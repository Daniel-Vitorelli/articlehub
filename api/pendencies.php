<?php
// ============================================
//  ArticleHub — Pendencies API
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listPendencies();
        break;
    case 'POST':
        $input = getInput(); // read php://input once
        if (isset($input['action']) && $input['action'] === 'update_status') {
            updatePendencyStatus($input);
        } else {
            createPendency($input); // pass already-read input
        }
        break;
    case 'PUT':
        updatePendencyStatus(getInput());
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

// Verifica se o usuário tem permissão para gerenciar pendências na solicitação fornecida.
function canManagePendency(array $user, array $request): bool
{
    // Administradores, Redatores e Revisores podem mexer em qualquer uma
    if (in_array($user['role'], ['admin', 'redator', 'revisor'])) {
        return true;
    }
    // Gestores apenas nos seus próprios pedidos
    if ($user['role'] === 'gestor' && (int)$request['requested_by_id'] === (int)$user['id']) {
        return true;
    }
    // Tratativa extra de segurança
    return false;
}

// --- List ---
function listPendencies(): void
{
    $user = requireAuth();
    $db = getDB();
    
    $reqId = (int)($_GET['request_id'] ?? 0);
    if (!$reqId) {
        jsonResponse(400, ['error' => 'ID da solicitação obrigatório.']);
    }

    // Primeiro valida acesso à solicitação
    $stmt = $db->prepare('SELECT requested_by_id FROM requests WHERE id = ?');
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();

    if (!$req) {
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
    }

    if (!canManagePendency($user, $req)) {
        jsonResponse(403, ['error' => 'Sem permissão para visualizar pendências desta solicitação.']);
    }

    $sql = 'SELECT p.*, u.name AS user_name, u.role AS user_role
            FROM request_pendencies p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.request_id = ?
            ORDER BY FIELD(p.status, "unresolved", "resolved"), p.created_at DESC';
            
    $stmt = $db->prepare($sql);
    $stmt->execute([$reqId]);
    $pendencies = $stmt->fetchAll();

    jsonResponse(200, $pendencies);
}

// --- Create ---
function createPendency(array $input = []): void
{
    $user = requireAuth();
    $db = getDB();

    if (empty($input)) {
        $input = getInput(); // fallback for direct PUT calls
    }

    $reqId = (int)($input['request_id'] ?? 0);
    $description = trim($input['description'] ?? '');

    if (!$reqId || !$description) {
        jsonResponse(400, ['error' => 'Campos obrigatórios: request_id e description.']);
    }

    $stmt = $db->prepare('SELECT requested_by_id FROM requests WHERE id = ?');
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();

    if (!$req || !canManagePendency($user, $req)) {
        jsonResponse(403, ['error' => 'Sem permissão para adicionar pendências.']);
    }

    $stmt = $db->prepare('INSERT INTO request_pendencies (request_id, user_id, description, status) VALUES (?, ?, ?, "unresolved")');
    $stmt->execute([$reqId, $user['id'], $description]);

    // Registrar no log
    $changes = json_encode([['field' => 'pendency', 'from' => null, 'to' => 'Criou nova pendência']], JSON_UNESCAPED_UNICODE);
    $stmtLog = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "edit", ?)');
    $stmtLog->execute([$reqId, $user['id'], $changes]);

    jsonResponse(201, ['message' => 'Pendência registrada com sucesso.']);
}

// --- Update Status ---
function updatePendencyStatus(array $input = []): void
{
    $user = requireAuth();
    $db = getDB();
    
    if (empty($input)) {
        $input = getInput();
    }

    $pendencyId = (int)($input['id'] ?? 0);
    $newStatus = $input['status'] ?? ''; // 'resolved' or 'unresolved'

    if (!$pendencyId || !in_array($newStatus, ['resolved', 'unresolved'])) {
        jsonResponse(400, ['error' => 'Dados inválidos.']);
    }

    $stmt = $db->prepare('SELECT p.*, r.requested_by_id 
                          FROM request_pendencies p
                          JOIN requests r ON p.request_id = r.id
                          WHERE p.id = ?');
    $stmt->execute([$pendencyId]);
    $pend = $stmt->fetch();

    if (!$pend || !canManagePendency($user, ['requested_by_id' => $pend['requested_by_id']])) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar esta pendência.']);
    }

    $resolvedAt = ($newStatus === 'resolved') ? date('Y-m-d H:i:s') : null;

    $stmt = $db->prepare('UPDATE request_pendencies SET status = ?, resolved_at = ? WHERE id = ?');
    $stmt->execute([$newStatus, $resolvedAt, $pendencyId]);

    // Registrar no log
    $actionText = ($newStatus === 'resolved') ? 'Marcou pendência como resolvida' : 'Reabriu a pendência';
    $changes = json_encode([['field' => 'pendency_status', 'from' => $pend['status'], 'to' => $newStatus]], JSON_UNESCAPED_UNICODE);
    $stmtLog = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "edit", ?)');
    $stmtLog->execute([$pend['request_id'], $user['id'], $changes]);

    jsonResponse(200, ['message' => 'Status atualizado.']);
}
