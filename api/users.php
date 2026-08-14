<?php
// ============================================
//  ArticleHub — Users API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listUsers();
        break;
    case 'POST':
        createUser();
        break;
    case 'PUT':
        updateUser();
        break;
    case 'DELETE':
        deleteUser();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listUsers(): void
{
    $user = requireRole('admin', 'gestor', 'redator');
    $db = getDB();
    // Admin vê tudo; gestor e redator só id/nome/role/ativo (para filtros e seleção)
    if ($user['role'] === 'admin') {
        $stmt = $db->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY id');
    }
    else {
        $stmt = $db->query('SELECT id, name, role, active FROM users WHERE active = 1 ORDER BY id');
    }
    jsonResponse(200, $stmt->fetchAll());
}

function createUser(): void
{
    requireRole('admin');
    $input = getInput();

    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? 'redator';

    if (!$name || !$email || !$password) {
        jsonResponse(400, ['error' => 'Nome, email e senha são obrigatórios.']);
    }

    if (!in_array($role, ['admin', 'gestor', 'revisor', 'redator'])) {
        jsonResponse(400, ['error' => 'Role inválida.']);
    }

    $db = getDB();

    // Check duplicate email
    if (checkDuplicate($db, 'users', 'email', $email)) {
        jsonResponse(409, ['error' => 'Já existe um usuário com este email.']);
    }

    $stmt = $db->prepare('INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, 1)');
    $stmt->execute([$name, $email, $password, $role]);

    $newId = (int)$db->lastInsertId();

    // Create default preferences
    $stmt = $db->prepare('INSERT INTO user_preferences (user_id, theme, sidebar_collapsed) VALUES (?, \'dark\', 0)');
    $stmt->execute([$newId]);

    jsonResponse(201, ['id' => $newId, 'message' => 'Usuário criado.']);
}

function updateUser(): void
{
    requireRole('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user)
        jsonResponse(404, ['error' => 'Usuário não encontrado.']);

    $name = trim($input['name'] ?? $user['name']);
    $email = trim($input['email'] ?? $user['email']);
    $role = $input['role'] ?? $user['role'];
    $password = $input['password'] ?? '';

    if (!in_array($role, ['admin', 'gestor', 'revisor', 'redator'])) {
        jsonResponse(400, ['error' => 'Role inválida.']);
    }

    // Check duplicate email (exclude current user)
    if (checkDuplicate($db, 'users', 'email', $email, $id)) {
        jsonResponse(409, ['error' => 'Já existe um usuário com este email.']);
    }

    if ($password) {
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?');
        $stmt->execute([$name, $email, $password, $role, $id]);
    }
    else {
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?');
        $stmt->execute([$name, $email, $role, $id]);
    }

    jsonResponse(200, ['message' => 'Usuário atualizado.']);
}

function deleteUser(): void
{
    $currentUser = requireRole('admin');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    if ($id === $currentUser['id'])
        jsonResponse(400, ['error' => 'Você não pode excluir sua própria conta.']);

    $db = getDB();

    try {
        $db->beginTransaction();

        // Clean up related records before deleting user
        // 1. Nullify writer_id in requests (this column allows NULL)
        $db->prepare('UPDATE requests SET writer_id = NULL WHERE writer_id = ?')->execute([$id]);
        // 2. Delete history for requests owned by this user
        $db->prepare('DELETE rh FROM request_history rh INNER JOIN requests r ON rh.request_id = r.id WHERE r.requested_by_id = ?')->execute([$id]);
        // 3. Delete requests owned by this user (requested_by_id is NOT NULL)
        $db->prepare('DELETE FROM requests WHERE requested_by_id = ?')->execute([$id]);
        // 4. Nullify user_id in remaining request_history
        $db->prepare('UPDATE request_history SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        // 4. Delete notifications
        $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$id]);
        // 5. Delete messages (sent and received)
        $db->prepare('DELETE FROM messages WHERE from_id = ? OR to_id = ?')->execute([$id, $id]);
        // 6. Delete preferences
        $db->prepare('DELETE FROM user_preferences WHERE user_id = ?')->execute([$id]);
        // 7. Delete user
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        $db->commit();
        jsonResponse(200, ['message' => 'Usuário excluído.']);
    }
    catch (\Exception $e) {
        $db->rollBack();
        jsonResponse(500, ['error' => 'Erro ao excluir usuário: ' . $e->getMessage()]);
    }
}