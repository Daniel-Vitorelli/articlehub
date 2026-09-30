<?php
// ============================================
//  ArticleHub — Periodic Analysis Comments API (Admin only)
//  GET    ?dominio=X&id_post=Y  -> lista comentários do grupo
//  POST   { dominio, id_post, comentario } -> cria (autor vem da sessão)
//  PUT    { id, comentario }    -> edita
//  DELETE ?id=N                 -> exclui
// ============================================
require_once __DIR__ . '/config.php';

// A view de análise periódica é admin-only (mesmo nível de api/periodic_analysis.php)
requireRole('admin');

$db = getDB();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        listComments($db);
        break;
    case 'POST':
        createComment($db);
        break;
    case 'PUT':
        updateComment($db);
        break;
    case 'DELETE':
        deleteComment($db);
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

/**
 * id_post é INT NULL no banco. A string vazia coage para 0 no MySQL
 * (mesmo cuidado de api/periodic_analysis.php), então normalizamos para null.
 */
function normalizeIdPost($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return (int)$value;
}

/**
 * Valida e normaliza o texto do comentário.
 * Devolve null quando inválido (o chamador responde o erro).
 */
function validateComentario($value): ?string
{
    $comentario = trim((string)$value);
    if ($comentario === '') {
        return null;
    }
    // mb_strlen: um comentário cheio de emoji contaria errado com strlen.
    if (mb_strlen($comentario, 'UTF-8') > 2000) {
        return null;
    }
    return $comentario;
}

// --- List ---
function listComments(PDO $db): void
{
    $dominio = trim($_GET['dominio'] ?? '');
    if ($dominio === '') {
        jsonResponse(400, ['error' => 'Parâmetro dominio obrigatório.']);
    }
    if (!isset($_GET['id_post'])) {
        jsonResponse(400, ['error' => 'Parâmetro id_post obrigatório.']);
    }
    $idPost = normalizeIdPost(trim((string)$_GET['id_post']));

    // <=> é null-safe: com = o grupo de id_post NULL nunca casaria (NULL = NULL é falso).
    $stmt = $db->prepare(
        'SELECT id, autor, comentario, created_at
         FROM periodic_analysis_comments
         WHERE dominio = ? AND id_post <=> ?
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$dominio, $idPost]);

    jsonResponse(200, $stmt->fetchAll());
}

// --- Create ---
function createComment(PDO $db): void
{
    $user = requireAuth();
    $input = getInput();

    // array_key_exists (e não empty): aceita id_post = 0, rejeita campo ausente.
    foreach (['dominio', 'id_post', 'comentario'] as $field) {
        if (!array_key_exists($field, $input)) {
            jsonResponse(400, ['error' => "Campo obrigatório: $field"]);
        }
    }

    $dominio = trim((string)$input['dominio']);
    if ($dominio === '') {
        jsonResponse(400, ['error' => 'Campo obrigatório: dominio']);
    }

    $comentario = validateComentario($input['comentario']);
    if ($comentario === null) {
        jsonResponse(400, ['error' => 'Comentário vazio ou muito longo (máx. 2000 caracteres).']);
    }

    $idPost = normalizeIdPost($input['id_post']);

    // Garante que o grupo existe em periodic_analysis — evita comentário órfão.
    $chk = $db->prepare('SELECT 1 FROM periodic_analysis WHERE dominio = ? AND id_post <=> ? LIMIT 1');
    $chk->execute([$dominio, $idPost]);
    if (!$chk->fetchColumn()) {
        jsonResponse(404, ['error' => 'Grupo de análise não encontrado.']);
    }

    // autor SEMPRE da sessão — nunca do cliente (senão daria para forjar autoria).
    $autor = (string)($user['name'] ?? '');

    // created_at propositalmente omitido: usa DEFAULT CURRENT_TIMESTAMP do banco.
    // Gerar no PHP e inserir em coluna TIMESTAMP causava skew de ~3h.
    $stmt = $db->prepare(
        'INSERT INTO periodic_analysis_comments (autor, comentario, id_post, dominio)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$autor, $comentario, $idPost, $dominio]);
    $newId = (int)$db->lastInsertId();

    // Devolve a linha real (com id e created_at do banco): o front insere direto no
    // cache e renderiza sem um segundo GET.
    $stmt = $db->prepare('SELECT id, autor, comentario, created_at FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$newId]);

    jsonResponse(201, $stmt->fetch());
}

// --- Update ---
function updateComment(PDO $db): void
{
    requireAuth();
    $input = getInput();

    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }

    if (!array_key_exists('comentario', $input)) {
        jsonResponse(400, ['error' => 'Campo obrigatório: comentario']);
    }
    $comentario = validateComentario($input['comentario']);
    if ($comentario === null) {
        jsonResponse(400, ['error' => 'Comentário vazio ou muito longo (máx. 2000 caracteres).']);
    }

    // Confere existência antes: distingue 404 de "nada mudou" (rowCount 0 nos dois casos).
    $chk = $db->prepare('SELECT id FROM periodic_analysis_comments WHERE id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        jsonResponse(404, ['error' => 'Comentário não encontrado.']);
    }

    // Só o texto é editável — autor e grupo permanecem.
    $stmt = $db->prepare('UPDATE periodic_analysis_comments SET comentario = ? WHERE id = ?');
    $stmt->execute([$comentario, $id]);

    $stmt = $db->prepare('SELECT id, autor, comentario, created_at FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, $stmt->fetch());
}

// --- Delete ---
function deleteComment(PDO $db): void
{
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }

    $chk = $db->prepare('SELECT id FROM periodic_analysis_comments WHERE id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        jsonResponse(404, ['error' => 'Comentário não encontrado.']);
    }

    $stmt = $db->prepare('DELETE FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Comentário excluído.']);
}
