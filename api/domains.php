<?php
// ============================================
//  ArticleHub — Domains API (Admin only)
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listDomains();
        break;
    case 'POST':
        createDomain();
        break;
    case 'PUT':
        updateDomain();
        break;
    case 'DELETE':
        deleteDomain();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listDomains(): void
{
    requireAuth();
    $db = getDB();
    $stmt = $db->query('SELECT * FROM domains ORDER BY id');
    jsonResponse(200, $stmt->fetchAll());
}

function createDomain(): void
{
    requireRole('admin');
    $input = getInput();

    $blogName = trim($input['blogName'] ?? '');
    $url = trim($input['url'] ?? '');
    $niche = trim($input['niche'] ?? '');
    $active = isset($input['active']) ? (bool)$input['active'] : true;
    $color = $input['color'] ?? '#7f5af0';

    if (!$blogName || !$url || !$niche) {
        jsonResponse(400, ['error' => 'Nome, URL e nicho são obrigatórios.']);
    }

    $db = getDB();

    // Duplicate checks
    if (checkDuplicate($db, 'domains', 'blog_name', $blogName)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com este nome.']);
    }
    if (checkDuplicate($db, 'domains', 'url', $url)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com esta URL.']);
    }

    $stmt = $db->prepare('INSERT INTO domains (blog_name, url, niche, color, active) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$blogName, $url, $niche, $color, (int)$active]);

    jsonResponse(201, ['id' => (int)$db->lastInsertId(), 'message' => 'Domínio criado.']);
}

function updateDomain(): void
{
    requireRole('admin');
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM domains WHERE id = ?');
    $stmt->execute([$id]);
    $domain = $stmt->fetch();
    if (!$domain)
        jsonResponse(404, ['error' => 'Domínio não encontrado.']);

    $blogName = trim($input['blogName'] ?? $domain['blog_name']);
    $url = trim($input['url'] ?? $domain['url']);
    $niche = trim($input['niche'] ?? $domain['niche']);
    $active = isset($input['active']) ? (int)(bool)$input['active'] : (int)$domain['active'];

    // Duplicate checks (exclude current record)
    if (checkDuplicate($db, 'domains', 'blog_name', $blogName, $id)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com este nome.']);
    }
    if (checkDuplicate($db, 'domains', 'url', $url, $id)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com esta URL.']);
    }

    $stmt = $db->prepare('UPDATE domains SET blog_name = ?, url = ?, niche = ?, active = ? WHERE id = ?');
    $stmt->execute([$blogName, $url, $niche, $active, $id]);

    jsonResponse(200, ['message' => 'Domínio atualizado.']);
}

function deleteDomain(): void
{
    requireRole('admin');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM domains WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Domínio excluído.']);
}