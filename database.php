<?php
declare(strict_types=1);

function pm_db_enabled(): bool {
    return trim((string)getenv('DATABASE_URL')) !== '';
}

function pm_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $url = trim((string)getenv('DATABASE_URL'));
    if ($url === '') throw new RuntimeException('DATABASE_URL is not configured.');
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL is malformed. Use the Internal Database URL from Render.');
    }
    $database = ltrim(rawurldecode($parts['path']), '/');
    $user = rawurldecode((string)($parts['user'] ?? ''));
    $password = rawurldecode((string)($parts['pass'] ?? ''));
    $port = (int)($parts['port'] ?? 5432);
    parse_str((string)($parts['query'] ?? ''), $query);
    $sslmode = (string)($query['sslmode'] ?? 'require');
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $parts['host'], $port, $database, $sslmode);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
