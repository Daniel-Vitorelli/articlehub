<?php
// ============================================
//  ArticleHub — Realtime SSE (todos os usuários)
//  GET /api/realtime.php
//  Mantém conexão SSE e dispara "refresh" quando webhook.php atualiza storage/webhook.json
//  Frontend: new EventSource("api/realtime.php")
// ============================================
require_once __DIR__ . '/config.php';

// Requer estar logado (mesma sessão do polling)
$user = requireAuth();

// Headers SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // desativa buffering nginx
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

// Evita timeout
@set_time_limit(0);
@ini_set('max_execution_time', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('implicit_flush', '1');
@ob_end_clean();

// Arquivo que webhook atualiza
$storageFile = __DIR__ . '/../storage/webhook.json';

// Envia comentário inicial para manter conexão viva e evitar proxy timeout
echo ": connected\n\n";
@ob_flush(); @flush();

// Última versão vista pelo cliente (via Last-Event-ID ou query ?last_version=)
$lastVersion = $_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last_version'] ?? '';
if (!$lastVersion && file_exists($storageFile)) {
    $existing = @json_decode(@file_get_contents($storageFile), true);
    $lastVersion = $existing['version'] ?? '';
}

// Envia evento inicial com dados atuais (para cliente já renderizar sem esperar webhook)
$initialData = [
    'event' => 'connected',
    'version' => $lastVersion,
    'user_id' => $user['id'],
    'time' => date('c'),
];
echo "id: " . ($lastVersion ?: time()) . "\n";
echo "event: connected\n";
echo "data: " . json_encode($initialData, JSON_UNESCAPED_UNICODE) . "\n\n";
@ob_flush(); @flush();

$startTime = time();
$maxDuration = 30; // reconecta a cada 30s (EventSource reconecta automaticamente)
$heartbeatInterval = 15; // envia : heartbeat a cada 15s para manter vivo
$lastHeartbeat = time();

// Função auxiliar para ler versão atual do storage (cria arquivo se não existir)
function readWebhookVersion($storageFile) {
    if (!file_exists($storageFile)) {
        // Cria arquivo inicial para evitar filemtime/version nulo
        $initial = [
            'version' => 'init-' . time(),
            'event' => 'init',
            'payload' => null,
            'triggered_at' => date('c'),
        ];
        @file_put_contents($storageFile, json_encode($initial, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $initial;
    }
    $content = @file_get_contents($storageFile);
    $data = @json_decode($content, true);
    if (!$data || !isset($data['version'])) {
        return ['version' => 'init-' . time(), 'event' => 'init', 'payload' => null, 'triggered_at' => date('c')];
    }
    return $data;
}

// Garante que storage existe antes do loop
if (!is_dir(dirname($storageFile))) {
    @mkdir(dirname($storageFile), 0775, true);
}
$currentData = readWebhookVersion($storageFile);
$lastVersion = $currentData['version'] ?? $lastVersion;

while (true) {
    // Timeout para reconexão limpa (evita PHP ficar preso para sempre)
    if (time() - $startTime >= $maxDuration) {
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['time' => date('c')], JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush(); @flush();
        break;
    }

    // Heartbeat periódico
    if (time() - $lastHeartbeat >= $heartbeatInterval) {
        echo ": heartbeat\n\n";
        @ob_flush(); @flush();
        $lastHeartbeat = time();
    }

    clearstatcache(true, $storageFile);
    $currentData = readWebhookVersion($storageFile);
    $currentVersion = $currentData['version'] ?? '';

    if ($currentVersion !== $lastVersion) {
        $lastVersion = $currentVersion;
        $payload = [
            'event' => $currentData['event'] ?? 'refresh',
            'version' => $currentVersion,
            'payload' => $currentData['payload'] ?? null,
            'triggered_at' => $currentData['triggered_at'] ?? date('c'),
        ];
        echo "id: $currentVersion\n";
        echo "event: refresh\n";
        echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush(); @flush();
    }

    // Verifica se cliente desconectou
    if (connection_aborted()) {
        break;
    }

    // Poll leve a cada 1s (mais rápido que polling 15s, mas sem query no banco)
    sleep(1);
}
