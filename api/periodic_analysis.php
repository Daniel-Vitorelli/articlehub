<?php
// ============================================
//  ArticleHub — Periodic Analysis API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['error' => 'Método não permitido.']);
}

requireRole('admin');

$db = getDB();
$stmt = $db->query(
    'SELECT pa.*, d.url AS dominio_url
     FROM periodic_analysis pa
     LEFT JOIN domains d ON d.blog_name = pa.dominio
     ORDER BY pa.created_at DESC, pa.id DESC'
);
jsonResponse(200, $stmt->fetchAll());