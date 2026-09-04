<?php
// ============================================
//  ArticleHub — Periodic Analysis API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

requireRole('admin');

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
    }

    // Lazy pagination: ?limit=50&offset=0&status=aprovado&post_type=post&dominio=xxx
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $status = trim($_GET['status'] ?? '');
    $postType = trim($_GET['post_type'] ?? '');
    $dominio = trim($_GET['dominio'] ?? '');
    $withHistory = isset($_GET['with_history']); // inclui histórico leve (id, created_at, status_compliance) por grupo

    // Se tem paginação, retorna agrupado (latest por dominio+id_post) + paginado
    if ($limit > 0) {
        $where = [];
        $params = [];
        if ($status !== '') { $where[] = 'pa.status_compliance = ?'; $params[] = $status; }
        if ($postType !== '') { $where[] = 'pa.post_type = ?'; $params[] = $postType; }
        if ($dominio !== '') { $where[] = 'pa.dominio = ?'; $params[] = $dominio; }
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
        $dataSql = "SELECT pa.*, d.url AS dominio_url
                    FROM periodic_analysis pa
                    INNER JOIN $latestSub ON pa.dominio = latest.dominio AND pa.id_post <=> latest.id_post AND pa.id = latest.max_id
                    LEFT JOIN domains d ON d.blog_name = pa.dominio
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
                SELECT dominio, id_post, id, created_at, status_compliance, resumo_analise
                FROM (
                    SELECT 
                        pa.dominio, pa.id_post, pa.id, pa.created_at, pa.status_compliance, pa.resumo_analise,
                        ROW_NUMBER() OVER (PARTITION BY pa.dominio, pa.id_post ORDER BY pa.created_at DESC, pa.id DESC) as rn
                    FROM periodic_analysis pa
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
            'SELECT id, created_at, status_compliance, resumo_analise
             FROM periodic_analysis
             WHERE dominio = ? AND id_post <=> ?
             ORDER BY created_at DESC, id DESC
             LIMIT 50'
        );
        $stmt->execute([$dominioH, $idPostH]);
        jsonResponse(200, $stmt->fetchAll());
    }

    $stmt = $db->query(
        'SELECT pa.*, d.url AS dominio_url
         FROM periodic_analysis pa
         LEFT JOIN domains d ON d.blog_name = pa.dominio
         ORDER BY pa.created_at DESC, pa.id DESC'
    );
    jsonResponse(200, $stmt->fetchAll());
}

if ($method === 'POST') {
    $input = getInput();
    $action = $input['action'] ?? '';

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
        $stmt = $db->query("SHOW COLUMNS FROM periodic_analysis LIKE 'publish_status'");
        $hasPublishStatus = (bool)$stmt->fetch();

        if ($hasPublishStatus) {
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio, publish_status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio)
                 VALUES (?, ?, ?, ?, ?)'
            );
        }

        $ids = [];
        $keys = [];
        try {
            $db->beginTransaction();
            foreach ($items as $it) {
                if (empty($it['id_post']) && ($it['id_post'] ?? null) !== '0' && ($it['id_post'] ?? null) !== 0) continue;
                if (empty($it['post_type'])) continue;
                if (empty($it['dominio'])) continue;
                if ($hasPublishStatus) {
                    $stmt->execute([
                        $it['id_post'],
                        $it['post_type'],
                        'nao_analisado',
                        'esperando re-analise',
                        $it['dominio'],
                        $it['publish_status'] ?? 'draft'
                    ]);
                } else {
                    $stmt->execute([
                        $it['id_post'],
                        $it['post_type'],
                        'nao_analisado',
                        'esperando re-analise',
                        $it['dominio']
                    ]);
                }
                $ids[] = (int)$db->lastInsertId();
                $keys[] = $it['dominio'] . '::' . $it['id_post'];
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
        // Verificar se coluna publish_status existe na tabela
        $stmt = $db->query("SHOW COLUMNS FROM periodic_analysis LIKE 'publish_status'");
        $hasPublishStatus = (bool)$stmt->fetch();

        if ($hasPublishStatus) {
            $publishStatus = $input['publish_status'] ?? 'draft';
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio, publish_status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $input['id_post'],
                $input['post_type'],
                'nao_analisado',
                'esperando re-analise',
                $input['dominio'],
                $publishStatus
            ]);
        } else {
            // Fallback sem publish_status (coluna não existe no banco)
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $input['id_post'],
                $input['post_type'],
                'nao_analisado',
                'esperando re-analise',
                $input['dominio']
            ]);
        }

        $newId = (int)$db->lastInsertId();

        jsonResponse(201, [
            'success' => true,
            'id' => $newId,
            'message' => 'Nova análise criada com status "Não analisado"'
        ]);
    }

    jsonResponse(400, ['error' => 'Ação inválida']);
}

jsonResponse(405, ['error' => 'Método não permitido.']);
