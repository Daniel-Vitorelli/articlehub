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
        $stmt = $db->prepare(
            'SELECT pa.*, d.url AS dominio_url
             FROM periodic_analysis pa
             LEFT JOIN domains d ON d.blog_name = pa.dominio
             WHERE pa.dominio = ? AND pa.id_post = ?
             ORDER BY pa.created_at DESC, pa.id DESC'
        );
        $stmt->execute([$dominioH, $idPostH]);
        jsonResponse(200, $stmt->fetchAll());
    }

    // Lazy pagination: ?limit=50&offset=0&status=aprovado&post_type=post&dominio=xxx
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $status = trim($_GET['status'] ?? '');
    $postType = trim($_GET['post_type'] ?? '');
    $dominio = trim($_GET['dominio'] ?? '');

    // Se tem paginação, retorna agrupado (latest por dominio+id_post) + paginado
    if ($limit > 0) {
        $where = [];
        $params = [];
        if ($status !== '') { $where[] = 'pa.status_compliance = ?'; $params[] = $status; }
        if ($postType !== '') { $where[] = 'pa.post_type = ?'; $params[] = $postType; }
        if ($dominio !== '') { $where[] = 'pa.dominio = ?'; $params[] = $dominio; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Subquery para latest por grupo
        $latestSub = '(SELECT MAX(id) as max_id FROM periodic_analysis GROUP BY dominio, id_post) AS latest';

        // Total agrupado (para "Mostrando X de Y")
        $countSql = "SELECT COUNT(*) FROM periodic_analysis pa INNER JOIN $latestSub ON pa.id = latest.max_id $whereSql";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Dados paginados
        $dataSql = "SELECT pa.*, d.url AS dominio_url
                    FROM periodic_analysis pa
                    INNER JOIN $latestSub ON pa.id = latest.max_id
                    LEFT JOIN domains d ON d.blog_name = pa.dominio
                    $whereSql
                    ORDER BY pa.created_at DESC, pa.id DESC
                    LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare($dataSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        jsonResponse(200, ['data' => $rows, 'total' => $total]);
    }

    // Fallback sem paginação (compatível com frontend antigo)
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
