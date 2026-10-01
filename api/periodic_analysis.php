<?php
// ============================================
//  ArticleHub — Periodic Analysis API
//  LEITURA: todos os perfis autenticados.
//  ESCRITA (reanálise): ver requireReanalyzePermission() logo abaixo — liberada para todos
//  por decisão explícita, mas sujeita à COTA por usuário (reanalysisQuota).
// ============================================
require_once __DIR__ . '/config.php';

// A view de análise periódica é acessível a todos os perfis (não é mais admin-only).
// O usuário é guardado porque a rota GET ?quota=1 precisa dele.
$user = requireAuth();

/**
 * Quem pode disparar reanálise (POST action=reanalyze|reanalyze_bulk) e se ainda cabe na
 * cota dele.
 *
 * PERMISSÃO: qualquer usuário autenticado — decisão explícita do time. Este é o ÚNICO ponto
 * a mudar para fechar a escrita por papel (ex.: trocar o corpo por `return requireRole('admin');`).
 *
 * COTA: o controle por usuário JÁ vive aqui — assertReanalysisQuota() recusa com 429 antes de
 * qualquer INSERT. A cota é configurada pelo admin por usuário (users.reanalysis_limit +
 * users.reanalysis_window_hours).
 *
 * @param int $requested quantas linhas este pedido vai criar (a cota consome uma por post).
 */
function requireReanalyzePermission(int $requested = 1): array
{
    $user = requireAuth();
    assertReanalysisQuota(getDB(), $user, $requested);
    return $user;
}

/**
 * As colunas da cota existem neste banco? Não há migrations no projeto, então cada uso confere
 * antes. Sem elas a reanálise segue funcionando — só não há cota a aplicar.
 */
function hasReanalysisQuotaColumns(PDO $db): bool
{
    return (bool)$db->query("SHOW COLUMNS FROM users LIKE 'reanalysis_limit'")->fetch()
        && (bool)$db->query("SHOW COLUMNS FROM users LIKE 'reanalysis_window_hours'")->fetch();
}

/**
 * Cota de reanálise do usuário: quanto ele já pediu na janela e quanto resta.
 *
 * Devolve ['limit' => ?int, 'hours' => ?int, 'used' => int, 'remaining' => ?int].
 * `limit` e `remaining` NULL = sem limite configurado.
 *
 * A contagem sai de periodic_reanalysis_log (uma linha por post reanalisado, gravada no POST)
 * dentro de uma JANELA MÓVEL — `NOW() - INTERVAL N HOUR`. Janela de CALENDÁRIO zeraria à
 * meia-noite: daria para pedir a cota inteira às 23h59 e de novo às 00h01.
 *
 * A comparação de tempo acontece inteira no banco (NOW() e created_at no mesmo relógio), então
 * não sofre o skew de fuso que motivou a regra de nunca gerar created_at no PHP.
 */
function reanalysisQuota(PDO $db, array $user): array
{
    $semLimite = ['limit' => null, 'hours' => null, 'used' => 0, 'remaining' => null];

    // Sem as colunas não há cota configurada; sem a tabela de log não há o que contar.
    if (!hasReanalysisQuotaColumns($db)) return $semLimite;
    if (!$db->query("SHOW TABLES LIKE 'periodic_reanalysis_log'")->fetch()) return $semLimite;

    $stmt = $db->prepare('SELECT reanalysis_limit, reanalysis_window_hours FROM users WHERE id = ?');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();

    $limit = ($row && $row['reanalysis_limit'] !== null) ? (int)$row['reanalysis_limit'] : null;
    if ($limit === null || $limit <= 0) return $semLimite;

    // Limite informado sem janela válida: assume 24h, que é o caso comum ("N por dia").
    $hours = ($row['reanalysis_window_hours'] !== null && (int)$row['reanalysis_window_hours'] > 0)
        ? (int)$row['reanalysis_window_hours']
        : 24;

    // $hours é int validado (> 0) e vai INTERPOLADO de propósito: `INTERVAL ? HOUR` depende de
    // como o driver lida com placeholder dentro de INTERVAL, e um erro aí derrubaria toda
    // reanálise. Sendo int, não há o que injetar. O user_id segue parametrizado.
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM periodic_reanalysis_log
         WHERE user_id = ? AND created_at >= (NOW() - INTERVAL $hours HOUR)"
    );
    $stmt->execute([(int)$user['id']]);
    $used = (int)$stmt->fetchColumn();

    return [
        'limit' => $limit,
        'hours' => $hours,
        'used' => $used,
        'remaining' => max(0, $limit - $used),
    ];
}

/**
 * Recusa com 429 quando o pedido estoura a cota. Roda ANTES de qualquer INSERT, então um
 * pedido barrado não deixa análise criada nem linha no log.
 */
function assertReanalysisQuota(PDO $db, array $user, int $requested): void
{
    if ($requested <= 0) return;

    $q = reanalysisQuota($db, $user);
    if ($q['remaining'] === null) return; // sem limite configurado

    if ($requested > $q['remaining']) {
        $msg = $q['remaining'] > 0
            ? "Limite de reanálises atingido: restam {$q['remaining']} de {$q['limit']} a cada {$q['hours']}h."
            : "Limite de reanálises atingido: você já usou as {$q['limit']} permitidas a cada {$q['hours']}h.";
        jsonResponse(429, ['error' => $msg, 'quota' => $q]);
    }
}

/**
 * Garante a tabela do log de reanálises em bancos criados antes desta feature
 * (schema.sql só roda no primeiro init do container). Espelha ensureSettingsTable()
 * de api/settings.php.
 *
 * Best-effort DE PROPÓSITO: se a criação falhar, o gate $hasLogTable desliga o log em
 * vez de derrubar a reanálise — perder registro é ruim, quebrar uma feature central é
 * pior. A falha aparece como "reanálises sem linha no log", que é detectável.
 */
function ensureReanalysisLogTable(): void
{
    try {
        getDB()->exec(
            'CREATE TABLE IF NOT EXISTS periodic_reanalysis_log (' .
            '  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,' .
            '  user_id INT UNSIGNED NOT NULL,' .
            '  dominio VARCHAR(50) NOT NULL,' .
            '  id_post INT DEFAULT NULL,' .
            '  analysis_id INT UNSIGNED DEFAULT NULL,' .
            '  action VARCHAR(50) NOT NULL,' .
            '  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            '  INDEX idx_prl_group (dominio, id_post, created_at),' .
            '  INDEX idx_prl_user_created (user_id, created_at),' .
            '  INDEX idx_prl_created (created_at),' .
            '  INDEX idx_prl_analysis (analysis_id)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Exception $e) {
        // silencioso: o gate de existência abaixo decide se grava
    }
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Distinct para popular filtros sem carregar tudo
    if (isset($_GET['distinct'])) {
        $distinct = trim($_GET['distinct']);
        if ($distinct === 'dominio') {
            $stmt = $db->query('SELECT DISTINCT dominio FROM periodic_analysis WHERE dominio IS NOT NULL AND dominio != "" ORDER BY dominio');
            jsonResponse(200, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($distinct === 'post_type') {
            $stmt = $db->query('SELECT DISTINCT post_type FROM periodic_analysis WHERE post_type IS NOT NULL AND post_type != "" ORDER BY post_type');
            jsonResponse(200, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($distinct === 'solicitante') {
            // Diferente dos dois acima: o filtro de solicitante age sobre a ÚLTIMA análise de
            // cada grupo, então as opções precisam sair do mesmo conjunto. Oferecer alguém que
            // só pediu uma análise já superada daria sempre resultado vazio.
            // 0 (análise automática do n8n) entra como um solicitante a mais; NULL fica de fora
            // de propósito — não existe opção "sem solicitante".
            $stmt = $db->query(
                'SELECT DISTINCT pa.solicitante_id, sol.name AS solicitante_nome
                 FROM periodic_analysis pa
                 INNER JOIN (SELECT MAX(id) AS max_id, dominio, id_post
                             FROM periodic_analysis GROUP BY dominio, id_post) AS latest
                     ON pa.dominio = latest.dominio AND pa.id_post <=> latest.id_post AND pa.id = latest.max_id
                 LEFT JOIN users sol ON sol.id = pa.solicitante_id
                 WHERE pa.solicitante_id IS NOT NULL
                 ORDER BY (pa.solicitante_id = 0) DESC, sol.name ASC'
            );
            jsonResponse(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    // Cota de reanálise do usuário LOGADO (?quota=1). Fica fora do bloco `distinct` de
    // propósito: não é uma lista de opções de filtro, é o estado da cota dele — e quem lê
    // é o próprio usuário, não um admin.
    if (isset($_GET['quota'])) {
        jsonResponse(200, reanalysisQuota($db, $user));
    }

    // Lazy pagination: ?limit=50&offset=0&status=aprovado&post_type=post&dominio=xxx&id_post=123&comments=with&solicitante=auto
    // comments aceita 'with' (só grupos com comentário) ou 'without' (só sem).
    // solicitante aceita 'auto' (análise automática do n8n) ou o id de um usuário.
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $status = trim($_GET['status'] ?? '');
    $postType = trim($_GET['post_type'] ?? '');
    $dominio = trim($_GET['dominio'] ?? '');
    // Busca por ID do post (parcial). Só aceita dígitos: id_post é INT no banco.
    $idPost = trim($_GET['id_post'] ?? '');
    $withHistory = isset($_GET['with_history']); // inclui histórico leve (id, created_at, status_compliance) por grupo

    // Se tem paginação, retorna agrupado (latest por dominio+id_post) + paginado
    if ($limit > 0) {
        $where = [];
        $params = [];
        if ($status !== '') { $where[] = 'pa.status_compliance = ?'; $params[] = $status; }
        if ($postType !== '') { $where[] = 'pa.post_type = ?'; $params[] = $postType; }
        if ($dominio !== '') { $where[] = 'pa.dominio = ?'; $params[] = $dominio; }
        // Busca por ID do post: PARCIAL, casa em qualquer posição (ex.: "48" acha 4821 e 1487).
        // CAST é necessário porque id_post é INT.
        // ctype_digit garante que o termo só tem dígitos — logo não existe % nem _ para
        // escapar no LIKE (o wildcard não pode ser injetado pelo usuário).
        // id_post NULL: CAST(NULL AS CHAR) é NULL, o LIKE resulta NULL e a linha fica fora
        // do resultado — que é o correto, um ID inexistente não deve casar com dígitos.
        if ($idPost !== '' && ctype_digit($idPost)) {
            $where[] = 'CAST(pa.id_post AS CHAR) LIKE ?';
            $params[] = '%' . $idPost . '%';
        }
        // Filtro de comentários. Só entra na query quando é pedido — assim o caminho
        // padrão (sem filtro) continua sem depender da tabela periodic_analysis_comments.
        // O valor é comparado com uma lista fixa, então nenhum trecho do SQL vem do
        // usuário (por isso não há placeholder aqui).
        // <=> é null-safe: id_post pode ser NULL em periodic_analysis.
        $comments = trim($_GET['comments'] ?? '');
        if ($comments === 'with' || $comments === 'without') {
            $exists = 'EXISTS (SELECT 1 FROM periodic_analysis_comments pc
                               WHERE pc.dominio = pa.dominio AND pc.id_post <=> pa.id_post)';
            $where[] = $comments === 'with' ? $exists : 'NOT ' . $exists;
        }
        // Filtro por quem pediu a ÚLTIMA análise do grupo. O INNER JOIN com o "latest" (acima)
        // já restringe `pa` à linha mais recente de cada grupo, então filtrar por
        // pa.solicitante_id é exatamente "grupos cuja última análise foi pedida por X".
        // 'auto' é a análise automática do n8n (solicitante_id = 0); qualquer outro valor é um
        // id de usuário. Allowlist + ctype_digit: nada do usuário entra no SQL.
        // (solicitante=0 funciona igual a 'auto' — o cast de ctype_digit dá 0.)
        $solicitante = trim($_GET['solicitante'] ?? '');
        if ($solicitante === 'auto') {
            $where[] = 'pa.solicitante_id = 0';
        } elseif ($solicitante !== '' && ctype_digit($solicitante)) {
            $where[] = 'pa.solicitante_id = ?';
            $params[] = (int)$solicitante;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Subquery para latest por grupo (usa índice idx_periodic_group_latest)
        $latestSub = '(SELECT MAX(id) AS max_id, dominio, id_post FROM periodic_analysis GROUP BY dominio, id_post) AS latest';

        // Total agrupado: só calcula quando offset=0 (troca de filtro), não em cada scroll.
        $total = null;
        if ($offset === 0) {
            // <=> (null-safe) para não excluir grupos com id_post NULL (NULL=NULL é falso com =).
            $countSql = "SELECT COUNT(*) FROM periodic_analysis pa
                         INNER JOIN $latestSub ON pa.dominio = latest.dominio AND pa.id_post <=> latest.id_post AND pa.id = latest.max_id
                         $whereSql";
            $stmt = $db->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
        }

        // Dados paginados (usa índice idx_periodic_group_ordered para ORDER BY)
        // sol: nome de quem pediu a reanálise, vindo de users (LEFT JOIN porque
        // solicitante_id é NULL nas linhas antigas e 0 nas automáticas do n8n — nenhum
        // dos dois deve excluir a linha).
        $dataSql = "SELECT pa.*, d.url AS dominio_url, sol.name AS solicitante_nome
                    FROM periodic_analysis pa
                    INNER JOIN $latestSub ON pa.dominio = latest.dominio AND pa.id_post <=> latest.id_post AND pa.id = latest.max_id
                    LEFT JOIN domains d ON d.blog_name = pa.dominio
                    LEFT JOIN users sol ON sol.id = pa.solicitante_id
                    $whereSql
                    ORDER BY pa.created_at DESC, pa.id DESC
                    LIMIT ? OFFSET ?";
        $stmt = $db->prepare($dataSql);
        // LIMIT/OFFSET como PARAM_INT: com EMULATE_PREPARES=false o bind via
        // execute($params) vai como string e o placeholder nativo de LIMIT exige int.
        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // COMENTADO: with_history removido - usa history_batch no frontend (mais eficiente)
        /*
        if ($withHistory && $rows) {
            // Busca histórico leve de todos os grupos da página numa query só
            // Constrói WHERE com OR para cada par (dominio, id_post) - compatível MySQL 5.7+
            $whereParts = [];
            $args = [];
            foreach ($rows as $r) {
                $whereParts[] = '(dominio = ? AND id_post = ?)';
                $args[] = $r->dominio;
                $args[] = $r->id_post;
            }
            $whereSql = implode(' OR ', $whereParts);
            $stmtH = $db->prepare("SELECT dominio, id_post, id, created_at, status_compliance
                                   FROM periodic_analysis
                                   WHERE $whereSql
                                   ORDER BY dominio, id_post, created_at DESC, id DESC");
            $stmtH->execute($args);
            $histRows = $stmtH->fetchAll(PDO::FETCH_ASSOC);
            $byKey = [];
            foreach ($histRows as $h) {
                $k = $h['dominio'] . '::' . $h['id_post'];
                if (!isset($byKey[$k])) $byKey[$k] = [];
                if (count($byKey[$k]) < 10) $byKey[$k][] = $h;
            }
            foreach ($rows as $r) {
                $k = $r->dominio . '::' . $r->id_post;
                $r->history = $byKey[$k] ?? [];
            }
        }
        */

        jsonResponse(200, ['data' => $rows, 'total' => $total]);
    }

    // Histórico em lote para múltiplos grupos - ?history_batch=1&groups=[{"dominio":"x","id_post":1},...]
    if (isset($_GET['history_batch']) && isset($_GET['groups'])) {
        $groups = json_decode($_GET['groups'], true);
        if (is_array($groups) && count($groups) > 0) {
            $whereParts = [];
            $args = [];
            foreach ($groups as $g) {
                // <=> null-safe: '' vira NULL para casar com a coluna INT NULL.
                $idPostBatch = $g['id_post'] ?? null;
                if ($idPostBatch === '') $idPostBatch = null;
                $whereParts[] = '(dominio = ? AND id_post <=> ?)';
                $args[] = $g['dominio'] ?? '';
                $args[] = $idPostBatch;
            }
            $whereSql = implode(' OR ', $whereParts);
            
            // MySQL 8+: ROW_NUMBER() para pegar top 10 por grupo direto no SQL (evita processamento PHP)
            $stmt = $db->prepare("
                SELECT dominio, id_post, id, created_at, status_compliance, resumo_analise, solicitante_id, solicitante_nome
                FROM (
                    SELECT 
                        pa.dominio, pa.id_post, pa.id, pa.created_at, pa.status_compliance, pa.resumo_analise,
                        pa.solicitante_id, sol.name AS solicitante_nome,
                        ROW_NUMBER() OVER (PARTITION BY pa.dominio, pa.id_post ORDER BY pa.created_at DESC, pa.id DESC) as rn
                    FROM periodic_analysis pa
                    LEFT JOIN users sol ON sol.id = pa.solicitante_id
                    WHERE $whereSql
                ) t
                WHERE t.rn <= 10
                ORDER BY t.dominio, t.id_post, t.created_at DESC, t.id DESC
            ");
            $stmt->execute($args);
            $histRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $byKey = [];
            foreach ($histRows as $h) {
                $k = $h['dominio'] . '::' . $h['id_post'];
                if (!isset($byKey[$k])) $byKey[$k] = [];
                $byKey[$k][] = $h;
            }
            jsonResponse(200, $byKey);
        } else {
            jsonResponse(200, new stdClass());
        }
    }

    // Histórico de um grupo específico (lazy para modal) - ?history=1&dominio=xxx&id_post=123
    if (isset($_GET['history']) && isset($_GET['dominio']) && isset($_GET['id_post'])) {
        $dominioH = trim($_GET['dominio']);
        $idPostH = trim($_GET['id_post']);
        if ($idPostH === '') $idPostH = null; // coluna INT NULL: '' coagia para 0 no MySQL
        $stmt = $db->prepare(
            'SELECT pa.id, pa.created_at, pa.status_compliance, pa.resumo_analise,
                    pa.solicitante_id, sol.name AS solicitante_nome
             FROM periodic_analysis pa
             LEFT JOIN users sol ON sol.id = pa.solicitante_id
             WHERE pa.dominio = ? AND pa.id_post <=> ?
             ORDER BY pa.created_at DESC, pa.id DESC
             LIMIT 50'
        );
        $stmt->execute([$dominioH, $idPostH]);
        jsonResponse(200, $stmt->fetchAll());
    }

    $stmt = $db->query(
        'SELECT pa.*, d.url AS dominio_url, sol.name AS solicitante_nome
         FROM periodic_analysis pa
         LEFT JOIN domains d ON d.blog_name = pa.dominio
         LEFT JOIN users sol ON sol.id = pa.solicitante_id
         ORDER BY pa.created_at DESC, pa.id DESC'
    );
    jsonResponse(200, $stmt->fetchAll());
}

if ($method === 'POST') {
    // getInput() vem ANTES do gate: a cota precisa saber quantas linhas o pedido cria, e isso
    // só existe depois de ler o corpo. Ler o corpo de um pedido não autenticado é inócuo — o
    // gate logo abaixo continua devolvendo 401 sem executar nada.
    $input = getInput();
    $action = $input['action'] ?? '';

    // Quantas linhas este pedido cria — é o que a cota consome (uma por post reanalisado).
    // No lote vale o tamanho do array enviado, mesmo que algum item acabe pulado pela validação
    // mais abaixo: contar a mais erra para o lado seguro de um limite.
    $requested = $action === 'reanalyze_bulk'
        ? (is_array($input['items'] ?? null) ? count($input['items']) : 0)
        : ($action === 'reanalyze' ? 1 : 0);

    // Ponto único da permissão E da cota de escrita.
    $user = requireReanalyzePermission($requested);

    // Colunas opcionais: cada uma só entra no INSERT se existir no banco, para o endpoint
    // não quebrar num ambiente onde a migração ainda não rodou. Montar a lista de colunas
    // uma vez evita 4 blocos quase idênticos (com/sem publish_status × com/sem solicitante_id).
    $hasPublishStatus = (bool)$db->query("SHOW COLUMNS FROM periodic_analysis LIKE 'publish_status'")->fetch();
    $hasSolicitanteId = (bool)$db->query("SHOW COLUMNS FROM periodic_analysis LIKE 'solicitante_id'")->fetch();

    $insertCols = ['id_post', 'post_type', 'status_compliance', 'resumo_analise', 'dominio'];
    if ($hasPublishStatus) $insertCols[] = 'publish_status';
    if ($hasSolicitanteId) $insertCols[] = 'solicitante_id';
    $insertSql = 'INSERT INTO periodic_analysis (' . implode(', ', $insertCols) . ') VALUES ('
        . implode(', ', array_fill(0, count($insertCols), '?')) . ')';

    // Quem pediu: sempre da SESSÃO, nunca do cliente (senão daria para forjar autoria).
    $solicitanteId = (int)$user['id'];

    // Log dos pedidos de reanálise: uma linha por POST reanalisado (um lote de 50 → 50 linhas).
    // O gate $hasLogTable é o mesmo espírito da tolerância a coluna ausente logo acima, só que
    // na granularidade de TABELA: se ela não puder ser garantida, o log é pulado e a reanálise
    // continua funcionando. created_at fica com o DEFAULT do banco.
    ensureReanalysisLogTable();
    $hasLogTable = (bool)$db->query("SHOW TABLES LIKE 'periodic_reanalysis_log'")->fetch();
    $logSql = 'INSERT INTO periodic_reanalysis_log (user_id, dominio, id_post, analysis_id, action) VALUES (?, ?, ?, ?, ?)';

    if ($action === 'reanalyze_bulk') {
        $items = $input['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(400, ['error' => 'Nenhum item para reanalisar']);
        }
        if (count($items) > 500) {
            jsonResponse(400, ['error' => 'Máximo de 500 itens por vez']);
        }

        // created_at propositalmente omitido: usa DEFAULT CURRENT_TIMESTAMP do banco,
        // consistente com as linhas antigas (gerar no PHP em America/Sao_Paulo e inserir
        // em coluna TIMESTAMP causava skew de ~3h conforme o time_zone da sessão).
        $stmt = $db->prepare($insertSql);
        $logStmt = $hasLogTable ? $db->prepare($logSql) : null;

        $ids = [];
        $keys = [];
        try {
            $db->beginTransaction();
            foreach ($items as $it) {
                if (empty($it['id_post']) && ($it['id_post'] ?? null) !== '0' && ($it['id_post'] ?? null) !== 0) continue;
                if (empty($it['post_type'])) continue;
                if (empty($it['dominio'])) continue;
                // Mesma ordem de $insertCols.
                $params = [
                    $it['id_post'],
                    $it['post_type'],
                    'nao_analisado',
                    'esperando re-analise',
                    $it['dominio'],
                ];
                if ($hasPublishStatus) $params[] = $it['publish_status'] ?? 'draft';
                if ($hasSolicitanteId) $params[] = $solicitanteId;
                $stmt->execute($params);
                $newId = (int)$db->lastInsertId();
                $ids[] = $newId;
                $keys[] = $it['dominio'] . '::' . $it['id_post'];
                // Log DENTRO do foreach (e da transação): precisa do analysis_id de cada item,
                // que só existe logo após o execute dele. Um item pulado pelos `continue`
                // acima não cria análise, logo não gera log — as contagens ficam iguais.
                if ($logStmt) {
                    $logStmt->execute([$solicitanteId, $it['dominio'], $it['id_post'], $newId, 'reanalyze_bulk']);
                }
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(500, ['error' => 'Falha ao criar análises em lote']);
        }

        jsonResponse(201, [
            'success' => true,
            'ids' => $ids,
            'keys' => $keys,
            'count' => count($ids),
            'message' => count($ids) . ' análise(s) criada(s)'
        ]);
    }

    if ($action === 'reanalyze') {
        // Validar campos obrigatórios (sem "undefined array key" no PHP 8 se ausente;
        // aceita 0/'0', rejeita ausente/null/'').
        $required = ['id_post', 'post_type', 'dominio'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
                jsonResponse(400, ['error' => "Campo obrigatório: $field"]);
            }
        }

        // created_at omitido de propósito: DEFAULT CURRENT_TIMESTAMP do banco
        // (ver comentário no reanalyze_bulk sobre o skew de fuso).
        // Mesma ordem de $insertCols (montado no topo do bloco POST).
        $params = [
            $input['id_post'],
            $input['post_type'],
            'nao_analisado',
            'esperando re-analise',
            $input['dominio'],
        ];
        if ($hasPublishStatus) $params[] = $input['publish_status'] ?? 'draft';
        if ($hasSolicitanteId) $params[] = $solicitanteId;

        $stmt = $db->prepare($insertSql);
        $logStmt = $hasLogTable ? $db->prepare($logSql) : null;

        // Transação envolvendo os DOIS INSERTs (análise + log). Sem ela, uma falha só no log
        // devolveria 500 com a análise JÁ criada — o usuário tentaria de novo e criaria uma
        // análise duplicada. Fail-closed: ou os dois existem, ou nenhum. O caminho de sucesso
        // é idêntico ao de antes (mesmo INSERT, mesmo 201).
        try {
            $db->beginTransaction();
            $stmt->execute($params);
            $newId = (int)$db->lastInsertId();

            if ($logStmt) {
                $logStmt->execute([$solicitanteId, $input['dominio'], $input['id_post'], $newId, 'reanalyze']);
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(500, ['error' => 'Falha ao criar análise']);
        }

        jsonResponse(201, [
            'success' => true,
            'id' => $newId,
            'message' => 'Nova análise criada com status "Não analisado"'
        ]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido.']);
