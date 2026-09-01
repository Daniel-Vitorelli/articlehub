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
    // Histórico de um grupo específico (lazy para modal) - ?history=1&dominio=xxx&id_post=123
    if (isset($_GET['history']) && isset($_GET['dominio']) && isset($_GET['id_post'])) {
        $dominioH = trim($_GET['dominio']);
        $idPostH = trim($_GET['id_post']);
        $historyLimit = isset($_GET['history_limit']) ? max(1, min(50, (int)$_GET['history_limit'])) : 10;
        $historyOffset = isset($_GET['history_offset']) ? max(0, (int)$_GET['history_offset']) : 0;
        // Total
        $stmt = $db->prepare('SELECT COUNT(*) FROM periodic_analysis WHERE dominio = ? AND id_post = ?');
        $stmt->execute([$dominioH, $idPostH]);
        $total = (int)$stmt->fetchColumn();
        // Retorna só os campos leves: id, created_at, status_compliance
        // (resumo_analise já está na listagem paginada / prefetch)
        $stmt = $db->prepare(
            'SELECT pa.id, pa.created_at, pa.status_compliance, pa.dominio, pa.id_post
             FROM periodic_analysis pa
             WHERE pa.dominio = ? AND pa.id_post = ?
             ORDER BY pa.created_at DESC, pa.id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$dominioH, $idPostH, $historyLimit, $historyOffset]);
        jsonResponse(200, ['data' => $stmt->fetchAll(), 'total' => $total]);
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
            $countSql = "SELECT COUNT(*) FROM periodic_analysis pa
                         INNER JOIN $latestSub ON pa.dominio = latest.dominio AND pa.id_post = latest.id_post AND pa.id = latest.max_id
                         $whereSql";
            $stmt = $db->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
        }

        // Dados paginados (usa índice idx_periodic_group_ordered para ORDER BY)
        $dataSql = "SELECT pa.*, d.url AS dominio_url
                    FROM periodic_analysis pa
                    INNER JOIN $latestSub ON pa.dominio = latest.dominio AND pa.id_post = latest.id_post AND pa.id = latest.max_id
                    LEFT JOIN domains d ON d.blog_name = pa.dominio
                    $whereSql
                    ORDER BY pa.created_at DESC, pa.id DESC
                    LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare($dataSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($withHistory && $rows) {
            try {
                $dominios = array_map(fn($r) => $r->dominio ?: '', $rows);
                $ids = array_map(fn($r) => $r->id_post, $rows);
                $domList = implode(',', array_fill(0, count($dominios), '?'));
                $idList = implode(',', array_fill(0, count($ids), '?'));
                $args = array_merge($dominios, $ids);
                $stmtH = $db->prepare("SELECT id, dominio, id_post, created_at, status_compliance
                                       FROM periodic_analysis
                                       WHERE COALESCE(dominio,'') IN ($domList) AND id_post IN ($idList)
                                       ORDER BY COALESCE(dominio,''), id_post, created_at DESC, id DESC");
                $stmtH->execute($args);
                $histRows = $stmtH->fetchAll(PDO::FETCH_ASSOC);
                $byKey = [];
                foreach ($histRows as $h) {
                    $k = ($h['dominio'] ?: '') . '::' . $h['id_post'];
                    if (!isset($byKey[$k])) $byKey[$k] = [];
                    if (count($byKey[$k]) < 10) $byKey[$k][] = $h;
                }
                foreach ($rows as $r) {
                    $k = ($r->dominio ?: '') . '::' . $r->id_post;
                    $r->history = $byKey[$k] ?? [];
                }
            } catch (Exception $e) {
                error_log('[periodic_analysis] withHistory error: ' . $e->getMessage());
                foreach ($rows as $r) { $r->history = []; }
            }
        }

        jsonResponse(200, ['data' => $rows, 'total' => $total]);
    }

    // Fallback sem paginação (compatível com frontend antigo)
    // ?history=1&dominio=X&id_post=Y → retorna histórico completo de um grupo
    if (isset($_GET['history']) && isset($_GET['dominio']) && isset($_GET['id_post'])) {
        $dominio = trim($_GET['dominio']);
        $idPost = $_GET['id_post'];
        $stmt = $db->prepare(
            'SELECT pa.*, d.url AS dominio_url
             FROM periodic_analysis pa
             LEFT JOIN domains d ON d.blog_name = pa.dominio
             WHERE pa.dominio = ? AND pa.id_post = ?
             ORDER BY pa.created_at DESC, pa.id DESC'
        );
        $stmt->execute([$dominio, $idPost]);
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

    if ($action === 'reanalyze') {
        // Validar campos obrigatórios
        $required = ['id_post', 'post_type', 'dominio'];
        foreach ($required as $field) {
            if (empty($input[$field]) && $input[$field] !== '0') {
                jsonResponse(400, ['error' => "Campo obrigatório: $field"]);
            }
        }

        // Timestamp São Paulo (UTC-3)
        $tz = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTime('now', $tz);
        $createdAt = $now->format('Y-m-d H:i:s');

        // Verificar se coluna publish_status existe na tabela
        $stmt = $db->query("SHOW COLUMNS FROM periodic_analysis LIKE 'publish_status'");
        $hasPublishStatus = (bool)$stmt->fetch();

        if ($hasPublishStatus) {
            $publishStatus = $input['publish_status'] ?? 'draft';
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio, created_at, publish_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $input['id_post'],
                $input['post_type'],
                'nao_analisado',
                'esperanndo re-analise',
                $input['dominio'],
                $createdAt,
                $publishStatus
            ]);
        } else {
            // Fallback sem publish_status (coluna não existe no banco)
            $stmt = $db->prepare(
                'INSERT INTO periodic_analysis (id_post, post_type, status_compliance, resumo_analise, dominio, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $input['id_post'],
                $input['post_type'],
                'nao_analisado',
                'esperanndo re-analise',
                $input['dominio'],
                $createdAt
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
