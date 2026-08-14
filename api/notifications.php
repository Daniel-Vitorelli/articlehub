<?php
// ============================================
//  ArticleHub — Notifications API
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = getAction();

switch ($method) {
    case 'GET':
        listNotifications();
        break;
    case 'PUT':
        if ($action === 'read_all') {
            markAllRead();
        }
        else {
            markRead();
        }
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listNotifications(): void
{
    $user = requireAuth();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$user['id']]);
    jsonResponse(200, $stmt->fetchAll());
}

function markRead(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);

    jsonResponse(200, ['message' => 'Notificação marcada como lida.']);
}

function markAllRead(): void
{
    $user = requireAuth();
    $db = getDB();
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    $stmt->execute([$user['id']]);

    jsonResponse(200, ['message' => 'Todas as notificações marcadas como lidas.']);
}