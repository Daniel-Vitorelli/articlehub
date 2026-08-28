<?php
// ============================================
//  ArticleHub — Webhook (externo → atualiza todos)
//  POST /api/webhook.php?secret=XXX  ou  Header X-Webhook-Secret: XXX
//  Body opcional: { "event": "requests_updated", "ids": [1,2] }
//  Ao receber, grava versão em storage/webhook.json que o SSE (realtime.php) observa
// ============================================
require_once __DIR__ . '/config.php';

// CORS para chamada externa
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['error' => 'Método não permitido. Use POST.']);
}

// Secret: env WEBHOOK_SECRET ou fallback (trocar em produção via docker-compose env)
$expectedSecret = getenv('WEBHOOK_SECRET') ?: 'articlehub-webhook-2026';
$providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
// Também aceita Authorization: Bearer <secret>
if (!$providedSecret && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $providedSecret = trim($m[1]);
    }
}
if ($providedSecret !== $expectedSecret) {
    jsonResponse(401, ['error' => 'Secret inválido. Envie ?secret=XXX ou header X-Webhook-Secret.']);
}

$input = getInput();
$event = $input['event'] ?? 'refresh';
$payload = $input;

// Gera versão - timestamp + random para garantir mudança mesmo em chamadas rápidas
$version = time() . '-' . bin2hex(random_bytes(4));
$data = [
    'version' => $version,
    'event' => $event,
    'payload' => $payload,
    'triggered_at' => date('c'),
    'triggered_by' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
];

// Garante diretório storage
$storageDir = __DIR__ . '/../storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
$storageFile = $storageDir . '/webhook.json';
@file_put_contents($storageFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
// Também atualiza mtime explicitamente
@touch($storageFile);

// Opcional: log em arquivo
@file_put_contents($storageDir . '/webhook.log', date('c') . " webhook {$event} version {$version} from " . ($_SERVER['REMOTE_ADDR'] ?? '-') . PHP_EOL, FILE_APPEND | LOCK_EX);

jsonResponse(200, [
    'success' => true,
    'version' => $version,
    'event' => $event,
    'message' => 'Webhook recebido. Todos os clientes conectados serão atualizados.',
]);
