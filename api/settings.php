<?php
// ============================================
//  ArticleHub — App Settings API
//  GET: qualquer usuário autenticado (leitura)
//  PUT: apenas admin (escrita)
// ============================================
require_once __DIR__ . '/config.php';

// Garante a tabela em bancos criados antes desta feature
// (schema.sql só roda no primeiro init do container).
function ensureSettingsTable(): void
{
    $db = getDB();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (' .
        '  `key` VARCHAR(100) NOT NULL PRIMARY KEY,' .
        '  `value` TEXT NOT NULL,' .
        "  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Seed do limiar de confirmação da reanálise em massa (não sobrescreve valor existente)
    $stmt = $db->prepare('INSERT IGNORE INTO app_settings (`key`, `value`) VALUES (?, ?)');
    $stmt->execute(['bulk_confirm_threshold', '10']);
    $stmt->execute(['session_lifetime', '432000']); // 5 dias
}

// Chaves editáveis + validadores (tipo, min, max)
function settingRules(): array
{
    return [
        'bulk_confirm_threshold' => ['type' => 'int', 'min' => 1, 'max' => 10000],
        'session_lifetime' => ['type' => 'int', 'min' => 300, 'max' => 2592000], // 5 min a 30 dias, em segundos
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        listSettings();
        break;
    case 'PUT':
        updateSetting();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function listSettings(): void
{
    requireAuth();
    ensureSettingsTable();
    $db = getDB();
    $stmt = $db->query('SELECT `key`, `value` FROM app_settings');
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['key']] = $row['value'];
    }
    // (object) garante `{}` em vez de `[]` quando vazio
    jsonResponse(200, (object)$out);
}

function updateSetting(): void
{
    requireRole('admin');
    ensureSettingsTable();
    $input = getInput();

    $key = trim($input['key'] ?? '');
    $rules = settingRules();
    if (!isset($rules[$key])) {
        jsonResponse(400, ['error' => 'Chave de configuração inválida.']);
    }

    $rule = $rules[$key];
    $value = $input['value'] ?? null;
    if ($rule['type'] === 'int') {
        if (!is_numeric($value) || (int)$value != $value) {
            jsonResponse(400, ['error' => 'Valor deve ser um número inteiro.']);
        }
        $value = (int)$value;
        if ($value < $rule['min'] || $value > $rule['max']) {
            jsonResponse(400, ['error' => "Valor deve estar entre {$rule['min']} e {$rule['max']}."]);
        }
        $value = (string)$value;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ' .
        'ON DUPLICATE KEY UPDATE `value` = ?'
    );
    $stmt->execute([$key, $value, $value]);

    jsonResponse(200, ['message' => 'Configuração salva.', 'key' => $key, 'value' => $value]);
}
