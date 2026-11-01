<?php
declare(strict_types=1);

session_name('pytest_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

$config = require dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/functions.php';

try {
    $db = Database::connection($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    $message = $config['app']['env'] === 'production' ? 'Database belum terhubung. Periksa konfigurasi .env.' : $e->getMessage();
    render_error('Konfigurasi belum siap', $message);
    exit;
}
