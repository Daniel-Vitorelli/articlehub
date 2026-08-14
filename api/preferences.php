<?php
// ============================================
//  ArticleHub — User Preferences API
// ============================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getPreferences();
        break;
    case 'PUT':
        savePreferences();
        break;
    default:
        jsonResponse(405, ['error' => 'Método não permitido.']);
}

function getPreferences(): void
{
    $user = requireAuth();
    $db = getDB();
    $stmt = $db->prepare('SELECT theme, sidebar_collapsed FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $prefs = $stmt->fetch();

    if (!$prefs) {
        $prefs = ['theme' => 'dark', 'sidebar_collapsed' => 0];
    }

    jsonResponse(200, [
        'theme' => $prefs['theme'],
        'sidebarCollapsed' => (bool)$prefs['sidebar_collapsed'],
    ]);
}

function savePreferences(): void
{
    $user = requireAuth();
    $input = getInput();
    $db = getDB();

    $theme = $input['theme'] ?? null;
    $sidebarCollapsed = isset($input['sidebarCollapsed']) ? (int)(bool)$input['sidebarCollapsed'] : null;

    // Upsert
    $stmt = $db->prepare('SELECT user_id FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$user['id']]);

    if ($stmt->fetch()) {
        $sets = [];
        $params = [];
        if ($theme !== null) {
            $sets[] = 'theme = ?';
            $params[] = $theme;
        }
        if ($sidebarCollapsed !== null) {
            $sets[] = 'sidebar_collapsed = ?';
            $params[] = $sidebarCollapsed;
        }
        if (empty($sets))
            jsonResponse(200, ['message' => 'Nenhuma alteração.']);
        $params[] = $user['id'];
        $stmt = $db->prepare('UPDATE user_preferences SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $stmt->execute($params);
    }
    else {
        $stmt = $db->prepare('INSERT INTO user_preferences (user_id, theme, sidebar_collapsed) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $theme ?? 'dark', $sidebarCollapsed ?? 0]);
    }

    jsonResponse(200, ['message' => 'Preferências salvas.']);
}