<?php
// ============================================
//  ArticleHub — Periodic Analysis API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

requireRole('admin');

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
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
