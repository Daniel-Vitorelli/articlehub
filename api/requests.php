<?php
// ============================================
//  ArticleHub — Requests API
// ============================================
require_once __DIR__ . '/config.php';

// -------------------------------------------------------------
// CAMPOS EXPOSTOS NA LISTAGEM
// Remova manualmente qualquer campo que NÃO quer expor no JSON.
// Ex: se não quer expor 'instructions', 'resumo_analise' ou
// 'wp_edit_url', basta apagar a linha correspondente.
// -------------------------------------------------------------
function getRequestPublicFields(): string
{
    // Estrutura atual de `requests` (19 cols + imagem + imagem_nome):
    // id, keyword, domain_id, writer_id, requested_by_id, status, priority,
    // wordcount, deadline, instructions, language, purpose, content_type,
    // niche_id, published_url, wp_edit_url, status_compliance, resumo_analise,
    // imagem MEDIUMBLOB, imagem_nome VARCHAR, created_at, updated_at
    // `imagem` e `resumo_analise/instructions` NÃO são carregados na listagem (lazy)
    // - imagem: flag `has_imagem` leve
    // - resumo_analise: flag `has_resumo` leve (usado só p/ badge clicável)
    // - instructions: só no detalhe/edit
    return '
        r.id,
        r.keyword,
        r.domain_id,
        r.writer_id,
        r.requested_by_id,
        r.status,
        r.priority,
        r.wordcount,
        r.deadline,
        r.language,
        r.purpose,
        r.content_type,
        r.niche_id,
        r.published_url,
        r.wp_edit_url,
        r.status_compliance,
        r.created_at,
        r.updated_at,
        (r.imagem IS NOT NULL) AS has_imagem,
        r.imagem_nome,
        (r.resumo_analise IS NOT NULL AND r.resumo_analise != \'\') AS has_resumo
    ';
}

function getHistoryPublicFields(): string
{
    return '
        rh.id,
        rh.request_id,
        rh.user_id,
        rh.action,
        rh.changes,
        rh.url,
        rh.created_at
    ';
}

// Campos internos (usados só para validação no PHP, não vão para o frontend)
// Mantidos explícitos para evitar SELECT * também internamente
// `imagem` NÃO entra aqui para não carregar BLOB em validações simples
function getRequestInternalFields(): string
{
    return '
        id, keyword, domain_id, writer_id, requested_by_id, status, priority,
        wordcount, deadline, instructions, language, purpose, content_type,
        niche_id, published_url, wp_edit_url, status_compliance, resumo_analise,
        imagem_nome, created_at, updated_at
        -- , imagem  -- só inclua se a lógica de update precisar validar imagem
    ';
}

$method = $_SERVER['REQUEST_METHOD'];
$action = getAction();

switch ($method) {
    case 'GET':
        if ($action === 'image') {
            getRequestImage();
        } elseif ($action === 'history') {
            getRequestHistory();
        } elseif ($action === 'detail') {
            getRequestDetail();
        } elseif ($action === 'deleted') {
            listDeletedRequests();
        } else {
            listRequests();
        }
        break;
    case 'POST':
        createRequest();
        break;
    case 'PUT':
        if ($action === 'status') {
            updateStatus();
        }
        elseif ($action === 'publish') {
            publishRequest();
        }
        elseif ($action === 'restore') {
            restoreRequest();
        }
        elseif ($action === 'reset_compliance') {
            resetCompliance();
        }
        elseif ($action === 'clear_image') {
            clearImage();
        }
        else {
            updateRequest();
        }
        break;
    case 'DELETE':
        deleteRequest();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

// --- List (filtered by role) - sem history (lazy) ---
function listRequests(): void
{
    $user = requireAuth();
    $db = getDB();
    $fields = getRequestPublicFields();

    if ($user['role'] === 'admin' || $user['role'] === 'revisor') {
        $sql = "SELECT {$fields},
                       d.blog_name, d.color, d.url AS domain_url,
                       w.name AS writer_name, g.name AS requester_name,
                       (SELECT COUNT(*) FROM request_pendencies rp WHERE rp.request_id = r.id AND rp.status = \"unresolved\") AS unresolved_pendencies_count
                FROM requests r
                LEFT JOIN domains d ON r.domain_id = d.id
                LEFT JOIN users w ON r.writer_id = w.id
                LEFT JOIN users g ON r.requested_by_id = g.id
                WHERE r.status != \"deleted\"
                ORDER BY FIELD(r.status, \"pending\",\"in-progress\",\"review\",\"done\",\"published\",\"revisado\"), r.deadline";
        $stmt = $db->query($sql);
    }
    elseif ($user['role'] === 'gestor') {
        $sql = "SELECT {$fields},
                       d.blog_name, d.color, d.url AS domain_url,
                       w.name AS writer_name, g.name AS requester_name,
                       (SELECT COUNT(*) FROM request_pendencies rp WHERE rp.request_id = r.id AND rp.status = \"unresolved\") AS unresolved_pendencies_count
                FROM requests r
                LEFT JOIN domains d ON r.domain_id = d.id
                LEFT JOIN users w ON r.writer_id = w.id
                LEFT JOIN users g ON r.requested_by_id = g.id
                WHERE r.requested_by_id = ? AND r.status != \"deleted\"
                ORDER BY FIELD(r.status, \"pending\",\"in-progress\",\"review\",\"done\",\"published\",\"revisado\"), r.deadline";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id']]);
    }
    else {
        // redator — see assigned requests AND self-created requests
        $sql = "SELECT {$fields},
                       d.blog_name, d.color, d.url AS domain_url,
                       w.name AS writer_name, g.name AS requester_name,
                       (SELECT COUNT(*) FROM request_pendencies rp WHERE rp.request_id = r.id AND rp.status = \"unresolved\") AS unresolved_pendencies_count
                FROM requests r
                LEFT JOIN domains d ON r.domain_id = d.id
                LEFT JOIN users w ON r.writer_id = w.id
                LEFT JOIN users g ON r.requested_by_id = g.id
                WHERE (r.writer_id = ? OR r.requested_by_id = ?) AND r.status != \"deleted\"
                ORDER BY FIELD(r.status, \"pending\",\"in-progress\",\"review\",\"done\",\"published\",\"revisado\"), r.deadline";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id'], $user['id']]);
    }

    $requests = $stmt->fetchAll();

    jsonResponse(200, $requests);
}

// --- List Deleted (filtered by role) ---
function listDeletedRequests(): void
{
    $user = requireAuth();
    $db = getDB();
    $fields = getRequestPublicFields();

    if ($user['role'] === 'admin') {
        $sql = "SELECT {$fields},
                       d.blog_name, d.color, d.url AS domain_url,
                       w.name AS writer_name, g.name AS requester_name
                FROM requests r
                LEFT JOIN domains d ON r.domain_id = d.id
                LEFT JOIN users w ON r.writer_id = w.id
                LEFT JOIN users g ON r.requested_by_id = g.id
                WHERE r.status = \"deleted\"
                ORDER BY r.updated_at DESC";
        $stmt = $db->query($sql);
    }
    else {
        // Redator/Gestor/Revisor só veem os deletados que eles mesmos solicitaram
        $sql = "SELECT {$fields},
                       d.blog_name, d.color, d.url AS domain_url,
                       w.name AS writer_name, g.name AS requester_name
                FROM requests r
                LEFT JOIN domains d ON r.domain_id = d.id
                LEFT JOIN users w ON r.writer_id = w.id
                LEFT JOIN users g ON r.requested_by_id = g.id
                WHERE r.requested_by_id = ? AND r.status = \"deleted\"
                ORDER BY r.updated_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user['id']]);
    }

    $requests = $stmt->fetchAll();

    jsonResponse(200, $requests);
}

// --- History (lazy, só quando abre detalhe) ---
function getRequestHistory(): void
{
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? $_GET['request_id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }
    $db = getDB();
    // Verifica permissão para ver a solicitação (mesma regra de listRequests)
    $stmt = $db->prepare('SELECT requested_by_id, writer_id FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) {
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
    }
    $canView = false;
    if (in_array($user['role'], ['admin', 'revisor'])) {
        $canView = true;
    } elseif ((int)$req['requested_by_id'] === (int)$user['id'] || (int)$req['writer_id'] === (int)$user['id']) {
        $canView = true;
    }
    if (!$canView) {
        jsonResponse(403, ['error' => 'Sem permissão para ver o histórico.']);
    }

    $historyFields = getHistoryPublicFields();
    $hStmt = $db->prepare("SELECT {$historyFields}, u.name AS user_name
                           FROM request_history rh
                           LEFT JOIN users u ON rh.user_id = u.id
                           WHERE rh.request_id = ?
                           ORDER BY rh.created_at");
    $hStmt->execute([$id]);
    $history = $hStmt->fetchAll();
    foreach ($history as &$h) {
        $h['changes'] = $h['changes'] ? json_decode($h['changes'], true) : [];
    }
    jsonResponse(200, $history);
}

// --- Detail (lazy, inclui instructions e resumo_analise) ---
function getRequestDetail(): void
{
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }
    $db = getDB();
    $detailFields = '
        r.id,
        r.keyword,
        r.domain_id,
        r.writer_id,
        r.requested_by_id,
        r.status,
        r.priority,
        r.wordcount,
        r.deadline,
        r.instructions,
        r.language,
        r.purpose,
        r.content_type,
        r.niche_id,
        r.published_url,
        r.wp_edit_url,
        r.status_compliance,
        r.resumo_analise,
        r.created_at,
        r.updated_at,
        (r.imagem IS NOT NULL) AS has_imagem,
        r.imagem_nome,
        (r.resumo_analise IS NOT NULL AND r.resumo_analise != \'\') AS has_resumo
    ';
    $sql = "SELECT {$detailFields},
                   d.blog_name, d.color, d.url AS domain_url,
                   w.name AS writer_name, g.name AS requester_name,
                   (SELECT COUNT(*) FROM request_pendencies rp WHERE rp.request_id = r.id AND rp.status = \"unresolved\") AS unresolved_pendencies_count
            FROM requests r
            LEFT JOIN domains d ON r.domain_id = d.id
            LEFT JOIN users w ON r.writer_id = w.id
            LEFT JOIN users g ON r.requested_by_id = g.id
            WHERE r.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
    }
    $canView = false;
    if (in_array($user['role'], ['admin', 'revisor'])) {
        $canView = true;
    } elseif ((int)$r['requested_by_id'] === (int)$user['id'] || (int)$r['writer_id'] === (int)$user['id']) {
        $canView = true;
    }
    if (!$canView) {
        jsonResponse(403, ['error' => 'Sem permissão para ver esta solicitação.']);
    }
    jsonResponse(200, $r);
}

// --- Create (any authenticated user) ---
function createRequest(): void
{
    $user = requireAuth();
    $input = getInput();
    $db = getDB();

    $keyword = trim($input['keyword'] ?? '');
    $domainId = (int)($input['domainId'] ?? 0);
    $writerId = !empty($input['writerId']) ? (int)$input['writerId'] : null;
    $priority = $input['priority'] ?? 'media';
    $wordcount = $input['wordcount'] ?? '800-1200';
    $deadline = $input['deadline'] ?? '';
    $language = $input['language'] ?? 'pt-br';
    $purpose = $input['purpose'] ?? 'conteudo';
    $contentType = $input['content_type'] ?? 'artigo';
    $nicheId = !empty($input['niche_id']) ? (int)$input['niche_id'] : null;
    $instructions = $input['instructions'] ?? '';

    // Redators always assign to themselves
    if ($user['role'] === 'redator') {
        $writerId = $user['id'];
    }

    if (!$keyword || !$domainId || !$deadline) {
        jsonResponse(400, ['error' => 'Palavra-chave, blog e prazo são obrigatórios.']);
    }



    $stmt = $db->prepare('INSERT INTO requests (keyword, domain_id, writer_id, requested_by_id, status, priority, wordcount, deadline, language, purpose, content_type, niche_id, instructions)
                          VALUES (?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$keyword, $domainId, $writerId, $user['id'], $priority, $wordcount, $deadline, $language, $purpose, $contentType, $nicheId, $instructions]);
    $newId = (int)$db->lastInsertId();

    // History entry
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action) VALUES (?, ?, "create")');
    $stmt->execute([$newId, $user['id']]);

    // Notify assigned writer (if different from creator)
    if ($writerId && $writerId != $user['id']) {
        $stmt = $db->prepare('INSERT INTO notifications (user_id, type, message, related_id)
                              VALUES (?, "new_request", ?, ?)');
        $msg = "Novo artigo atribuído: \"{$keyword}\"";
        $stmt->execute([$writerId, $msg, $newId]);
    }

    jsonResponse(201, ['id' => $newId, 'message' => 'Solicitação criada.']);
}

// --- Update ---
function updateRequest(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    // SELECT explícito (era SELECT *) — uso interno, não exposto
    $internalFields = getRequestInternalFields();
    $stmt = $db->prepare("SELECT {$internalFields} FROM requests WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);

    // Permission check
    if ($user['role'] !== 'admin' && $r['requested_by_id'] != $user['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para editar.']);
    }

    $keyword = trim($input['keyword'] ?? $r['keyword']);
    $domainId = (int)($input['domainId'] ?? $r['domain_id']);
    $writerId = isset($input['writerId']) ? ((int)$input['writerId'] ?: null) : $r['writer_id'];
    $priority = $input['priority'] ?? $r['priority'];
    $wordcount = $input['wordcount'] ?? $r['wordcount'];
    $deadline = $input['deadline'] ?? $r['deadline'];
    $language = $input['language'] ?? $r['language'];
    $purpose = $input['purpose'] ?? $r['purpose'];
    $contentType = $input['content_type'] ?? $r['content_type'];
    $nicheId = isset($input['niche_id']) ? (!empty($input['niche_id']) ? (int)$input['niche_id'] : null) : $r['niche_id'];
    $instructions = $input['instructions'] ?? $r['instructions'];

    // Build changes diff
    $changes = [];
    $fieldMap = [
        'keyword' => $keyword, 'domain_id' => $domainId, 'writer_id' => $writerId,
        'priority' => $priority, 'wordcount' => $wordcount, 'deadline' => $deadline,
        'language' => $language, 'purpose' => $purpose, 'content_type' => $contentType,
        'niche_id' => $nicheId, 'instructions' => $instructions,
    ];
    foreach ($fieldMap as $field => $newVal) {
        $oldVal = $r[$field];
        if ((string)$oldVal !== (string)$newVal) {
            $changes[] = ['field' => $field, 'from' => $oldVal, 'to' => $newVal];
        }
    }

    if (count($changes) === 0) {
        jsonResponse(200, ['message' => 'Nenhuma alteração.']);
    }

    // Update
    $stmt = $db->prepare('UPDATE requests SET keyword = ?, domain_id = ?, writer_id = ?, priority = ?, wordcount = ?, deadline = ?, language = ?, purpose = ?, content_type = ?, niche_id = ?, instructions = ? WHERE id = ?');
    $stmt->execute([$keyword, $domainId, $writerId, $priority, $wordcount, $deadline, $language, $purpose, $contentType, $nicheId, $instructions, $id]);

    // History
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "edit", ?)');
    $stmt->execute([$id, $user['id'], json_encode($changes, JSON_UNESCAPED_UNICODE)]);

    jsonResponse(200, ['message' => 'Solicitação atualizada.']);
}

// --- Status change ---
function updateStatus(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    $newStatus = $input['status'] ?? '';
    $wpEditUrl = trim($input['wp_edit_url'] ?? '');

    if (!$id || !$newStatus) {
        jsonResponse(400, ['error' => 'ID e status são obrigatórios.']);
    }

    $validStatuses = ['pending', 'in-progress', 'review', 'done', 'published', 'revisado'];
    if (!in_array($newStatus, $validStatuses)) {
        jsonResponse(400, ['error' => 'Status inválido.']);
    }

    $db = getDB();
    $internalFields = getRequestInternalFields();
    $stmt = $db->prepare("SELECT {$internalFields} FROM requests WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);

    // Permissão: admin, redator e revisor sempre; dono do pedido também
    $canChange = in_array($user['role'], ['admin', 'redator', 'revisor'])
        || (int)$r['requested_by_id'] === (int)$user['id'];
    if (!$canChange) {
        jsonResponse(403, ['error' => 'Sem permissão para alterar o status desta solicitação.']);
    }

    // Apenas admin e revisor podem marcar como 'revisado'
    if ($newStatus === 'revisado' && !in_array($user['role'], ['admin', 'revisor'])) {
        jsonResponse(403, ['error' => 'Apenas administradores e revisores podem marcar como revisado.']);
    }

    // 'revisado' só pode ser definido a partir de 'published'
    if ($newStatus === 'revisado' && $r['status'] !== 'published') {
        jsonResponse(400, ['error' => 'Apenas artigos publicados podem ser marcados como revisado.']);
    }

    $oldStatus = $r['status'];
    if ($oldStatus === 'revisado') {
        jsonResponse(400, ['error' => 'Artigos revisados não podem ter o status alterado.']);
    }
    if ($oldStatus === 'published' && $newStatus !== 'revisado') {
        jsonResponse(400, ['error' => 'Artigos publicados só podem ser marcados como revisado.']);
    }

    if ($oldStatus === $newStatus) {
        jsonResponse(200, ['message' => 'Status inalterado.']);
    }

    // When changing to 'done', require WP edit URL with hostname validation
    if ($newStatus === 'done') {
        if (!$wpEditUrl) {
            jsonResponse(400, ['error' => 'A URL de edição do WordPress é obrigatória para marcar como Concluído.']);
        }
        if (!filter_var($wpEditUrl, FILTER_VALIDATE_URL)) {
            jsonResponse(400, ['error' => 'URL de edição inválida.']);
        }
        // Hostname check
        $stmt2 = $db->prepare('SELECT url FROM domains WHERE id = ?');
        $stmt2->execute([$r['domain_id']]);
        $domain = $stmt2->fetch();
        if ($domain) {
            $expectedHost = parse_url($domain['url'], PHP_URL_HOST);
            $providedHost = parse_url($wpEditUrl, PHP_URL_HOST);
            if ($expectedHost && $providedHost && $expectedHost !== $providedHost) {
                jsonResponse(400, ['error' => "O domínio \"{$providedHost}\" não corresponde ao esperado \"{$expectedHost}\"."]);
            }
        }
        // Save WP edit URL
        $stmt = $db->prepare('UPDATE requests SET status = ?, wp_edit_url = ?, status_compliance = "nao_analisado" WHERE id = ?');
        $stmt->execute([$newStatus, $wpEditUrl, $id]);
    }
    else {
        $stmt = $db->prepare('UPDATE requests SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $id]);
    }

    // History
    $changes = json_encode([['field' => 'status', 'from' => $oldStatus, 'to' => $newStatus]], JSON_UNESCAPED_UNICODE);
    $historyUrl = ($newStatus === 'done') ? $wpEditUrl : null;
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes, url) VALUES (?, ?, "status_change", ?, ?)');
    $stmt->execute([$id, $user['id'], $changes, $historyUrl]);

    // Notify request owner if different user
    if ($r['requested_by_id'] != $user['id']) {
        $statusLabels = ['pending' => 'Pendente', 'in-progress' => 'Em Produção', 'review' => 'Em Revisão', 'done' => 'Concluído', 'published' => 'Publicado', 'revisado' => 'Revisado'];
        $label = $statusLabels[$newStatus] ?? $newStatus;
        $msg = "Status alterado para \"{$label}\": \"{$r['keyword']}\"";
        $stmt = $db->prepare('INSERT INTO notifications (user_id, type, message, related_id) VALUES (?, "status_changed", ?, ?)');
        $stmt->execute([$r['requested_by_id'], $msg, $id]);
    }

    jsonResponse(200, ['message' => 'Status atualizado.']);
}

// --- Publish ---
function publishRequest(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    $url = trim($input['url'] ?? '');

    if (!$id || !$url) {
        jsonResponse(400, ['error' => 'ID e URL são obrigatórios.']);
    }

    $db = getDB();
    $internalFields = getRequestInternalFields();
    $stmt = $db->prepare("SELECT {$internalFields} FROM requests WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);

    // Permissão: admin, redator e revisor sempre; dono do pedido também
    $canPublish = in_array($user['role'], ['admin', 'redator', 'revisor'])
        || (int)$r['requested_by_id'] === (int)$user['id'];
    if (!$canPublish) {
        jsonResponse(403, ['error' => 'Sem permissão para publicar esta solicitação.']);
    }

    if ($r['status'] !== 'done') {
        jsonResponse(400, ['error' => 'O artigo precisa estar com status "Concluído" antes de ser publicado.']);
    }

    if ($r['status_compliance'] !== 'aprovado') {
        jsonResponse(400, ['error' => 'O artigo precisa estar com compliance "Aprovado" para ser publicado.']);
    }

    // URL format validation
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        jsonResponse(400, ['error' => 'URL inválida.']);
    }

    // Domain host check
    $stmt2 = $db->prepare('SELECT url FROM domains WHERE id = ?');
    $stmt2->execute([$r['domain_id']]);
    $domain = $stmt2->fetch();
    if ($domain) {
        $expectedHost = parse_url($domain['url'], PHP_URL_HOST);
        $providedHost = parse_url($url, PHP_URL_HOST);
        if ($expectedHost && $providedHost && $expectedHost !== $providedHost) {
            jsonResponse(400, ['error' => "O domínio \"{$providedHost}\" não corresponde ao esperado \"{$expectedHost}\"."]);
        }
    }

    // Update - ao publicar limpa imagem e nome (libera espaço)
    $stmt = $db->prepare('UPDATE requests SET status = "published", published_url = ?, imagem = NULL, imagem_nome = NULL WHERE id = ?');
    $stmt->execute([$url, $id]);

    // History
    $changes = json_encode([['field' => 'status', 'from' => $r['status'], 'to' => 'published']], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes, url) VALUES (?, ?, "published", ?, ?)');
    $stmt->execute([$id, $user['id'], $changes, $url]);

    jsonResponse(200, ['message' => 'Artigo publicado.']);
}

// --- Delete ---
function deleteRequest(): void
{
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $force = (int)($_GET['force'] ?? 0);

    $db = getDB();
    $stmt = $db->prepare('SELECT status, requested_by_id FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);

    // Hard delete: only admins, and only when force=1 (from trash page)
    if ($force === 1 && $user['role'] === 'admin') {
        $stmt = $db->prepare('DELETE FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(200, ['message' => 'Solicitação excluída permanentemente.']);
    }

    // Soft delete (move to trash): admin/revisor/redator can delete any request,
    // gestores can delete only their own pending requests
    $canSoftDelete = in_array($user['role'], ['admin', 'revisor', 'redator']) ||
        ((int)$r['requested_by_id'] === (int)$user['id'] && $r['status'] === 'pending');

    if (!$canSoftDelete) {
        jsonResponse(403, ['error' => 'Você não tem permissão para excluir esta solicitação.']);
    }

    $prevStatus = $r['status'];
    $stmt = $db->prepare('UPDATE requests SET status = "deleted" WHERE id = ?');
    $stmt->execute([$id]);

    // Log history
    $changes = json_encode([['field' => 'status', 'from' => $prevStatus, 'to' => 'deleted']], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "status_change", ?)');
    $stmt->execute([$id, $user['id'], $changes]);

    jsonResponse(200, ['message' => 'Solicitação movida para a lixeira.']);
}

// --- Restore (from trash) ---
function restoreRequest(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id)
        jsonResponse(400, ['error' => 'ID obrigatório.']);

    $db = getDB();
    $stmt = $db->prepare('SELECT requested_by_id FROM requests WHERE id = ? AND status = "deleted"');
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada ou não está na lixeira.']);

    // Only admin can restore any request; others only their own
    if ($user['role'] !== 'admin' && (int)$r['requested_by_id'] !== (int)$user['id']) {
        jsonResponse(403, ['error' => 'Sem permissão para recuperar esta solicitação.']);
    }

    $stmt = $db->prepare('UPDATE requests SET status = "pending" WHERE id = ?');
    $stmt->execute([$id]);

    // Log
    $changes = json_encode([['field' => 'status', 'from' => 'deleted', 'to' => 'pending']], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "status_change", ?)');
    $stmt->execute([$id, $user['id'], $changes]);

    jsonResponse(200, ['message' => 'Solicitação recuperada com sucesso.']);
}

// --- Get Image (lazy load) ---
function getRequestImage(): void
{
    $user = requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID obrigatório.']);
    }

    $db = getDB();
    // Só carrega o BLOB quando solicitado (+ nome original se existir)
    $stmt = $db->prepare('SELECT imagem, imagem_nome, requested_by_id, writer_id FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
    }

    // Permissão: admin/revisor vê tudo; gestor/redator só se for dono ou redator atribuído
    $canView = false;
    if (in_array($user['role'], ['admin', 'revisor'])) {
        $canView = true;
    } elseif ((int)$r['requested_by_id'] === (int)$user['id'] || (int)$r['writer_id'] === (int)$user['id']) {
        $canView = true;
    }
    if (!$canView) {
        jsonResponse(403, ['error' => 'Sem permissão para ver a imagem desta solicitação.']);
    }

    if ($r['imagem'] === null || $r['imagem'] === '') {
        jsonResponse(404, ['error' => 'Solicitação sem imagem.']);
    }

    $blob = $r['imagem'];
    $mime = 'image/jpeg';
    $b64 = '';

    // Caso já esteja como data URI (data:image/...;base64,XXXX)
    $trimmed = ltrim($blob);
    if (strpos($trimmed, 'data:image') === 0) {
        if (preg_match('#^data:(image/[^;]+);base64,(.+)$#s', $trimmed, $m)) {
            $mime = $m[1];
            $b64 = trim($m[2]);
        } else {
            // fallback: extrai após vírgula
            $parts = explode(',', $trimmed, 2);
            $b64 = trim(end($parts));
        }
    } else {
        // Verifica se já é string base64 (sem binário)
        $isBase64 = false;
        // Heurística: só chars base64 e tamanho múltiplo de 4
        if (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $blob) && (strlen(trim($blob)) % 4 === 0)) {
            $decoded = base64_decode(trim($blob), true);
            if ($decoded !== false) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $decodedMime = $finfo->buffer($decoded);
                if ($decodedMime && strpos($decodedMime, 'image/') === 0) {
                    $isBase64 = true;
                    $b64 = trim($blob);
                    $mime = $decodedMime;
                }
            }
        }
        if (!$isBase64) {
            // Blob binário puro -> codifica agora
            $b64 = base64_encode($blob);
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->buffer($blob);
                if ($detected && strpos($detected, 'image/') === 0) {
                    $mime = $detected;
                }
            }
        }
    }

    // Usa imagem_nome como filename se existir, senão fallback tratado no frontend
    $filename = $r['imagem_nome'] ?? null;
    if ($filename !== null) $filename = trim($filename);

    jsonResponse(200, ['image' => $b64, 'mime' => $mime, 'filename' => $filename]);
}

// --- Clear Image (excluir imagem sem publicar) ---
function clearImage(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(400, ['error' => 'ID é obrigatório.']);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT imagem, imagem_nome, requested_by_id, writer_id FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);
    }

    // Permissão espelha getRequestImage / updateRequest
    $canClear = false;
    if (in_array($user['role'], ['admin', 'revisor', 'redator'])) {
        $canClear = true;
    } elseif ((int)$r['requested_by_id'] === (int)$user['id'] || (int)$r['writer_id'] === (int)$user['id']) {
        $canClear = true;
    }
    if (!$canClear) {
        jsonResponse(403, ['error' => 'Sem permissão para excluir a imagem desta solicitação.']);
    }

    if ($r['imagem'] === null && $r['imagem_nome'] === null) {
        jsonResponse(200, ['message' => 'Já sem imagem.']);
    }

    $stmt = $db->prepare('UPDATE requests SET imagem = NULL, imagem_nome = NULL WHERE id = ?');
    $stmt->execute([$id]);

    // Log no histórico
    $changes = json_encode([['field' => 'imagem', 'from' => $r['imagem_nome'] ?: 'com imagem', 'to' => null]], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO request_history (request_id, user_id, action, changes) VALUES (?, ?, "edit", ?)');
    $stmt->execute([$id, $user['id'], $changes]);

    jsonResponse(200, ['message' => 'Imagem excluída.']);
}

// Reset Compliance
function resetCompliance(): void
{
    $user = requireAuth();
    $input = getInput();
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        jsonResponse(400, ['error' => 'ID é obrigatório.']);
    }

    $db = getDB();
    $internalFields = getRequestInternalFields();
    $stmt = $db->prepare("SELECT {$internalFields} FROM requests WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r)
        jsonResponse(404, ['error' => 'Solicitação não encontrada.']);

    // Permissão — espelha o updateStatus (administrador, revisor, redator e dono)
    $canReset = in_array($user['role'], ['admin', 'revisor', 'redator'])
        || (int)$r['requested_by_id'] === (int)$user['id'];
    if (!$canReset) {
        jsonResponse(403, ['error' => 'Sem permissão para redefinir compliance.']);
    }

    $stmt = $db->prepare('UPDATE requests SET status_compliance = "nao_analisado", resumo_analise = NULL WHERE id = ?');
    $stmt->execute([$id]);

    jsonResponse(200, ['message' => 'Compliance redefinida.']);
}
