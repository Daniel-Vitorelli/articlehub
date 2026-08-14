<?php
// ============================================
//  ArticleHub — Auth API
// ============================================
require_once __DIR__ . '/config.php';

$action = getAction();

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check':
        handleCheck();
        break;
    default:
        jsonResponse(400, ['error' => 'Ação inválida.']);
}

function handleLogin(): void
{
    $input = getInput();
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(400, ['error' => 'Email e senha são obrigatórios.']);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, email, password, role, active FROM users WHERE LOWER(email) = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(401, ['error' => 'Email ou senha incorretos.']);
    }

    // Compare password (plain text for now, ready for password_verify later)
    if ($user['password'] !== $password) {
        jsonResponse(401, ['error' => 'Email ou senha incorretos.']);
    }

    if (!$user['active']) {
        jsonResponse(403, ['error' => 'Sua conta está desativada. Contate o administrador.']);
    }

    // Create session
    $sessionUser = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    $_SESSION['user'] = $sessionUser;

    // Load preferences
    $stmt = $db->prepare('SELECT theme, sidebar_collapsed FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $prefs = $stmt->fetch() ?: ['theme' => 'dark', 'sidebar_collapsed' => 0];

    jsonResponse(200, [
        'user' => $sessionUser,
        'preferences' => [
            'theme' => $prefs['theme'],
            'sidebarCollapsed' => (bool)$prefs['sidebar_collapsed'],
        ],
    ]);
}

function handleLogout(): void
{
    $_SESSION = [];
    session_destroy();
    jsonResponse(200, ['message' => 'Logout realizado.']);
}

function handleCheck(): void
{
    if (empty($_SESSION['user'])) {
        jsonResponse(200, ['authenticated' => false]);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT theme, sidebar_collapsed FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $prefs = $stmt->fetch() ?: ['theme' => 'dark', 'sidebar_collapsed' => 0];

    jsonResponse(200, [
        'authenticated' => true,
        'user' => $_SESSION['user'],
        'preferences' => [
            'theme' => $prefs['theme'],
            'sidebarCollapsed' => (bool)$prefs['sidebar_collapsed'],
        ],
    ]);
}