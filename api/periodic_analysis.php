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
$stmt = $db->query('SELECT * FROM periodic_analysis ORDER BY created_at DESC, id DESC');
jsonResponse(200, $stmt->fetchAll());