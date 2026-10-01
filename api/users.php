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
    // Cota de reanálise: só o branch admin devolve. O não-admin é deliberadamente mínimo
    // (id/nome/role/ativo, para filtros e seleção) — a cota de um usuário não é assunto de
    // gestor/redator, e incluí-la ali vazaria o dado.
    $quotaFields = hasUserQuotaColumns($db) ? ', reanalysis_limit, reanalysis_window_hours' : '';
    if ($user['role'] === 'admin') {
        $stmt = $db->query("SELECT id, name, email, role, active, created_at{$quotaFields} FROM users ORDER BY id");
    }
    else {
        $stmt = $db->query('SELECT id, name, role, active FROM users WHERE active = 1 ORDER BY id');
    }
    jsonResponse(200, $stmt->fetchAll());
}

/**
 * As colunas da cota existem neste banco? Não há migrations no projeto, então cada uso confere
 * antes — sem elas o CRUD de usuário segue funcionando, só não há cota a gravar.
 */
function hasUserQuotaColumns(PDO $db): bool
{
    return (bool)$db->query("SHOW COLUMNS FROM users LIKE 'reanalysis_limit'")->fetch()
        && (bool)$db->query("SHOW COLUMNS FROM users LIKE 'reanalysis_window_hours'")->fetch();
}

/**
 * Valida e normaliza a cota vinda do formulário do admin.
 *
 * Regras: sem limite informado → NULL nas duas colunas (sem limite, o padrão de quem nunca
 * foi configurado). Limite informado sem janela válida → 24h, que é o caso comum ("N por dia").
 * Limite zero/negativo ou texto não numérico → 400, para não virar "sem limite" em silêncio.
 */
function normalizeUserQuota(array $input): array
{
    $rawLimit = $input['reanalysis_limit'] ?? null;
    $rawHours = $input['reanalysis_window_hours'] ?? null;

    $limitVazio = $rawLimit === null || $rawLimit === '';
    if (!$limitVazio && !is_numeric($rawLimit)) {
        jsonResponse(400, ['error' => 'Limite de reanálises inválido.']);
    }
    if (!$limitVazio && !is_numeric($rawHours) && $rawHours !== null && $rawHours !== '') {
        jsonResponse(400, ['error' => 'Janela de reanálises inválida.']);
    }

    $limit = $limitVazio ? null : (int)$rawLimit;
    if ($limit === null || $limit <= 0) {
        return ['limit' => null, 'hours' => null];
    }

    $hours = ($rawHours === null || $rawHours === '') ? 24 : (int)$rawHours;
    if ($hours <= 0) $hours = 24;

    return ['limit' => $limit, 'hours' => $hours];
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

    // Cota de reanálise (opcional no formulário). Só entra no INSERT se as colunas existirem.
    $quota = normalizeUserQuota($input);
    $quotaCols = hasUserQuotaColumns($db) ? ', reanalysis_limit, reanalysis_window_hours' : '';
    $quotaVals = $quotaCols ? ', ?, ?' : '';
    $params = [$name, $email, $password, $role];
    if ($quotaCols) {
        $params[] = $quota['limit'];
        $params[] = $quota['hours'];
    }

    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, active{$quotaCols}) VALUES (?, ?, ?, ?, 1{$quotaVals})");
    $stmt->execute($params);

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

    // Cota de reanálise. Campo AUSENTE do payload = mantém o valor atual (mesmo padrão dos
    // outros campos aqui, `$input['x'] ?? $user['x']`); presente e vazio = limpa (sem limite).
    // Sem essa distinção, um cliente que não mandasse os campos apagaria a cota em silêncio.
    $temQuotaNoPayload = array_key_exists('reanalysis_limit', $input)
        || array_key_exists('reanalysis_window_hours', $input);
    $quota = $temQuotaNoPayload
        ? normalizeUserQuota($input)
        : ['limit' => $user['reanalysis_limit'] ?? null, 'hours' => $user['reanalysis_window_hours'] ?? null];

    $quotaSet = hasUserQuotaColumns($db) ? ', reanalysis_limit = ?, reanalysis_window_hours = ?' : '';
    $quotaParams = $quotaSet ? [$quota['limit'], $quota['hours']] : [];

    if ($password) {
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?{$quotaSet} WHERE id = ?");
        $stmt->execute(array_merge([$name, $email, $password, $role], $quotaParams, [$id]));
    }
    else {
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?{$quotaSet} WHERE id = ?");
        $stmt->execute(array_merge([$name, $email, $role], $quotaParams, [$id]));
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