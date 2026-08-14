<?php
// ============================================
//  ArticleHub — Logs API (Status Change History)
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(405, ['error' => 'Método não permitido.']);
}

listLogs();

function listLogs(): void
{
    $user = requireAuth();
    $db = getDB();

    // Filters
    $filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $filterDate = $_GET['date'] ?? date('Y-m-d'); // Default: today

    // Security check: only admin can see other users' logs
    if ($user['role'] !== 'admin') {
        $filterUserId = $user['id'];
    }

    $params = [];
    $where = ["DATE(rh.created_at) = ?"];
    $params[] = $filterDate;

    if ($filterUserId) {
        $where[] = "rh.user_id = ?";
        $params[] = $filterUserId;
    }

    $whereClause = implode(' AND ', $where);

    $sql = "SELECT rh.*, 
                   u.name AS user_name, u.role AS user_role,
                   r.keyword, r.status AS current_status,
                   d.blog_name
            FROM request_history rh
            LEFT JOIN users u ON rh.user_id = u.id
            LEFT JOIN requests r ON rh.request_id = r.id
            LEFT JOIN domains d ON r.domain_id = d.id
            WHERE $whereClause
            ORDER BY rh.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Parse JSON changes
    foreach ($logs as &$log) {
        $log['changes'] = $log['changes'] ? json_decode($log['changes'], true) : [];
    }

    jsonResponse(200, $logs);
}
