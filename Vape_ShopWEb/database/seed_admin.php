<?php
/**
 * VAPE CO. — first admin account.
 * Vape_ShopWEb / database / seed_admin.php
 *
 * Creates the administrator account that can sign in at admin/login.php.
 *
 * THIS SCRIPT IS A COMMAND-LINE TOOL. It refuses to run through a browser.
 *
 * USAGE
 *   C:\xampp\php\php.exe database\seed_admin.php admin@vapeco.ph "Admin@123" "Cristobal" "Admin"
 *
 *   Optional arguments:
 *       [birthdate]   YYYY-MM-DD  (default 1990-01-01 — must be 21 or older)
 *       [phone]       contact number (default: none)
 *
 *   Re-set the password of an existing account:
 *       C:\xampp\php\php.exe database\seed_admin.php admin@vapeco.ph "NewPass@123" --force
 *
 * The password is hashed with PHP's password_hash() — the same function the
 * sign-in pages use to check it. The plain password is never written to the
 * database and never printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';   // for is_under_21()

/* ---------- Arguments ---------- */

$argvList = $argv ?? [];
$args     = array_values(array_filter(
    array_slice($argvList, 1),          // drop the script name
    static fn($a) => !str_starts_with($a, '--')
));

$force = in_array('--force', $argvList, true);

$email     = trim((string) ($args[0] ?? ''));
$password  = (string) ($args[1] ?? '');
$firstName = trim((string) ($args[2] ?? 'Admin'));
$lastName  = trim((string) ($args[3] ?? 'Store'));
$birthdate = trim((string) ($args[4] ?? '1990-01-01'));
$phone     = trim((string) ($args[5] ?? ''));

function out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function fail(string $message): void
{
    out();
    out('ERROR: ' . $message);
    exit(1);
}

/* ---------- Validate ---------- */

if ($email === '' || $password === '') {
    out('Usage:');
    out('  C:\xampp\php\php.exe database\seed_admin.php <email> <password> [firstName] [lastName] [birthdate] [phone]');
    out();
    out('Example:');
    out('  C:\xampp\php\php.exe database\seed_admin.php admin@vapeco.ph "Admin@123" "Cristobal" "Admin"');
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('"' . $email . '" is not a valid email address.');
}

if (strlen($password) < 8) {
    fail('The password must be at least 8 characters long.');
}

$dob = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
if ($dob === false || $dob->format('Y-m-d') !== $birthdate) {
    fail('The birthdate must be a real date in YYYY-MM-DD format.');
}

if (is_under_21($birthdate)) {
    fail('The birthdate ' . $birthdate . ' means the account holder is under ' . MIN_AGE . '.');
}

if ($firstName === '' || $lastName === '') {
    fail('The first and last name are required.');
}

/* ---------- Look the role up by NAME, never by a hard-coded id ---------- */

$pdo = db();

$roleStmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'admin' LIMIT 1");
$roleStmt->execute();
$role = $roleStmt->fetch();

if (!$role) {
    fail("The 'admin' role does not exist. Import database/schema.sql first.");
}

$roleId = (int) $role['id'];

/* ---------- Create or refuse ---------- */

$findUser = $pdo->prepare('SELECT id, role_id FROM users WHERE email = ? LIMIT 1');
$findUser->execute([$email]);
$existing = $findUser->fetch();

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existing) {
    if (!$force) {
        fail('An account with the email "' . $email . '" already exists. Use --force to reset its password.');
    }

    $update = $pdo->prepare(
        'UPDATE users
            SET password_hash = ?, role_id = ?, first_name = ?, last_name = ?,
                birthdate = ?, phone = ?, is_active = 1, blocked_reason = NULL, blocked_at = NULL
          WHERE id = ?'
    );
    $update->execute([
        $hash,
        $roleId,
        $firstName,
        $lastName,
        $birthdate,
        $phone !== '' ? $phone : null,
        (int) $existing['id'],
    ]);

    out();
    out('Admin account updated.');
    out('  id       : ' . $existing['id']);
    out('  email    : ' . $email);
    out('  name     : ' . $firstName . ' ' . $lastName);
    out('  role     : admin');
    out('  password : changed');
    out();
    out('Sign in at: admin/login.php  (inside this project folder)');
    out();
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO users
        (role_id, email, password_hash, first_name, last_name, phone, birthdate, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
);
$insert->execute([
    $roleId,
    $email,
    $hash,
    $firstName,
    $lastName,
    $phone !== '' ? $phone : null,
    $birthdate,
]);

out();
out('Admin account created.');
out('  id       : ' . $pdo->lastInsertId());
out('  email    : ' . $email);
out('  name     : ' . $firstName . ' ' . $lastName);
out('  role     : admin');
out('  birthdate: ' . $birthdate);
out();
out('Sign in at: admin/login.php  (inside this project folder)');
out();
