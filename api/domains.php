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
    // automacao_imagem adicionada pelo usuário (BOOLEAN/TINYINT) - SELECT * já inclui se existir
    $stmt = $db->query('SELECT * FROM domains ORDER BY id');
    $rows = $stmt->fetchAll();
    // Normaliza para frontend: garante 0/1 nos flags e texto nos campos editáveis
    // (language/bloco_anuncio são NULL-able na tabela real e podem vir null no JSON).
    foreach ($rows as &$r) {
        $r['automacao_imagem'] = (int)(bool)($r['automacao_imagem'] ?? 0);
        $r['color'] = (string)($r['color'] ?? '');
        $r['language'] = (string)($r['language'] ?? '');
        $r['bloco_anuncio'] = (string)($r['bloco_anuncio'] ?? '');
    }
    unset($r);
    jsonResponse(200, $rows);
}

function createDomain(): void
{
    requireRole('admin');
    $input = getInput();

    $blogName = trim($input['blogName'] ?? '');
    $url = trim($input['url'] ?? '');
    $niche = trim($input['niche'] ?? '');
    $active = isset($input['active']) ? (bool)$input['active'] : true;
    $color = normalizeDomainColor($input['color'] ?? null, '#7f5af0');
    $language = trim((string)($input['language'] ?? ''));
    $blocoAnuncio = (string)($input['bloco_anuncio'] ?? '');
    $automacaoImagem = isset($input['automacao_imagem']) ? (int)(bool)$input['automacao_imagem'] : 0;

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

    // `language` e `bloco_anuncio` vão SEMPRE no INSERT: em produção já foram NOT NULL
    // sem DEFAULT (erro 1364) e o INSERT sem elas derrubava a criação. String vazia
    // serve para as duas versões da tabela (NOT NULL e NULL-able).
    $base = [$blogName, $url, $niche, $color, $language, $blocoAnuncio, (int)$active];
    try {
        $stmt = $db->prepare('INSERT INTO domains (blog_name, url, niche, color, language, bloco_anuncio, active, automacao_imagem) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([...$base, $automacaoImagem]);
    } catch (PDOException $e) {
        // Só cai no fallback quando a coluna automacao_imagem não existe (1054).
        // Antes, qualquer erro era reexecutado e a segunda exceção virava fatal sem JSON.
        if (!isUnknownColumn($e)) {
            domainDbError($e);
        }
        $stmt = $db->prepare('INSERT INTO domains (blog_name, url, niche, color, language, bloco_anuncio, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        try {
            $stmt->execute($base);
        } catch (PDOException $e2) {
            domainDbError($e2);
        }
    }

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
    $active = isset($input['active']) ? (int)(bool)$input['active'] : (int)($domain['active'] ?? 1);
    $automacaoImagem = isset($input['automacao_imagem']) ? (int)(bool)$input['automacao_imagem'] : (int)($domain['automacao_imagem'] ?? 0);
    $color = normalizeDomainColor($input['color'] ?? null, (string)($domain['color'] ?? '#7f5af0'));
    // array_key_exists (e não `??`): campo AUSENTE preserva o valor gravado. O checkbox de
    // automação manda só `{id, automacao_imagem}` e não pode zerar cor/idioma/bloco.
    $language = array_key_exists('language', $input) ? trim((string)$input['language']) : (string)($domain['language'] ?? '');
    $blocoAnuncio = array_key_exists('bloco_anuncio', $input) ? (string)$input['bloco_anuncio'] : (string)($domain['bloco_anuncio'] ?? '');

    // Duplicate checks (exclude current record)
    if (checkDuplicate($db, 'domains', 'blog_name', $blogName, $id)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com este nome.']);
    }
    if (checkDuplicate($db, 'domains', 'url', $url, $id)) {
        jsonResponse(409, ['error' => 'Já existe um domínio com esta URL.']);
    }

    $base = [$blogName, $url, $niche, $color, $language, $blocoAnuncio, $active];
    try {
        $stmt = $db->prepare('UPDATE domains SET blog_name = ?, url = ?, niche = ?, color = ?, language = ?, bloco_anuncio = ?, active = ?, automacao_imagem = ? WHERE id = ?');
        $stmt->execute([...$base, $automacaoImagem, $id]);
    } catch (PDOException $e) {
        if (!isUnknownColumn($e)) {
            domainDbError($e);
        }
        $stmt = $db->prepare('UPDATE domains SET blog_name = ?, url = ?, niche = ?, color = ?, language = ?, bloco_anuncio = ?, active = ? WHERE id = ?');
        try {
            $stmt->execute([...$base, $id]);
        } catch (PDOException $e2) {
            domainDbError($e2);
        }
    }

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

// --- Helpers ---

// `color` é VARCHAR(7) e alimenta o CSS do blog: só aceita #RRGGBB.
// Ausente/vazio cai no fallback (o padrão da coluna na criação, o valor atual na edição).
function normalizeDomainColor($value, string $fallback): string
{
    $color = is_string($value) ? trim($value) : '';
    if ($color === '') {
        return $fallback;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        jsonResponse(400, ['error' => 'Cor inválida. Use o formato #RRGGBB.']);
    }
    return strtolower($color);
}

// 1054 = "Unknown column": é o único caso em que o fallback sem automacao_imagem faz sentido.
function isUnknownColumn(PDOException $e): bool
{
    return isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1054;
}

// Erro de banco tem de voltar como JSON: um fatal do PHP devolve 500 sem corpo e o
// front só consegue mostrar "Erro 500", sem a causa.
function domainDbError(PDOException $e): void
{
    error_log('[domains.php] ' . $e->getMessage());
    // errorInfo pode ser null (falha antes do prepare): não indexar sem checar.
    $detail = (is_array($e->errorInfo) && isset($e->errorInfo[2])) ? $e->errorInfo[2] : $e->getMessage();
    jsonResponse(500, ['error' => 'Erro ao salvar o domínio: ' . $detail]);
}