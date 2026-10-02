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

    // O dia é o dia do USUÁRIO (São Paulo), não o do servidor — e o padrão "hoje" é
    // derivado do relógio do MySQL, para que o dia exibido e o dia filtrado venham do
    // mesmo relógio. Antes: `date('Y-m-d')` (relógio do container), um terceiro relógio
    // na mesma consulta.
    $filterDate = dateParam('date') ?? saoPauloToday($db);

    // Security check: only admin can see other users' logs
    if ($user['role'] !== 'admin') {
        $filterUserId = $user['id'];
    }

    $params = [];
    $where = [];

    // Intervalo half-open no fuso de São Paulo (ver applySaoPauloDayFilter em config.php).
    // Substitui `DATE(rh.created_at) = ?`: aquele comparava o dia de SP com o dia do
    // servidor em que a linha foi gravada (desaparecia o log perto da virada do dia no
    // servidor) e ainda desativava o índice.
    applySaoPauloDayFilter($where, $params, 'rh.created_at', $filterDate);

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
