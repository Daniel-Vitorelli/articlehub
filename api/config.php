<?php
// ============================================
//  ArticleHub — API Configuration
// ============================================

// --- CORS Headers ---
// Mesmo-origem (o app usa fetch relativo "api/..."): cookies de sessão vão
// automaticamente, sem precisar de ACA-Credentials. Não enviar
// "Allow-Origin: *" junto com "Allow-Credentials: true" (combinação inválida
// que o browser rejeita no EventSource/fetch com credenciais).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- Environment Configuration ---
// Lê a variável de ambiente do Docker (APP_ENV). Se não existir, assume 'prod' por segurança.
$env = getenv('APP_ENV') ?: 'prod';

if ($env === 'dev') {
    // Ambiente de Desenvolvimento (ahteste.ai-equinox.com)
    define('DB_HOST', getenv('DB_HOST') ?: '5.189.166.47');
    define('DB_NAME', getenv('DB_NAME') ?: 'ahteste');
    define('DB_USER', getenv('DB_USER') ?: 'usr_ahteste');
    define('DB_PASS', getenv('DB_PASS') ?: 'zu6Y.x6ZMd10BaQI');
} else {
    // Ambiente de Produção (articlehub.ai-equinox.com)
    define('DB_HOST', getenv('DB_HOST') ?: '5.189.166.47');
    define('DB_NAME', getenv('DB_NAME') ?: 'articlehub');
    define('DB_USER', getenv('DB_USER') ?: 'usr_articlehub');
    define('DB_PASS', getenv('DB_PASS') ?: 'Z9bnWlyAp[PK59sY');
}

// --- Session ---
// Lê a duração da sessão configurável pelo admin (app_settings.session_lifetime).
// Se a tabela ainda não existir ou o valor for inválido, usa o padrão de 5 dias.
function sessionLifetime(): int
{
    $default = 432000; // 5 dias
    try {
        $stmt = getDB()->query("SELECT `value` FROM app_settings WHERE `key` = 'session_lifetime'");
        $val = $stmt->fetchColumn();
        if ($val !== false && is_numeric($val)) {
            $n = (int)$val;
            if ($n >= 300 && $n <= 31536000) return $n;
        }
    }
    catch (PDOException $e) {
        // Tabela ainda não criada — usa o padrão.
    }
    return $default;
}

$sessionLifetime = sessionLifetime();
session_start([
    'cookie_lifetime' => $sessionLifetime,
    'gc_maxlifetime' => $sessionLifetime,
]);

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
                );
        }
        catch (PDOException $e) {
            jsonResponse(500, ['error' => 'Erro de conexão com o banco de dados.']);
            exit;
        }
        checkConnectionTimezone($pdo);
    }
    return $pdo;
}

// ============================================
//  Fuso do BANCO — descoberto, nunca assumido
// ============================================
// O PDO NÃO define `time_zone` na conexão: o MySQL fica no relógio do SISTEMA do servidor
// de banco. Isso é o correto para este projeto (todas as colunas de evento saem do mesmo
// relógio, via NOW()/CURRENT_TIMESTAMP), mas significa que o PHP precisa SABER qual é esse
// relógio para converter corretamente um dia de São Paulo no intervalo a filtrar.
//
// `date_default_timezone_set()` também NÃO é chamado de propósito: deixar o PHP no fuso do
// sistema e só converter explicitamente (via DateTimeZone) evita que um `date()` distraído
// passe a gravar um horário que o banco não vai bater.

/** Cache do nome do fuso do banco; false = ainda não tentou. */
function dbTimezoneName(): string
{
    static $name = false;
    if ($name !== false) {
        return $name;
    }
    $name = 'UTC'; // default do MySQL quando não configurado — premissa conservadora
    try {
        $db = getDB();
        // Tenta o nome IANA (ex.: 'America/Sao_Paulo')...
        $tz = @$db->query('SELECT @@session.time_zone')->fetchColumn();
        if (is_string($tz) && $tz !== '' && strtoupper($tz) !== 'SYSTEM') {
            $name = $tz;
            return $name;
        }
        // ...se for 'SYSTEM', o MySQL só expõe o NOME se a tabela de timezones estiver
        // populada. Sem ela, o offset é tudo o que existe — e offset é suficiente:
        // o PHP constrói um DateTimeZone de offset fixo naquele instante.
        $offset = $db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        if ($offset !== false && $offset !== null) {
            $name = sprintf('%+03d:%02d', intdiv((int)$offset, 3600), abs((int)$offset % 3600) / 60);
        }
    }
    catch (Exception $e) {
        // sem resposta: mantém 'UTC' (default documentado do MySQL)
    }
    return $name;
}

/**
 * Fuso do banco como DateTimeZone. Prefere o nome IANA (acompanha horário de verão
 * histórico); se só houver offset, usa um fuso de offset fixo (suficiente para o dia
 * corrente, que é o que este código precisa resolver).
 */
function dbTimezone(): DateTimeZone
{
    static $zone = null;
    if ($zone !== null) {
        return $zone;
    }
    $name = getenv('APP_DB_TIMEZONE') ?: dbTimezoneName();
    try {
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $name)) {
            $zone = new DateTimeZone($name); // offset fixo, ex.: '+03:00'
        }
        else {
            $zone = new DateTimeZone($name); // nome IANA
        }
    }
    catch (Exception $e) {
        $zone = new DateTimeZone('UTC');
    }
    return $zone;
}

/**
 * Confere, na abertura da conexão, se o PHP sabe traduzir o fuso anunciado pelo MySQL.
 * Se não souber (ex.: banco em 'Europe/Berlin' e a base do PHP sem essa entrada), avisa
 * no log em vez de converter errado silenciosamente. Não interrompe a requisição.
 */
function checkConnectionTimezone(PDO $db): void
{
    try {
        $tz  = @$db->query('SELECT @@session.time_zone')->fetchColumn();
        $off = $db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        $name = (is_string($tz) && $tz !== '' && strtoupper($tz) !== 'SYSTEM') ? $tz : null;
        if ($name !== null && !in_array($name, DateTimeZone::listIdentifiers(), true)) {
            error_log("ArticleHub: MySQL anuncia time_zone '{$name}', que o PHP não conhece. "
                . "A conversão de dia vai usar o offset {$off}s como fallback.");
        }
    }
    catch (Exception $e) {
        // diagnóstico best-effort; nunca derruba a conexão
    }
}

// --- Helpers ---
function jsonResponse(int $code, $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(): array
{
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

function requireAuth(): array
{
    if (empty($_SESSION['user'])) {
        jsonResponse(401, ['error' => 'Não autenticado.']);
    }
    return $_SESSION['user'];
}

function requireRole(string...$roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles)) {
        jsonResponse(403, ['error' => 'Sem permissão.']);
    }
    return $user;
}

function getAction(): string
{
    return $_GET['action'] ?? '';
}

// ============================================
//  Fuso horário — conversão de "dia" para intervalo no relógio do banco
// ============================================
// O modelo de tempo do projeto: colunas de evento (created_at etc.) são gravadas pelo
// MySQL com DEFAULT CURRENT_TIMESTAMP / NOW(), ou seja, no relógio do SERVIDOR de banco.
// O display no front é sempre em America/Sao_Paulo. Como o servidor NÃO está em São Paulo,
// filtrar por dia é a fronteira entre os dois fusos — e é onde nasce a confusão.
//
// `DATE(coluna) = 'YYYY-MM-DD'` estava errado: compara o dia de São Paulo que o usuário
// escolheu com o dia do SERVIDOR em que o evento foi gravado. Sempre que os dois calendários
// divergem (um log de 21:30 em SP vira 00:30 do dia seguinte no servidor), a linha desaparece
// da tela. Pior: `DATE(coluna)` mata o índice (não é sargável).
//
// A solução é a mesma de presenceDateFilter(): intervalo HALF-OPEN [início, fim) montado com
// valores já convertidos, que continua usando o índice. A conversão é feita AQUI, no PHP, e
// não com CONVERT_TZ(), de propósito: CONVERT_TZ depende de a tabela mysql.time_zone_name
// estar populada no servidor, o que não é garantido. Aqui a única dependência é o banco de
// fusos do PHP (DateTimeZone), que está sempre disponível.
const DISPLAY_TIMEZONE = 'America/Sao_Paulo';

/**
 * Converte o offset de São Paulo naquele dia específico para segundos.
 * Não é constante fixa (-3h) de propósito: se o horário de verão voltar, o valor
 * acompanha sozinho. A conversão é por DIA (não por "agora") justamente para acertar
 * o dia certo quando a regra mudar no meio do período.
 */
function saoPauloOffsetSeconds(string $ymd): int
{
    try {
        $tz = new DateTimeZone(DISPLAY_TIMEZONE);
        $dt = new DateTime($ymd . ' 12:00:00', $tz);
        return $dt->getOffset();
    }
    catch (Exception $e) {
        // -3h: desde 2019 São Paulo não tem mais horário de verão, então este é o valor
        // correto na prática. Só cai aqui se o banco de fusos do PHP estiver ausente.
        return -3 * 3600;
    }
}

/**
 * Valida um parâmetro de data 'YYYY-MM-DD'. Devolve null se ausente ou inválido.
 * Mesmo contrato de presenceDateParam().
 */
function dateParam(string $key): ?string
{
    $raw = trim((string)($_GET[$key] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $raw;
}

/**
 * Traduz um dia no fuso de EXIBIÇÃO (São Paulo) no intervalo half-open correspondente
 * no relógio em que a coluna foi gravada. Devolve [$inicio, $fim] como strings
 * 'YYYY-MM-DD HH:MM:SS', ou null se $dia for inválido.
 *
 * POR QUE NÃO PRECISA CONVERTER NADA AQUI: o PDO conecta sem `SET time_zone`, então o
 * MySQL opera no relógio do SISTEMA e devolve a mesma string que gravou. A coluna guarda
 * o WALL CLOCK do servidor, e o MySQL faz a aritmética (NOW(), CURRENT_TIMESTAMP) também
 * nele. Logo, comparar `coluna >= 'literal'` é comparar wall clock com wall clock.
 * O que precisamos é o wall clock do servidor que corresponde a 00:00 em São Paulo.
 *
 * Como o PDO está configurado (checkConnectionTimezone, no fim deste arquivo), o objeto
 * de data do PHP ENXERGA o fuso do banco — daí `setTimezone()` resolver a conta sozinho,
 * inclusive em horário de verão. Se o fuso do banco não pôde ser determinado, o fallback
 * assume que o banco está em UTC (o default do MySQL) e a conta continua correta.
 */
function saoPauloDayRange(?string $dia): ?array
{
    if ($dia === null) {
        return null;
    }
    try {
        $tz     = new DateTimeZone(DISPLAY_TIMEZONE);
        $dbTz   = dbTimezone();
        $start  = new DateTime($dia . ' 00:00:00', $tz);
        $end    = new DateTime($dia . ' 00:00:00', $tz);
        $end->modify('+1 day');
        // Mesmo instante, reexpresso no fuso em que a coluna foi gravada.
        $start->setTimezone($dbTz);
        $end->setTimezone($dbTz);
    }
    catch (Exception $e) {
        return null;
    }
    return [
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
    ];
}

/**
 * Adiciona ao WHERE o filtro de um dia (fuso de São Paulo) sobre uma coluna de evento.
 * Half-open: coluna >= início AND coluna < fim. Sargável — o índice da coluna é usado.
 */
function applySaoPauloDayFilter(array &$where, array &$params, string $column, ?string $dia): void
{
    $range = saoPauloDayRange($dia);
    if ($range === null) {
        return;
    }
    $where[]  = "$column >= ?";
    $params[] = $range[0];
    $where[]  = "$column < ?";
    $params[] = $range[1];
}

/**
 * "Hoje" no fuso de São Paulo, derivado do relógio do BANCO (fonte de verdade do projeto),
 * e não do relógio do container PHP. Devolve 'YYYY-MM-DD'.
 *
 * É este valor que preenche o filtro de Logs quando o cliente não manda data: assim o dia
 * que a tela mostra e o dia que o backend filtra saem do MESMO relógio.
 */
function saoPauloToday(PDO $db): string
{
    try {
        $nowTs = (int)$db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    }
    catch (Exception $e) {
        $nowTs = time(); // sem banco, cai no relógio do PHP (último recurso)
    }
    try {
        $dt = new DateTime('@' . $nowTs);
        $dt->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE));
        return $dt->format('Y-m-d');
    }
    catch (Exception $e) {
        return gmdate('Y-m-d', $nowTs);
    }
}

/**
 * Strip accents and lowercase for duplicate comparison.
 * "Português" → "portugues", "Finanças" → "financas"
 */
function normalizeStr(string $str): string
{
    $str = trim($str);
    // Transliterate accented chars to ASCII
    if (function_exists('transliterator_transliterate')) {
        $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
    }
    else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }
    return mb_strtolower($str, 'UTF-8');
}

/**
 * Check for duplicate records in a table.
 * Uses accent-insensitive, case-insensitive comparison via PHP normalizeStr.
 * Returns true if a duplicate exists.
 */
function checkDuplicate(PDO $db, string $table, string $column, string $value, ?int $excludeId = null): bool
{
    $normalized = normalizeStr($value);
    $stmt = $db->query("SELECT id, {$column} FROM {$table}");
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        if ($excludeId && (int)$row['id'] === $excludeId)
            continue;
        if (normalizeStr($row[$column]) === $normalized)
            return true;
    }
    return false;
}