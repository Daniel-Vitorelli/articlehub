<?php
// Quick DB connection test
require_once __DIR__ . '/api/config.php';

echo "Testing database connection...\n";
try {
    $db = getDB();
    echo "✅ Connection successful!\n\n";

    // Test users table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM users');
    $r = $stmt->fetch();
    echo "Users: {$r['cnt']}\n";

    // Test domains table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM domains');
    $r = $stmt->fetch();
    echo "Domains: {$r['cnt']}\n";

    // Test requests table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM requests');
    $r = $stmt->fetch();
    echo "Requests: {$r['cnt']}\n";

    // Test notifications table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM notifications');
    $r = $stmt->fetch();
    echo "Notifications: {$r['cnt']}\n";

    // Test messages table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM messages');
    $r = $stmt->fetch();
    echo "Messages: {$r['cnt']}\n";

    // Test user_preferences table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM user_preferences');
    $r = $stmt->fetch();
    echo "User Preferences: {$r['cnt']}\n";

    // Test request_history table
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM request_history');
    $r = $stmt->fetch();
    echo "Request History: {$r['cnt']}\n";

    echo "\n✅ All tables accessible!\n";

    // List users
    echo "\n--- Users ---\n";
    $stmt = $db->query('SELECT id, name, email, role, active FROM users');
    while ($row = $stmt->fetch()) {
        echo "  [{$row['id']}] {$row['name']} ({$row['email']}) - {$row['role']} - " . ($row['active'] ? 'Active' : 'Inactive') . "\n";
    }

}
catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}