<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function json_out(array $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json(): mixed
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    try {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function render(string $template, array $data = []): never
{
    $csrfToken = csrf_token();
    extract($data, EXTR_SKIP);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    include dirname(__DIR__) . '/templates/layout.php';
    exit;
}

function client_address(): string
{
    $address = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($address) && $address !== '' ? $address : 'unknown';
}

function consume_rate_limit(string $key, int $limit, int $windowMs): array
{
    $path = dirname(__DIR__) . '/storage/rate-limit.json';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return ['allowed' => true];
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $store = $raw ? json_decode($raw, true) : [];
    if (!is_array($store)) {
        $store = [];
    }
    $now = (int) (microtime(true) * 1000);
    $recent = array_values(array_filter($store[$key] ?? [], static fn ($stamp): bool => $now - (int) $stamp < $windowMs));
    if (count($recent) >= $limit) {
        $oldest = $recent[0] ?? $now;
        $retry = max(1, (int) ceil(($windowMs - ($now - $oldest)) / 1000));
        $store[$key] = $recent;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($store));
        flock($handle, LOCK_UN);
        fclose($handle);
        return ['allowed' => false, 'retryAfterSeconds' => $retry];
    }
    $recent[] = $now;
    $store[$key] = $recent;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($store));
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['allowed' => true];
}

function require_api_user(): array
{
    $user = current_user();
    if (!$user) {
        json_out(['error' => 'Oturum yok.'], 401);
    }
    return $user;
}

function is_uuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

function clean_text(mixed $value, int $min, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $text = trim($value);
    $length = text_length($text);
    if ($length < $min || $length > $max) {
        return null;
    }
    return $text;
}

function clean_email(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $email = strtolower(trim($value));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

ini_set('display_errors', '0');
load_env(dirname(__DIR__, 2) . '/.env');

function cookie_base(): array
{
    return [
        'path' => '/',
        'secure' => ($_ENV['APP_ENV'] ?? '') === 'production',
        'samesite' => 'Lax',
    ];
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests");
}

function csrf_token(): string
{
    $token = $_COOKIE['bt_csrf'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        setcookie('bt_csrf', $token, cookie_base() + [
            'expires' => time() + 14 * 24 * 60 * 60,
            'httponly' => false,
        ]);
        $_COOKIE['bt_csrf'] = $token;
    }
    return $token;
}

function require_csrf(): void
{
    $cookie = $_COOKIE['bt_csrf'] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($cookie) || !is_string($header) || $cookie === '' || !hash_equals($cookie, $header)) {
        json_out(['error' => 'Oturum doğrulanamadı. Sayfayı yenileyin.'], 403);
    }
}

send_security_headers();
