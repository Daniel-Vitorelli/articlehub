<?php
// ============================================
//  ArticleHub — Languages API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listLanguages();
        break;
    case 'POST':
        createLanguage();
        break;
    case 'PUT':
        updateLanguage();
        break;
    case 'DELETE':
        deleteLanguage();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listLanguages(): void
{
    requireAuth();
    $db = getDB();
    $stmt = $db->query('SELECT * FROM languages ORDER BY name');
    jsonResponse(200, $stmt->fetchAll());
}

function createLanguage(): void
{
    requireRole('admin');
    $input = getInput();

    $name = trim($input['name'] ?? '');
    $code = trim($input['code'] ?? '');
    $active = isset($input['active']) ? (bool)$input['active'] : true;

    if (!$name || !$code) {
        jsonResponse(400, ['error' => 'Nome e código são obrigatórios.']);
    }

    $db = getDB();

    // Duplicate checks
    if (checkDuplicate($db, 'languages', 'name', $name)) {
        jsonResponse(409, ['error' => 'Já existe um idioma com este nome.']);
    }
    if (checkDuplicate($db, 'languages', 'code', $code)) {
        jsonResponse(409, ['error' => 'Já existe um idioma com este código.']);
    }

    $stmt = $db->prepare('INSERT INTO languages (name, code, active) VALUES (?, ?, ?)');
    $stmt->execute([$name, $code, (int)$active]);

    jsonResponse(201, ['id' => (int)$db->lastInsertId(), 'message' => 'Idioma criado.']);
}

function updateLanguage(): void
{
    requireRole('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM languages WHERE id = ?');
    $stmt->execute([$id]);
    $lang = $stmt->fetch();
    if (!$lang)
        jsonResponse(404, ['error' => 'Idioma não encontrado.']);

    $name = trim($input['name'] ?? $lang['name']);
    $code = trim($input['code'] ?? $lang['code']);
    $active = isset($input['active']) ? (int)(bool)$input['active'] : (int)$lang['active'];

    // Duplicate checks (exclude current record)
    if (checkDuplicate($db, 'languages', 'name', $name, $id)) {
        jsonResponse(409, ['error' => 'Já existe um idioma com este nome.']);
    }
    if (checkDuplicate($db, 'languages', 'code', $code, $id)) {
        jsonResponse(409, ['error' => 'Já existe um idioma com este código.']);
    }

    $stmt = $db->prepare('UPDATE languages SET name = ?, code = ?, active = ? WHERE id = ?');
    $stmt->execute([$name, $code, $active, $id]);

    jsonResponse(200, ['message' => 'Idioma atualizado.']);
}

function deleteLanguage(): void
{
    requireRole('admin');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM languages WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Idioma excluído.']);
}