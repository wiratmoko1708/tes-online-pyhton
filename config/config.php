<?php
declare(strict_types=1);

function load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv(trim($key)) === false) {
            putenv(trim($key) . '=' . $value);
            $_ENV[trim($key)] = $value;
        }
    }
}

load_env(dirname(__DIR__) . '/.env');

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === '' ? $default : (string) $value;
}

return [
    'app' => [
        'name' => env_value('APP_NAME', 'PyTest SMA'),
        'env' => env_value('APP_ENV', 'production'),
        'url' => rtrim(env_value('APP_URL', ''), '/'),
    ],
    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => env_value('DB_PORT', '3306'),
        'database' => env_value('DB_DATABASE', 'ujian_python'),
        'username' => env_value('DB_USERNAME', 'pytest'),
        'password' => env_value('DB_PASSWORD', 'password_kuat'),
    ],
    'python_bin' => env_value('PYTHON_BIN', 'python3'),
    'ai' => [
        'base_url' => rtrim(env_value('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'model' => env_value('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env_value('AI_API_KEY'),
    ],
];
