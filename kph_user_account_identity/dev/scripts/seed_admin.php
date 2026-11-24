<?php
// ============================================================
// KameraPH | Module 1 | Ecommerce | Admin account seeder
// ============================================================
// Idempotently creates (or repairs) the admin account:
//
//   admin@kameraph.test   /   Admin123!
//
// It registers through the same User::register() path used by
// the public API, then grants the admin role (3) and clears any
// leftover suspension so the account is usable immediately.
//
// Usage (XAMPP / Windows):
//   C:\xampp\php\php.exe seed_admin.php
// ============================================================

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/UserRole.php';
require_once __DIR__ . '/../../classes/Role.php';

$email    = 'admin@kameraph.test';
$password = 'Admin123!';

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("SELECT UserID FROM `user` WHERE Email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $userId = (int)$existing['UserID'];
        echo "Admin user already exists (UserID $userId).\n";
    } else {
        $users = new User();
        $userId = $users->register([
            'FirstName'    => 'Kamera',
            'LastName'     => 'Admin',
            'Email'        => $email,
            'PhoneNumber'  => '09991234567',
            'Password'     => $password,
            'Username'     => 'admin',
        ]);
        echo "Created admin user (UserID $userId).\n";
    }

    $roles = new UserRole();
    if (!$roles->hasRole($userId, 'admin')) {
        $roles->assignByName($userId, 'admin');
        echo "Granted admin role.\n";
    } else {
        echo "Admin role already present.\n";
    }

    $stmt = $db->prepare("UPDATE `user` SET Status = 'Active', SuspensionReason = NULL WHERE UserID = ?");
    $stmt->execute([$userId]);
    if ($stmt->rowCount() > 0) {
        echo "Cleared suspension flags.\n";
    }

    echo "Done: $email / $password\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Seeding failed: " . $e->getMessage() . "\n");
    exit(1);
}