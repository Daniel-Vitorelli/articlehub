<?php
// ============================================
//  ArticleHub — Compliance History API
// ============================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['error' => 'Método não permitido.']);
}

$user = requireAuth();
$db = getDB();
$action = $_GET['action'] ?? '';
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

// Pré-carga de TODO o histórico de compliance (visível pelo usuário) para abrir modal instantâneo
if ($action === 'history_all') {
    // Mesma regra de visibilidade de listRequests
    if (in_array($user['role'], ['admin', 'revisor', 'redator'])) {
        $where = '1=1';
        $params = [];
    } elseif ($user['role'] === 'gestor') {
        $where = 'r.requested_by_id = ?';
        $params = [$user['id']];
    } else {
        $where = '(r.writer_id = ? OR r.requested_by_id = ?)';
        $params = [$user['id'], $user['id']];
    }
    $stmt = $db->prepare(
        "SELECT ch.id, ch.request_id, ch.status_compliance, ch.resumo_analise, ch.created_at
         FROM compliance_history ch
         INNER JOIN requests r ON r.id = ch.request_id
         WHERE {$where}
         ORDER BY ch.request_id, ch.created_at DESC"
    );
    $stmt->execute($params);
    jsonResponse(200, $stmt->fetchAll());
}

if (!$requestId) {
    jsonResponse(400, ['error' => 'request_id é obrigatório.']);
}

// Permissão — espelha o padrão do projeto (admin/revisor/redator ou dono)
$stmt = $db->prepare('SELECT requested_by_id FROM requests WHERE id = ?');
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) {
    jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
}
$allowed = in_array($user['role'], ['admin', 'revisor', 'redator'])
    || (int)$req['requested_by_id'] === (int)$user['id'];
if (!$allowed) {
    jsonResponse(403, ['error' => 'Sem permissão para ver este histórico.']);
}

$stmt = $db->prepare('SELECT ch.id, ch.request_id, ch.status_compliance, ch.resumo_analise, ch.created_at
                      FROM compliance_history ch
                      WHERE ch.request_id = ?
                      ORDER BY ch.created_at DESC');
$stmt->execute([$requestId]);

jsonResponse(200, $stmt->fetchAll());