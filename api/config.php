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

// --- Session ---
session_start([
    'cookie_lifetime' => 432000, // 5 dias
    'gc_maxlifetime' => 432000,
]);

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
    }
    return $pdo;
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