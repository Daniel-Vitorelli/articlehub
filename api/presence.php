<?php
// ============================================
//  ArticleHub — Presence API (registro de presença)
//
//  PRESENÇA NÃO USA SESSÃO DE SERVIDOR. O usuário está online enquanto mantiver a
//  aba/janela aberta e passa a offline quando ela fecha OU quando o heartbeat para
//  de chegar. Quem decide é o navegador, não o PHP.
//
//  Escrita (qualquer usuário autenticado):
//    POST ?action=heartbeat  body {tab}            -> renova a aba, abre sessão se preciso
//    POST ?action=offline    body {tab, reason?}   -> fecha a aba (reason: closed|logout)
//  Leitura (ADMIN):
//    GET ?online=1                                 -> quem está online agora
//    GET                                           -> histórico de acessos
//        filtros: from / to / date (Y-m-d), user_id, reason (open|closed|timeout|logout)
//        página:  limit (1..500, default 100), offset
//
//  Modelo: presence_sessions = 1 linha por usuário (entrou/saiu/duração);
//          presence_tabs     = 1 linha por aba viva. A sessão só fecha quando
//          NOT EXISTS aba viva — é o que faz "fechar 1 de N abas" não marcar offline.
//
//  ABA EM SEGUNDO PLANO OU MINIMIZADA: comportamento CONFIGURÁVEL pelo admin via
//  app_settings.presence_track_minimized (padrão 1 = conta como online). Quando 0,
//  o front pausa o heartbeat em document.hidden e volta ao reaparecer. Por isso o
//  limbo precisa de margem: Chrome acelera timer de aba oculta para ~1/min.
//
//  PRESENÇA NÃO É AUDITORIA: é telemetria. Quando a tabela não existe (ou o usuário do
//  banco não tem permissão de DDL) a feature DEGRADA em silêncio — nunca 500, nunca
//  fatal. A regra fail-closed vale para periodic_reanalysis_log, não para presence_*.
//  O que o cliente recebe nesse caso é `unavailable: true` para parar de martelar.
// ============================================
require_once __DIR__ . '/config.php';

// Defaults e limites das três configurações (espelhados em api/settings.php e app.js).
// Segundos. Abaixo de 15s de intervalo o custo de rede não compensa; acima de 900s de
// timeout o "falso online" dura tempo demais.
const PRESENCE_INTERVAL_DEFAULT = 60;
const PRESENCE_INTERVAL_MIN     = 15;
const PRESENCE_INTERVAL_MAX     = 300;
const PRESENCE_TIMEOUT_DEFAULT  = 150;
const PRESENCE_TIMEOUT_MIN      = 60;
const PRESENCE_TIMEOUT_MAX      = 900;

// Graça anti-F5 (reabrir a sessão fechada no reload). O piso é FIXO porque o buraco do
// F5 depende do tempo de CARGA da página, não do intervalo; o teto acompanha o intervalo
// porque uma aba que só bate a cada 5min também demora a provar que voltou.
const PRESENCE_REOPEN_GRACE_MIN = 60;
const PRESENCE_REOPEN_GRACE_MAX = 300;

// Retenção do histórico (dias). 0 = NUNCA expurgar (0 é um valor válido, não "ausente").
// Configurável pelo admin em app_settings.presence_retention_days.
const PRESENCE_RETENTION_DEFAULT = 180;
const PRESENCE_RETENTION_MAX     = 3650;

// DDL e varredura eram executados em CADA batida de CADA aba. O gate vive na sessão PHP
// (por cookie, não por aba) e usa o relógio do MySQL: ~1 varredura/min e ~1 checagem de
// schema/10min por usuário. Atrasar a varredura não deixa ninguém online além da conta
// na tela porque as LEITURAS reaplicam o predicado do timeout e varrem sempre.
const PRESENCE_SWEEP_EVERY  = 60;  // segundos
const PRESENCE_SCHEMA_EVERY = 600; // segundos

// Página padrão do histórico. Precisa bater com PRESENCE_LOG_PAGE_SIZE em app.js.
const PRESENCE_HISTORY_PAGE = 100;
const PRESENCE_HISTORY_MAX  = 500;

$method = $_SERVER['REQUEST_METHOD'];
$action = getAction();

if ($method === 'POST') {
    if ($action === 'heartbeat') {
        presenceHeartbeat();
    }
    if ($action === 'offline') {
        presenceOffline();
    }
    jsonResponse(400, ['error' => 'Ação desconhecida.']);
}

if ($method === 'GET') {
    if (isset($_GET['online'])) {
        presenceOnlineNow();
    }
    presenceHistory();
}

jsonResponse(405, ['error' => 'Método não permitido.']);

// ============================================
//  Schema (não há migrations — ver convenção: ensureXTable + schema.sql + bloco manual)
// ============================================
// Regra de custo de schema adotada aqui:
//   tabela nova   -> CREATE TABLE IF NOT EXISTS (runtime) + schema.sql + bloco manual
//   índice novo   -> CREATE (novas instalações) + gate SHOW INDEX + ALTER (existentes)
//   coluna nova   -> CREATE + gate SHOW COLUMNS + ALTER + todo uso tolerando ausência
//   mudar tipo/nome de coluna existente -> NÃO FAZER (reescreveria o significado das
//                    linhas já gravadas; o risco supera o ganho)
function ensurePresenceTables(): void
{
    // Silencioso de propósito (mesmo padrão de ensureReanalysisLogTable): se o usuário do
    // banco não puder criar tabela, a feature degrada em vez de derrubar o app inteiro.
    try {
        $db = getDB();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS presence_sessions (' .
            '  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,' .
            '  user_id INT UNSIGNED NOT NULL COMMENT \'Dono da presenca (users.id), sempre da sessao\',' .
            '  entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Ficou online (1a aba). NUNCA gerar no PHP\',' .
            '  exited_at DATETIME DEFAULT NULL COMMENT \'NULL = ainda online (unico criterio de aberto)\',' .
            '  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Ultimo heartbeat de QUALQUER aba viva\',' .
            '  exit_reason VARCHAR(20) DEFAULT NULL COMMENT \'closed | timeout | logout\',' .
            '  INDEX idx_ps_user_entered (user_id, entered_at),' .
            '  INDEX idx_ps_user_exited (user_id, exited_at),' .
            '  INDEX idx_ps_open (exited_at, last_seen_at),' .
            '  INDEX idx_ps_entered (entered_at)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS presence_tabs (' .
            '  tab_token VARCHAR(64) NOT NULL PRIMARY KEY COMMENT \'Token por aba (sessionStorage); 1 linha = 1 aba viva\',' .
            '  session_id INT UNSIGNED NOT NULL COMMENT \'presence_sessions.id\',' .
            '  user_id INT UNSIGNED NOT NULL COMMENT \'Desnormalizado: permite DELETE no cleanup de usuario sem JOIN\',' .
            '  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            '  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            '  INDEX idx_pt_session (session_id),' .
            '  INDEX idx_pt_user (user_id),' .
            '  INDEX idx_pt_seen (last_seen_at)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Exception $e) {
        // silencioso: os gates `SHOW TABLES LIKE` decidem se lê/escreve
    }
}

// presence_sessions pode já existir sem este índice (criada antes dele existir). MySQL
// não tem CREATE INDEX IF NOT EXISTS, então o gate é SHOW INDEX. Idempotente e roda
// dentro do gate de PRESENCE_SCHEMA_EVERY, ou seja, ~1x a cada 10min por usuário.
// Serve à graça anti-F5 e ao histórico por usuário, que antes só tinham
// idx_ps_user_entered (user_id, entered_at).
function ensurePresenceIndexes(): void
{
    try {
        $db  = getDB();
        $has = (bool)$db->query(
            "SHOW INDEX FROM presence_sessions WHERE Key_name = 'idx_ps_user_exited'"
        )->fetch();
        if (!$has) {
            $db->exec('ALTER TABLE presence_sessions ADD INDEX idx_ps_user_exited (user_id, exited_at)');
        }
    } catch (Exception $e) {
        // sem o índice as consultas só ficam um pouco piores: não derruba nada
    }
}

function presenceTableExists(PDO $db): bool
{
    try {
        return (bool)$db->query("SHOW TABLES LIKE 'presence_sessions'")->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
//  Configuração (app_settings) e relógio do servidor
// ============================================
// Lê as três configurações já clampeadas + o relógio do MySQL numa consulta só. Não usa a
// sessão nem o cliente: o front nunca manda intervalo, o servidor é quem dita o ritmo.
function presenceSettings(PDO $db): array
{
    $interval  = PRESENCE_INTERVAL_DEFAULT;
    $timeout   = PRESENCE_TIMEOUT_DEFAULT;
    $retention = PRESENCE_RETENTION_DEFAULT;
    $tzOffset  = null; // MINUTOS entre UTC e o relógio do MySQL; null = desconhecido
    $nowTs     = 0;    // UNIX_TIMESTAMP() do MySQL — relógio do SERVIDOR, nunca o do PHP

    try {
        // Uma consulta: as três chaves + o relógio. O offset vem junto porque o front
        // precisa formatar DATETIME ("2026-10-01 12:00:00") sem depender do fuso do
        // navegador. Nada aqui gera timestamp de EVENTO: é metadado, e quem responde é o
        // próprio MySQL (convenção: nunca gerar entered_at/created_at no PHP).
        $stmt = $db->prepare(
            'SELECT UNIX_TIMESTAMP() AS now_ts,
                    TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS tz_offset_minutes,
                    (SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1) AS interval_s,
                    (SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1) AS timeout_s,
                    (SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1) AS retention_d'
        );
        $stmt->execute([
            'presence_heartbeat_interval',
            'presence_offline_timeout',
            'presence_retention_days',
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $nowTs = (int)$row['now_ts'];
            // !== null (e não `if ($row[...])`): um offset de 0 (servidor em UTC) é tão
            // válido quanto -180, e 0 também é um valor de retenção válido (= nunca
            // expurgar). Só null significa "não consegui saber".
            if ($row['tz_offset_minutes'] !== null) {
                $tzOffset = (int)$row['tz_offset_minutes'];
            }
            if (is_numeric($row['interval_s'])) {
                $interval = min(max((int)$row['interval_s'], PRESENCE_INTERVAL_MIN), PRESENCE_INTERVAL_MAX);
            }
            if (is_numeric($row['timeout_s'])) {
                $timeout = min(max((int)$row['timeout_s'], PRESENCE_TIMEOUT_MIN), PRESENCE_TIMEOUT_MAX);
            }
            if (is_numeric($row['retention_d'])) {
                $retention = min(max((int)$row['retention_d'], 0), PRESENCE_RETENTION_MAX);
            }
        }
    } catch (Exception $e) {
        // app_settings pode não existir: tenta pelo menos o relógio do MySQL, porque é
        // ele que cadencia o gate de manutenção.
        try {
            $row = $db->query(
                'SELECT UNIX_TIMESTAMP() AS now_ts,
                        TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS tz_offset_minutes'
            )->fetch();
            if ($row) {
                $nowTs = (int)$row['now_ts'];
                if ($row['tz_offset_minutes'] !== null) {
                    $tzOffset = (int)$row['tz_offset_minutes'];
                }
            }
        } catch (Exception $e2) {
            // sem relógio: $nowTs fica 0 e o gate de manutenção roda sempre
        }
    }

    // Margem obrigatória: Chrome acelera o timer de aba oculta para ~1/min, então um
    // timeout menor que o intervalo + folga marcaria offline quem só minimizou a janela.
    // settingRules() valida cada chave isoladamente, daí o max() aqui também.
    return [
        'interval'       => $interval,
        'timeout'        => $timeout,
        'limbo'          => max($timeout, $interval + 30),
        'grace'          => min(max(PRESENCE_REOPEN_GRACE_MIN, $interval), PRESENCE_REOPEN_GRACE_MAX),
        'retention_days' => $retention,
        'tz_offset_minutes' => $tzOffset,
        'now_ts'         => $nowTs,
    ];
}

// Gate de manutenção: DDL e varredura deixam de rodar em toda batida. $forceSweep existe
// para as leituras de admin (raras) não dependerem do gate.
function presenceMaintenance(PDO $db, array $cfg, bool $forceSweep = false): bool
{
    $now = (int)$cfg['now_ts']; // 0 se a consulta de settings falhou -> roda sempre

    $schemaAt = (int)($_SESSION['presence_schema_at'] ?? 0);
    if ($now <= 0 || $now - $schemaAt >= PRESENCE_SCHEMA_EVERY) {
        $_SESSION['presence_schema_at'] = $now;
        ensurePresenceTables();
        ensurePresenceIndexes();
    }

    $sweepAt = (int)($_SESSION['presence_sweep_at'] ?? 0);
    if ($forceSweep || $now <= 0 || $now - $sweepAt >= PRESENCE_SWEEP_EVERY) {
        $_SESSION['presence_sweep_at'] = $now;
        sweepPresence($db, $cfg);
    }

    return presenceTableExists($db);
}

// Não existe cron neste projeto. A varredura é LAZY: roda sob gate no heartbeat e sempre
// nas leituras de admin. O conjunto de sessões abertas é limitado pelo número de usuários,
// então o scan é barato.
function sweepPresence(PDO $db, array $cfg): void
{
    try {
        $limbo = (int)$cfg['limbo']; // sempre int (derivado de (int) + max()): sem risco
        $stale = "NOW() - INTERVAL $limbo SECOND";

        $db->exec("DELETE FROM presence_tabs WHERE last_seen_at < $stale");

        // exited_at = last_seen_at + $limbo, NÃO NOW(): NOW() cobraria todo o buraco do
        // timeout como presença e inflaria a duração de cada crash em $limbo segundos.
        // last_seen_at é a última prova de vida; +$limbo é a estimativa honesta.
        $db->exec(
            "UPDATE presence_sessions s
             SET s.exited_at = (s.last_seen_at + INTERVAL $limbo SECOND),
                 s.exit_reason = 'timeout'
             WHERE s.exited_at IS NULL
               AND s.last_seen_at < $stale
               AND NOT EXISTS (SELECT 1 FROM presence_tabs t WHERE t.session_id = s.id)"
        );

        // Abas que sobraram sem pai (o DELETE acima pega as velhas; este limpa as que
        // ficaram órfãs de uma sessão fechada). Mantém a tabela ~nº de abas vivas.
        $db->exec(
            'DELETE t FROM presence_tabs t
             INNER JOIN presence_sessions s ON s.id = t.session_id
             WHERE s.exited_at IS NOT NULL'
        );

        // Duas sessões abertas para o mesmo usuário só acontecem quando o GET_LOCK não é
        // concedido, mas aí o histórico ganha linhas duplicadas e ?online=1 devolve o
        // usuário 2x. Mantém a mais antiga (entered_at é o real) e fecha as extras com a
        // última prova de vida — auto-cura sem precisar de constraint.
        $dups = $db->query(
            'SELECT user_id, MIN(id) AS keep_id
             FROM presence_sessions
             WHERE exited_at IS NULL
             GROUP BY user_id
             HAVING COUNT(*) > 1'
        )->fetchAll();
        foreach ($dups as $d) {
            $db->prepare(
                "UPDATE presence_sessions
                 SET exited_at = (last_seen_at + INTERVAL $limbo SECOND), exit_reason = 'timeout'
                 WHERE user_id = ? AND exited_at IS NULL AND id <> ?"
            )->execute([$d['user_id'], $d['keep_id']]);
        }

        // Retenção: só sessões FECHADAS e em lote (LIMIT), para o expurgo nunca virar um
        // DELETE gigante no meio de uma batida. 0 desliga (é valor válido, não ausência).
        $retention = (int)$cfg['retention_days'];
        if ($retention > 0) {
            $db->exec(
                "DELETE FROM presence_sessions
                 WHERE exited_at IS NOT NULL
                   AND exited_at < (NOW() - INTERVAL $retention DAY)
                 ORDER BY exited_at
                 LIMIT 1000"
            );
        }
    } catch (Exception $e) {
        // varredura é manutenção: falhar não deve derrubar o heartbeat
    }
}

// ============================================
//  Escrita
// ============================================
function presenceHeartbeat(): void
{
    $user = requireAuth();
    $db   = getDB();

    $cfg = presenceSettings($db);
    if (!presenceMaintenance($db, $cfg)) {
        // Sem tabela e sem permissão de DDL: degradar em vez de um 500 por batida. O
        // `unavailable` faz o front espaçar as tentativas em vez de martelar.
        jsonResponse(200, [
            'ok'          => false,
            'unavailable' => true,
            'interval'    => PRESENCE_INTERVAL_MAX,
            'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
        ]);
    }

    try {
        $uid = (int)$user['id'];

        // Usuário desmarcou o rastreamento? Não processa nada e avisa o front para parar.
        $tracked = true;
        try {
            $trk = $db->prepare('SELECT track_presence FROM users WHERE id = ? LIMIT 1');
            $trk->execute([$uid]);
            $tracked = (bool)($trk->fetchColumn() ?? 1);
        } catch (Exception $e) {
            // coluna pode não existir: assume rastreado (padrão do banco)
        }
        if (!$tracked) {
            jsonResponse(200, [
                'ok'      => true,
                'tracked' => false,
                'interval'=> $cfg['interval'],
                'timeout' => $cfg['timeout'],
                'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
            ]);
        }

        $tab = presenceTabToken(getInput());

        $sessionId = presenceResolveSession($db, $uid, $tab, (int)$cfg['grace']);

        // Upsert numa ida só. O UPDATE explicito de last_seen_at (em vez de ON UPDATE
        // CURRENT_TIMESTAMP) deixa claro que é o heartbeat que renova, não qualquer
        // escrita. `user_id` entra no ON DUPLICATE porque o token mora no sessionStorage
        // da ABA: um logout seguido de login de OUTRO usuário na mesma aba reaproveita o
        // token, e sem isso a linha ficava com o user_id antigo — aí o DELETE do
        // ?action=offline (que filtra por user_id) não achava nada e a sessão do novo
        // usuário não fechava no logout.
        $stmt = $db->prepare(
            'INSERT INTO presence_tabs (tab_token, session_id, user_id) VALUES (?, ?, ?) ' .
            'ON DUPLICATE KEY UPDATE session_id = VALUES(session_id),
                                      user_id = VALUES(user_id),
                                      last_seen_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$tab, $sessionId, $uid]);

        $db->prepare('UPDATE presence_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$sessionId]);
    } catch (PDOException $e) {
        // Tabela sumiu no meio da sessão (restore/dump/ambiente novo): invalida o gate
        // para a próxima batida tentar recriar. 503 JSON, nunca fatal/HTML.
        unset($_SESSION['presence_schema_at']);
        jsonResponse(503, ['error' => 'Presença indisponível.']);
    }

    // Não devolvemos server_time de propósito: o cliente nunca envia timestamp e nunca
    // deve calcular duração com o próprio relógio (já houve skew de ~3h neste projeto).
    // O offset é diferente: é metadado de FORMATAÇÃO, não de medição.
    jsonResponse(200, [
        'ok'         => true,
        'session_id' => $sessionId,
        'interval'   => $cfg['interval'],
        'timeout'    => $cfg['timeout'],
        'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
    ]);
}

function presenceOffline(): void
{
    $user = requireAuth();
    $db   = getDB();

    if (!presenceTableExists($db)) {
        jsonResponse(200, ['ok' => false]);
    }

    $uid    = (int)$user['id'];
    $input  = getInput();
    $tab    = presenceTabToken($input);
    $reason = (string)($input['reason'] ?? 'closed');
    // Allowlist fixa: valor do cliente nunca entra no SQL nem no banco sem passar por aqui.
    if (!in_array($reason, ['closed', 'logout'], true)) {
        $reason = 'closed';
    }

    // Precisa do session_id ANTES de apagar a linha da aba.
    $stmt = $db->prepare('SELECT session_id FROM presence_tabs WHERE tab_token = ? AND user_id = ?');
    $stmt->execute([$tab, $uid]);
    $row = $stmt->fetch();
    // !== null (e não `if ($row)`): session_id é AUTO_INCREMENT, então 0 nunca é válido,
    // mas quem lê o código não tem como saber — o teste explícito é o que documenta.
    $sessionId = $row ? (int)$row['session_id'] : null;

    $db->prepare('DELETE FROM presence_tabs WHERE tab_token = ? AND user_id = ?')
        ->execute([$tab, $uid]);

    // O NOT EXISTS é toda a resposta para "fechar 1 de N abas não marca offline": só a
    // última aba viva satisfaz o predicado.
    if ($sessionId !== null) {
        $db->prepare(
            'UPDATE presence_sessions s
             SET s.exited_at = CURRENT_TIMESTAMP, s.exit_reason = ?
             WHERE s.id = ?
               AND NOT EXISTS (SELECT 1 FROM presence_tabs t WHERE t.session_id = s.id)'
        )->execute([$reason, $sessionId]);
    }

    jsonResponse(200, ['ok' => true]);
}

// Token da aba: obrigatório e cortado no tamanho da coluna. Nunca é confiável como
// identidade — serve só para distinguir abas do mesmo usuário (o dono é a sessão).
function presenceTabToken(array $input): string
{
    $tab = trim((string)($input['tab'] ?? ''));
    if ($tab === '') {
        jsonResponse(400, ['error' => 'Token da aba obrigatório.']);
    }
    return substr($tab, 0, 64);
}

// ============================================
//  Sessão: achar a aberta, reabrir ou criar
// ============================================
function presenceResolveSession(PDO $db, int $uid, string $tab, int $grace): int
{
    // Caminho comum (a aba já existe e a sessão dela continua aberta): uma consulta.
    $stmt = $db->prepare(
        'SELECT t.session_id
         FROM presence_tabs t
         INNER JOIN presence_sessions s ON s.id = t.session_id
         WHERE t.tab_token = ? AND t.user_id = ? AND s.exited_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$tab, $uid]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['session_id'];
    }

    return presenceOpenSession($db, $uid, $grace);
}

function presenceOpenSession(PDO $db, int $uid, int $grace): int
{
    // Anti-corrida: duas abas abrindo juntas criariam duas sessões abertas. Falta índice
    // único parcial em MySQL (NULLs são distintos), então o lock nomeado é o caminho.
    // Best-effort: sem lock a corrida é rara e a varredura acaba fechando a órfã.
    $lockName = 'presence_open_' . $uid; // $uid é (int): sem risco de injeção
    $locked   = false;
    try {
        $locked = (bool)$db->query("SELECT GET_LOCK('$lockName', 3)")->fetchColumn();
    } catch (Exception $e) {
        // segue sem lock
    }

    try {
        $open = $db->prepare(
            'SELECT id FROM presence_sessions
             WHERE user_id = ? AND exited_at IS NULL ORDER BY id ASC LIMIT 1'
        );

        // 1) Já existe sessão aberta (ex.: segunda aba, ou outro dispositivo)? Reusa.
        $open->execute([$uid]);
        $row = $open->fetch();
        if ($row) {
            return (int)$row['id'];
        }

        // 2) Graça anti-F5: F5 dispara pagehide -> beacon de saída -> a sessão fecha. Sem
        //    isso cada reload viraria um registro novo no histórico.
        //
        //    exit_reason <> 'logout' (com IS NULL liberado): SAIR é explícito. Reabrir a
        //    sessão de quem saiu apagaria o motivo e fundiria duas visitas distintas numa
        //    linha só — o histórico perderia justamente o dado mais interessante dele.
        //    $grace é int (derivado de (int) + min/max), então entra no SQL sem risco.
        $reopen = $db->prepare(
            'UPDATE presence_sessions
             SET exited_at = NULL, exit_reason = NULL, last_seen_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND exited_at IS NOT NULL
                   AND (exit_reason IS NULL OR exit_reason <> \'logout\')
                   AND exited_at >= (NOW() - INTERVAL ' . $grace . ' SECOND)
             ORDER BY exited_at DESC LIMIT 1'
        );
        $reopen->execute([$uid]);
        if ($reopen->rowCount() > 0) {
            $open->execute([$uid]);
            $row = $open->fetch();
            if ($row) {
                return (int)$row['id'];
            }
        }

        // 3) Nova sessão. entered_at/last_seen_at vêm do DEFAULT do banco — nunca gerar
        //    timestamp no PHP (convenção: já houve skew de ~3h por isso).
        $db->prepare('INSERT INTO presence_sessions (user_id) VALUES (?)')->execute([$uid]);
        return (int)$db->lastInsertId();
    } finally {
        if ($locked) {
            try {
                $db->query("SELECT RELEASE_LOCK('$lockName')");
            } catch (Exception $e) {
                // o lock expira com a conexão de qualquer forma
            }
        }
    }
}

// ============================================
//  Leitura (ADMIN)
// ============================================
// Só o formato; quem faz a aritmética do dia é o MySQL (DATE_ADD), então não há "amanhã"
// calculado no PHP nem dependência do relógio da linguagem.
function presenceDateParam(string $key): ?string
{
    $raw = trim((string)($_GET[$key] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $raw;
}

// Devolve o WHERE/params de data. Intervalo HALF-OPEN montado no MySQL: é sargável (usa
// idx_ps_entered), ao contrário de DATE(entered_at) = ?, que desativava o índice e ainda
// dependia de @@time_zone. O dia é o dia do SERVIDOR (é o fuso em que entered_at foi
// gravado) — o front recebe o offset e formata de acordo.
function presenceDateFilter(array &$where, array &$params): void
{
    $from = presenceDateParam('from');
    $to   = presenceDateParam('to');
    if ($from !== null) {
        $where[]  = 'ps.entered_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $where[]  = 'ps.entered_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $to . ' 00:00:00';
    }
    if ($from === null && $to === null) {
        // `date` (dia único) continua aceito para compatibilidade com quem já chama assim.
        $date = presenceDateParam('date');
        if ($date !== null) {
            $where[]  = 'ps.entered_at >= ?';
            $params[] = $date . ' 00:00:00';
            $where[]  = 'ps.entered_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
        }
    }
}

function presenceOnlineNow(): void
{
    requireRole('admin');
    $db = getDB();

    $cfg = presenceSettings($db);
    if (!presenceTableExists($db)) {
        ensurePresenceTables();
        if (!presenceTableExists($db)) {
            // Tabela ausente devolve vazio, não 500 numa tela de leitura (mesma postura
            // de api/reanalysis_log.php).
            jsonResponse(200, [
                'data' => [],
                'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
            ]);
        }
    }
    sweepPresence($db, $cfg); // leitura de admin é rara: varre sempre, sem gate

    // O predicado do timeout é reaplicado na leitura: a varredura é lazy, então entre
    // dois heartbeats uma linha ainda pode estar aberta sem merecer estar.
    // O GROUP BY existe porque sessões duplicadas (corrida sem GET_LOCK) fariam o mesmo
    // usuário aparecer 2x; lista todas as colunas não agregadas (ONLY_FULL_GROUP_BY).
    $limbo = (int)$cfg['limbo'];
    $stmt  = $db->query(
        "SELECT s.user_id, MIN(s.entered_at) AS entered_at, MAX(s.last_seen_at) AS last_seen_at,
                u.name, u.role
         FROM presence_sessions s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.exited_at IS NULL
           AND s.last_seen_at >= (NOW() - INTERVAL $limbo SECOND)
         GROUP BY s.user_id, u.name, u.role
         ORDER BY u.name"
    );
    jsonResponse(200, [
        'data' => $stmt->fetchAll(),
        'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
    ]);
}

function presenceHistory(): void
{
    requireRole('admin');
    $db = getDB();

    $cfg = presenceSettings($db);
    if (!presenceTableExists($db)) {
        ensurePresenceTables();
        if (!presenceTableExists($db)) {
            jsonResponse(200, [
                'data' => [],
                'total' => 0,
                'totals' => null,
                'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
            ]);
        }
    }
    sweepPresence($db, $cfg); // leitura de admin é rara: varre sempre, sem gate

    $where  = [];
    $params = [];

    // Filtro de data sobre entered_at (quando a pessoa FICOU online), sem pré-preenchimento:
    // abrir filtrado por "hoje" mostraria vazio em boa parte dos dias.
    presenceDateFilter($where, $params);

    // ctype_digit (e não `if ($userId)`): "abc" viraria 0 e filtraria um usuário
    // inexistente, devolvendo lista vazia sem motivo aparente.
    $rawUser = trim((string)($_GET['user_id'] ?? ''));
    if ($rawUser !== '' && ctype_digit($rawUser)) {
        $where[]  = 'ps.user_id = ?';
        $params[] = (int)$rawUser;
    }

    // Motivo da saída: allow-list, nada do cliente entra no SQL sem passar por aqui.
    // 'open' não é um exit_reason — é o estado (exited_at IS NULL).
    $reason = trim((string)($_GET['reason'] ?? ''));
    if ($reason === 'open') {
        $where[] = 'ps.exited_at IS NULL';
    } elseif (in_array($reason, ['closed', 'timeout', 'logout'], true)) {
        $where[]  = 'ps.exit_reason = ?';
        $params[] = $reason;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : PRESENCE_HISTORY_PAGE;
    $limit  = min(max($limit, 1), PRESENCE_HISTORY_MAX);
    $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;

    // Contagem e totais só na primeira página (troca de filtro), não em cada scroll —
    // mesmo padrão de api/periodic_analysis.php.
    $total  = null;
    $totals = null;
    if ($offset === 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM presence_sessions ps $whereSql");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS sessions,
                    COUNT(DISTINCT ps.user_id) AS users,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, ps.entered_at, COALESCE(ps.exited_at, NOW()))), 0) AS seconds,
                    COALESCE(SUM(ps.exited_at IS NULL), 0) AS open_now
             FROM presence_sessions ps $whereSql"
        );
        $stmt->execute($params);
        $totals = $stmt->fetch();
    }

    // Duração NÃO é coluna: gerada não pode chamar NOW() (não determinística) e calculada
    // estaria sempre errada na linha aberta. Computa na leitura, com COALESCE para a
    // sessão em andamento. LEFT JOIN (não INNER): usuário excluído não desaparece do
    // histórico — é justamente o caso que a auditoria precisa ver.
    // duration_estimated é DERIVADO de exit_reason (sem coluna nova): a duração de quem
    // caiu por timeout é um limite superior (last_seen_at + limbo), não um valor exato.
    $stmt = $db->prepare(
        "SELECT ps.id, ps.user_id, ps.entered_at, ps.exited_at, ps.exit_reason, ps.last_seen_at,
                TIMESTAMPDIFF(SECOND, ps.entered_at, COALESCE(ps.exited_at, NOW())) AS duration_seconds,
                (ps.exited_at IS NULL) AS still_open,
                (ps.exit_reason = 'timeout') AS duration_estimated,
                u.name AS user_name, u.role AS user_role
         FROM presence_sessions ps
         LEFT JOIN users u ON u.id = ps.user_id
         $whereSql
         ORDER BY ps.entered_at DESC, ps.id DESC
         LIMIT ? OFFSET ?"
    );
    // LIMIT/OFFSET como PARAM_INT: com EMULATE_PREPARES=false o bind via execute($params)
    // vai como string e o placeholder nativo de LIMIT exige int.
    $bindIdx = 1;
    foreach ($params as $p) {
        $stmt->bindValue($bindIdx++, $p);
    }
    $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
    $stmt->execute();

    jsonResponse(200, [
        'data' => $stmt->fetchAll(),
        'total' => $total,
        'totals' => $totals,
        'server_utc_offset_minutes' => $cfg['tz_offset_minutes'],
    ]);
}
