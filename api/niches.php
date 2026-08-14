<?php
// ============================================
//  ArticleHub — Niches API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listNiches();
        break;
    case 'POST':
        createNiche();
        break;
    case 'PUT':
        updateNiche();
        break;
    case 'DELETE':
        deleteNiche();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listNiches(): void
{
    requireAuth();
    $db = getDB();
    $stmt = $db->query('SELECT * FROM niches ORDER BY name');
    jsonResponse(200, $stmt->fetchAll());
}

function createNiche(): void
{
    requireRole('admin');
    $input = getInput();

    $name = trim($input['name'] ?? '');
    $active = isset($input['active']) ? (bool)$input['active'] : true;

    if (!$name) {
        jsonResponse(400, ['error' => 'Nome é obrigatório.']);
    }

    $db = getDB();

    // Duplicate check
    if (checkDuplicate($db, 'niches', 'name', $name)) {
        jsonResponse(409, ['error' => 'Já existe um nicho com este nome.']);
    }

    $stmt = $db->prepare('INSERT INTO niches (name, active) VALUES (?, ?)');
    $stmt->execute([$name, (int)$active]);

    jsonResponse(201, ['id' => (int)$db->lastInsertId(), 'message' => 'Nicho criado.']);
}

function updateNiche(): void
{
    requireRole('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM niches WHERE id = ?');
    $stmt->execute([$id]);
    $niche = $stmt->fetch();
    if (!$niche)
        jsonResponse(404, ['error' => 'Nicho não encontrado.']);

    $name = trim($input['name'] ?? $niche['name']);
    $active = isset($input['active']) ? (int)(bool)$input['active'] : (int)$niche['active'];

    // Duplicate check (exclude current record)
    if (checkDuplicate($db, 'niches', 'name', $name, $id)) {
        jsonResponse(409, ['error' => 'Já existe um nicho com este nome.']);
    }

    $stmt = $db->prepare('UPDATE niches SET name = ?, active = ? WHERE id = ?');
    $stmt->execute([$name, $active, $id]);

    jsonResponse(200, ['message' => 'Nicho atualizado.']);
}

function deleteNiche(): void
{
    requireRole('admin');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM niches WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Nicho excluído.']);
}