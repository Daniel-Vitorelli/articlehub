<?php
// ============================================
//  ArticleHub — Periodic Analysis Comments API
//  GET    ?dominio=X&id_post=Y  -> lista comentários do grupo
//  GET    ?action=counts        -> mapa { "dominio::id_post": total } dos grupos COM comentários
//  POST   { dominio, id_post, comentario } -> cria (autor E user_id vêm da sessão)
//  PUT    { id, comentario }    -> edita (SÓ o autor; admin não edita o de outro)
//  DELETE ?id=N                 -> exclui (o autor OU um admin, para moderação)
//
//  Acessível a todos os perfis autenticados. A permissão de escrita não é por papel,
//  e sim por DONO: quem manda é o user_id gravado na criação, nunca o `autor` (nome
//  de exibição, que não é único).
// ============================================
require_once __DIR__ . '/config.php';

// A view de análise periódica é acessível a todos os perfis (não é mais admin-only).
requireAuth();

$db = getDB();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (getAction() === 'counts') {
            listCounts($db);
            break;
        }
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

// --- Counts ---
// Mapa dos grupos que TÊM comentários, para a tabela poder marcar a linha.
// Uma query agrupada só: são anotações manuais de admin, então o conjunto é pequeno.
// Devolve { "dominio::id_post": total } — a mesma chave de grupo de periodic_analysis.
// Fica de fora da query de listagem da análise periódica de propósito: se a tabela de
// comentários faltar, a tabela principal continua funcionando (a marca só não aparece).
function listCounts(PDO $db): void
{
    $stmt = $db->query(
        'SELECT dominio, id_post, COUNT(*) AS total
         FROM periodic_analysis_comments
         GROUP BY dominio, id_post'
    );

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        // Mesmo formato de chave do front: id_post nulo vira "" (o JS usa `id_post ?? ""`).
        $key = $row['dominio'] . '::' . ($row['id_post'] === null ? '' : $row['id_post']);
        $map[$key] = (int)$row['total'];
    }

    // Array vazio viraria "[]" no JSON, e o front espera um objeto.
    jsonResponse(200, $map ?: new stdClass());
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
    // user_id vai junto porque é o que o front usa para decidir quais botões mostrar.
    $stmt = $db->prepare(
        'SELECT id, autor, user_id, comentario, created_at
         FROM periodic_analysis_comments
         WHERE dominio = ? AND id_post <=> ?
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$dominio, $idPost]);

    jsonResponse(200, $stmt->fetchAll());
}

/**
 * Carrega o dono do comentário. Devolve null quando não existe.
 * Ponto único dos dois caminhos de escrita que dependem de dono (editar/excluir),
 * para a regra não divergir entre eles.
 */
function findComment(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT id, user_id FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * O autor pode sempre; o admin pode só para moderação (excluir), nunca para editar.
 * `user_id` NULL (comentário anterior à coluna) não pertence a ninguém: ninguém edita,
 * e o admin ainda consegue excluir.
 */
function isCommentOwner(array $comment, array $user): bool
{
    return (int)$comment['user_id'] === (int)$user['id'];
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

    // autor e user_id SEMPRE da sessão — nunca do cliente (senão daria para forjar
    // autoria, e o dono é o que decide quem pode editar/excluir).
    $autor = (string)($user['name'] ?? '');
    $userId = (int)$user['id'];

    // created_at propositalmente omitido: usa DEFAULT CURRENT_TIMESTAMP do banco.
    // Gerar no PHP e inserir em coluna TIMESTAMP causava skew de ~3h.
    $stmt = $db->prepare(
        'INSERT INTO periodic_analysis_comments (autor, user_id, comentario, id_post, dominio)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$autor, $userId, $comentario, $idPost, $dominio]);
    $newId = (int)$db->lastInsertId();

    // Devolve a linha real (com id e created_at do banco): o front insere direto no
    // cache e renderiza sem um segundo GET. user_id vai junto para o front já saber
    // que o comentário é do próprio autor (mostra os botões na hora).
    $stmt = $db->prepare('SELECT id, autor, user_id, comentario, created_at FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$newId]);

    jsonResponse(201, $stmt->fetch());
}

// --- Update ---
// Só o AUTOR edita. Um admin NÃO edita o comentário de outra pessoa — a moderação dele
// é excluir (ver deleteComment). Editar em nome de outro apagaria a autoria.
function updateComment(PDO $db): void
{
    $user = requireAuth();
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

    // Confere existência e DONO antes: distingue 404 de "nada mudou" (rowCount 0 nos dois
    // casos) e barra a edição do comentário alheio.
    $comment = findComment($db, $id);
    if (!$comment) {
        jsonResponse(404, ['error' => 'Comentário não encontrado.']);
    }
    if (!isCommentOwner($comment, $user)) {
        jsonResponse(403, ['error' => 'Você só pode editar o seu próprio comentário.']);
    }

    // Só o texto é editável — autor, user_id e grupo permanecem.
    $stmt = $db->prepare('UPDATE periodic_analysis_comments SET comentario = ? WHERE id = ?');
    $stmt->execute([$comentario, $id]);

    $stmt = $db->prepare('SELECT id, autor, user_id, comentario, created_at FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, $stmt->fetch());
}

// --- Delete ---
// O autor exclui o próprio comentário; um admin exclui qualquer um (moderação).
function deleteComment(PDO $db): void
{
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }

    $comment = findComment($db, $id);
    if (!$comment) {
        jsonResponse(404, ['error' => 'Comentário não encontrado.']);
    }

    $podeExcluir = isCommentOwner($comment, $user) || $user['role'] === 'admin';
    if (!$podeExcluir) {
        jsonResponse(403, ['error' => 'Você só pode excluir o seu próprio comentário.']);
    }

    $stmt = $db->prepare('DELETE FROM periodic_analysis_comments WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Comentário excluído.']);
}
