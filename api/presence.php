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
//    GET ?date=YYYY-MM-DD&user_id=N                -> histórico de acessos
//
//  Modelo: presence_sessions = 1 linha por usuário (entrou/saiu/duração);
//          presence_tabs     = 1 linha por aba viva. A sessão só fecha quando
//          NOT EXISTS aba viva — é o que faz "fechar 1 de N abas" não marcar offline.
//
//  Decisão explícita: ABA EM SEGUNDO PLANO OU MINIMIZADA CONTA COMO ONLINE. O heartbeat
//  não pausa com document.hidden (e o front não checa isso). Só fecha a aba ou estoura
//  o timeout. Por isso o limbo precisa de margem: Chrome acelera timer de aba oculta
//  para ~1/min (ver presenceSettings()).
// ============================================
require_once __DIR__ . '/config.php';

// Defaults e limites das duas configurações (espelhados em api/settings.php e app.js).
// Segundos. Abaixo de 15s de intervalo o custo de rede não compensa; acima de 900s de
// timeout o "falso online" dura tempo demais.
const PRESENCE_INTERVAL_DEFAULT = 60;
const PRESENCE_INTERVAL_MIN     = 15;
const PRESENCE_INTERVAL_MAX     = 300;
const PRESENCE_TIMEOUT_DEFAULT  = 150;
const PRESENCE_TIMEOUT_MIN      = 60;
const PRESENCE_TIMEOUT_MAX      = 900;

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

function presenceTableExists(PDO $db): bool
{
    try {
        return (bool)$db->query("SHOW TABLES LIKE 'presence_sessions'")->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
//  Configuração (app_settings) e varredura de timeout
// ============================================
// Lê as duas configurações já clampeadas. Não usa a sessão nem o cliente: o front nunca
// manda intervalo, o servidor é quem dita o ritmo.
function presenceSettings(PDO $db): array
{
    $interval = PRESENCE_INTERVAL_DEFAULT;
    $timeout  = PRESENCE_TIMEOUT_DEFAULT;

    try {
        $stmt = $db->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['presence_heartbeat_interval']);
        $raw = $stmt->fetchColumn();
        // is_numeric (e não `if ($raw)`): "0" é um valor que existe, só é inválido aqui.
        if (is_numeric($raw)) {
            $interval = min(max((int)$raw, PRESENCE_INTERVAL_MIN), PRESENCE_INTERVAL_MAX);
        }
        $stmt->execute(['presence_offline_timeout']);
        $raw = $stmt->fetchColumn();
        if (is_numeric($raw)) {
            $timeout = min(max((int)$raw, PRESENCE_TIMEOUT_MIN), PRESENCE_TIMEOUT_MAX);
        }
    } catch (Exception $e) {
        // app_settings pode não existir: cai nos defaults
    }

    // Margem obrigatória: Chrome acelera o timer de aba oculta para ~1/min, então um
    // timeout menor que o intervalo + folga marcaria offline quem só minimizou a janela.
    // settingRules() valida cada chave isoladamente, daí o GREATEST aqui também.
    return [
        'interval' => $interval,
        'timeout'  => $timeout,
        'limbo'    => max($timeout, $interval + 30),
    ];
}

// Não existe cron neste projeto. A varredura é LAZY: roda no heartbeat, no ?online=1 e no
// histórico. O conjunto de sessões abertas é limitado pelo número de usuários, então o
// scan é gratuito.
function sweepPresence(PDO $db, int $limbo): void
{
    try {
        // $limbo é sempre int (derivado de (int) + max()), então entra no SQL sem risco.
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
    ensurePresenceTables();

    $cfg = presenceSettings($db);
    sweepPresence($db, $cfg['limbo']);

    $uid = (int)$user['id'];
    $tab = presenceTabToken(getInput());

    $sessionId = presenceResolveSession($db, $uid, $tab);

    // Upsert numa ida só. O UPDATE explicito de last_seen_at (em vez de ON UPDATE
    // CURRENT_TIMESTAMP) deixa claro que é o heartbeat que renova, não qualquer escrita.
    $stmt = $db->prepare(
        'INSERT INTO presence_tabs (tab_token, session_id, user_id) VALUES (?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), last_seen_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$tab, $sessionId, $uid]);

    $db->prepare('UPDATE presence_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$sessionId]);

    // Não devolvemos server_time de propósito: o cliente nunca envia timestamp e nunca
    // deve calcular duração com o próprio relógio (já houve skew de ~3h neste projeto).
    jsonResponse(200, [
        'ok'         => true,
        'session_id' => $sessionId,
        'interval'   => $cfg['interval'],
        'timeout'    => $cfg['timeout'],
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
    $sessionId = $row ? (int)$row['session_id'] : null;

    $db->prepare('DELETE FROM presence_tabs WHERE tab_token = ? AND user_id = ?')
        ->execute([$tab, $uid]);

    // O NOT EXISTS é toda a resposta para "fechar 1 de N abas não marca offline": só a
    // última aba viva satisfaz o predicado.
    if ($sessionId) {
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
function presenceResolveSession(PDO $db, int $uid, string $tab): int
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

    return presenceOpenSession($db, $uid);
}

function presenceOpenSession(PDO $db, int $uid): int
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
        $reopen = $db->prepare(
            'UPDATE presence_sessions
             SET exited_at = NULL, exit_reason = NULL, last_seen_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND exited_at IS NOT NULL
                   AND exited_at >= (NOW() - INTERVAL 60 SECOND)
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
function presenceOnlineNow(): void
{
    requireRole('admin');
    $db = getDB();
    ensurePresenceTables();

    $cfg = presenceSettings($db);
    sweepPresence($db, $cfg['limbo']);

    // O predicado do timeout é reaplicado na leitura: a varredura é lazy, então entre
    // dois heartbeats uma linha ainda pode estar aberta sem merecer estar.
    $limbo = $cfg['limbo'];
    $stmt  = $db->query(
        "SELECT s.user_id, s.entered_at, s.last_seen_at, u.name, u.role
         FROM presence_sessions s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.exited_at IS NULL
           AND s.last_seen_at >= (NOW() - INTERVAL $limbo SECOND)"
    );
    jsonResponse(200, $stmt->fetchAll());
}

function presenceHistory(): void
{
    requireRole('admin');
    $db = getDB();
    ensurePresenceTables();

    $cfg = presenceSettings($db);
    sweepPresence($db, $cfg['limbo']);

    $where  = [];
    $params = [];

    // Filtro de data sobre entered_at (quando a pessoa FICOU online), sem pré-preenchimento:
    // abrir filtrado por "hoje" mostraria vazio em boa parte dos dias.
    $date = trim($_GET['date'] ?? '');
    if ($date !== '') {
        $where[] = 'DATE(ps.entered_at) = ?';
        $params[] = $date;
    }

    $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    if ($userId) {
        $where[] = 'ps.user_id = ?';
        $params[] = $userId;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Duração NÃO é coluna: gerada não pode chamar NOW() (não determinística) e calculada
    // estaria sempre errada na linha aberta. Computa na leitura, com COALESCE para a
    // sessão em andamento. LEFT JOIN (não INNER): usuário excluído não desaparece do
    // histórico — é justamente o caso que a auditoria precisa ver.
    $stmt = $db->prepare(
        "SELECT ps.id, ps.user_id, ps.entered_at, ps.exited_at, ps.exit_reason, ps.last_seen_at,
                TIMESTAMPDIFF(SECOND, ps.entered_at, COALESCE(ps.exited_at, NOW())) AS duration_seconds,
                (ps.exited_at IS NULL) AS still_open,
                u.name AS user_name, u.role AS user_role
         FROM presence_sessions ps
         LEFT JOIN users u ON u.id = ps.user_id
         $whereSql
         ORDER BY ps.entered_at DESC, ps.id DESC
         LIMIT 500"
    );
    $stmt->execute($params);

    jsonResponse(200, $stmt->fetchAll());
}
