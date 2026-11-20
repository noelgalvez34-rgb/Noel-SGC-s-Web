<?php
declare(strict_types=1);
session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'sgc_cooperative');
define('DB_USER', 'root');
define('DB_PASS', '');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function logged_in(): bool { return isset($_SESSION['user']); }
function role(): string { return $_SESSION['user']['role'] ?? ''; }
function require_login(): void {
    if (!logged_in()) { header('Location: index.php?page=login'); exit; }
}
function require_roles(array $roles): void {
    require_login();
    if (!in_array(role(), $roles, true)) { http_response_code(403); exit('Access denied.'); }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid form token. Please go back and try again.');
    }
}
function flash(string $msg, string $type='success'): void { $_SESSION['flash'] = [$msg,$type]; }
function redirect(string $page): never { header('Location: index.php?page='.urlencode($page)); exit; }
function money_qty($n): string { return number_format((float)$n, 2); }
function current_user_id(): int { return (int)($_SESSION['user']['user_id'] ?? 0); }
