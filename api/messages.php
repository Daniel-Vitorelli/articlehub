<?php
// ============================================
//  ArticleHub — Messages API
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = getAction();

switch ($method) {
    case 'GET':
        listMessages();
        break;
    case 'POST':
        sendMessage();
        break;
    case 'PUT':
        markRead();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listMessages(): void
{
    $user = requireAuth();
    $tab = $_GET['tab'] ?? 'inbox';
    $db = getDB();

    if ($tab === 'sent') {
        $stmt = $db->prepare('SELECT m.*, u.name AS to_name FROM messages m LEFT JOIN users u ON m.to_id = u.id WHERE m.from_id = ? ORDER BY m.created_at DESC');
        $stmt->execute([$user['id']]);
    }
    else {
        $stmt = $db->prepare('SELECT m.*, u.name AS from_name FROM messages m LEFT JOIN users u ON m.from_id = u.id WHERE m.to_id = ? ORDER BY m.created_at DESC');
        $stmt->execute([$user['id']]);
    }

    jsonResponse(200, $stmt->fetchAll());
}

function sendMessage(): void
{
    $user = requireAuth();
    $input = getInput();

    $toId = (int)($input['toId'] ?? 0);
    $subject = trim($input['subject'] ?? '');
    $body = trim($input['body'] ?? '');

    if (!$toId || !$subject || !$body) {
        jsonResponse(400, ['error' => 'Destinatário, assunto e mensagem são obrigatórios.']);
    }

    $db = getDB();

    // Check recipient exists
    $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? AND active = 1');
    $stmt->execute([$toId]);
    $toUser = $stmt->fetch();
    if (!$toUser)
        jsonResponse(404, ['error' => 'Destinatário não encontrado.']);

    // Insert message
    $stmt = $db->prepare('INSERT INTO messages (from_id, to_id, subject, body) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], $toId, $subject, $body]);
    $msgId = (int)$db->lastInsertId();

    // Notify recipient
    $notifMsg = "Nova mensagem de {$user['name']}: \"{$subject}\"";
    $stmt = $db->prepare('INSERT INTO notifications (user_id, type, message, related_id) VALUES (?, "new_message", ?, ?)');
    $stmt->execute([$toId, $notifMsg, $msgId]);

    jsonResponse(201, ['id' => $msgId, 'message' => 'Mensagem enviada.']);
}

function markRead(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('UPDATE messages SET is_read = 1 WHERE id = ? AND to_id = ?');
    $stmt->execute([$id, $user['id']]);

    jsonResponse(200, ['message' => 'Mensagem marcada como lida.']);
}