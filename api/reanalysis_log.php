<?php
// ============================================
//  ArticleHub — Reanalysis Log API
//  GET ?date=YYYY-MM-DD&user_id=N -> lista os pedidos de reanálise
//
//  ADMIN-ONLY: o log diz quem pediu o quê e quando. Diferente da Análise Periódica
//  (aberta a todos os perfis), este é um registro de auditoria e fica restrito.
//
//  A tabela periodic_reanalysis_log é escrita por api/periodic_analysis.php
//  (ver ensureReanalysisLogTable lá). Aqui só se lê.
//
//  Cada linha mostra também o RESULTADO do pedido: status_compliance e resumo_analise
//  da linha criada em periodic_analysis. O elo é prl.analysis_id — e não o grupo, porque
//  o grupo pode já ter recebido OUTRA análise depois; o que interessa aqui é o desfecho
//  do pedido registrado nesta linha do log.
//
//  Mostra ainda a ANÁLISE ANTERIOR do mesmo post (prev_*), para comparar antes/depois do
//  pedido. O elo é (dominio, id_post) com id menor que o do pedido — a análise imediatamente
//  anterior, não a última do grupo. Ver o comentário do LEFT JOIN `prev` mais abaixo.
//
//  A linha nasce com status 'nao_analisado' + resumo 'esperando re-analise' e é
//  SOBRESCRITA depois pelo crawler externo (n8n) quando a análise termina. Nada neste
//  projeto escreve o resultado — por isso o front lê o valor atual, sem cache.
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    jsonResponse(405, ['error' => 'Método não permitido.']);
}

listReanalysisLog();

function listReanalysisLog(): void
{
    requireRole('admin');
    $db = getDB();

    // A tabela só nasce no primeiro POST de reanálise (ensureReanalysisLogTable, em
    // api/periodic_analysis.php). Antes disso ela não existe — e devolver lista vazia é
    // mais honesto do que estourar um 500 numa tela de leitura. Mesmo espírito do gate
    // $hasLogTable de lá.
    if (!$db->query("SHOW TABLES LIKE 'periodic_reanalysis_log'")->fetch()) {
        jsonResponse(200, []);
    }

    $where = [];
    $params = [];

    // Filtro de data: dia exato no fuso de São Paulo — o mesmo dia que o front exibe. Sem
    // data = todos os períodos (o front não pré-preenche, porque pedidos de reanálise são
    // esporádicos e um "hoje" fixo mostraria vazio na maioria dos dias).
    // Antes: `DATE(prl.created_at) = ?`, que comparava o dia escolhido em SP com o dia do
    // SERVIDOR em que a linha foi gravada (mesmo bug de api/logs.php) e matava o índice.
    $date = dateParam('date');
    applySaoPauloDayFilter($where, $params, 'prl.created_at', $date);

    $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    if ($userId) {
        $where[] = 'prl.user_id = ?';
        $params[] = $userId;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // LEFT JOIN (e não INNER): se um usuário for excluído, a linha do log sobrevive com o
    // user_id intacto — o INNER a esconderia justamente no caso que a auditoria precisa ver.
    // O teto de 500 é de segurança: o log é append-only e cresce sem limite. A tela avisa
    // quando a resposta vem cheia, para o corte não ser silencioso (ver REANALYSIS_LOG_LIMIT
    // em app.js — os dois números precisam bater).
    //
    // pa é o SEGUNDO LEFT JOIN (por analysis_id): traz o desfecho do pedido. LEFT e não
    // INNER porque analysis_id é NULL nas linhas gravadas antes da coluna existir e a linha
    // pode ter sido apagada em periodic_analysis (não há FK de propósito — ver schema.sql);
    // nenhum dos dois casos pode sumir com a linha do log.
    // COALESCE no id_post/dominio não é necessário: eles já vêm desnormalizados da própria
    // tabela de log, que é a fonte da verdade da auditoria (analysis_id pode apontar para
    // uma linha cujo id_post tenha sido corrigido depois).
    // prev é o TERCEIRO LEFT JOIN: a análise ANTERIOR do mesmo post — a imediatamente mais
    // antiga que a deste pedido. Como isto é um log de REanálise, quase sempre existe uma.
    // O elo é (dominio, id_post) + id menor, e não o grupo solto: o que interessa é a análise
    // que veio ANTES desta, não a última do grupo (o grupo pode ter recebido análises depois).
    // MAX(id) em vez de ORDER BY ... LIMIT 1: id é AUTO_INCREMENT e anda junto com created_at,
    // então o maior id abaixo do atual é exatamente o anterior — e o subselect fica sargável
    // pelo índice (dominio, id_post).
    // `<=>` no id_post porque a coluna é INT NULL ('' viraria 0 na comparação com `=`).
    // analysis_id NULL (linha antiga) ou pedido que é a 1ª análise do post → prev fica NULL,
    // e a tela mostra "—" em vez de inventar uma análise anterior.
    $stmt = $db->prepare(
        "SELECT prl.id, prl.user_id, prl.dominio, prl.id_post, prl.analysis_id,
                prl.action, prl.created_at,
                u.name AS user_name, u.role AS user_role,
                pa.status_compliance, pa.resumo_analise,
                prev.status_compliance AS prev_status_compliance,
                prev.resumo_analise AS prev_resumo_analise,
                prev.created_at AS prev_created_at
         FROM periodic_reanalysis_log prl
         LEFT JOIN users u ON u.id = prl.user_id
         LEFT JOIN periodic_analysis pa ON pa.id = prl.analysis_id
         LEFT JOIN periodic_analysis prev ON prev.id = (
             SELECT MAX(pa2.id)
             FROM periodic_analysis pa2
             WHERE pa2.dominio = prl.dominio
               AND pa2.id_post <=> prl.id_post
               AND pa2.id < prl.analysis_id
         )
         $whereSql
         ORDER BY prl.created_at DESC, prl.id DESC
         LIMIT 500"
    );
    $stmt->execute($params);

    jsonResponse(200, $stmt->fetchAll());
}
