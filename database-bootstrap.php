<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

if (!pm_db_enabled()) {
    fwrite(STDOUT, "DATABASE_URL is not set; keeping local JSON storage.\n");
    exit(0);
}

// One-time seed ships with the application image so GitHub -> Render deploys can
// initialize an empty PostgreSQL database without a manual disk upload.
// Once imported, PostgreSQL is authoritative and later deploys skip this file.
$jsonPath = getenv('PM_MIGRATION_JSON') ?: (__DIR__ . '/pm_schedule_data.json');
$pdo = pm_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS pm_app_state (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    data JSONB NOT NULL,
    revision BIGINT NOT NULL DEFAULT 1,
    source_sha256 TEXT,
    imported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)");

$existing = $pdo->query('SELECT revision FROM pm_app_state WHERE id = 1')->fetch();
if ($existing) {
    fwrite(STDOUT, "PostgreSQL application data already exists; import skipped to prevent overwriting newer records.\n");
    exit(0);
}
if (!is_file($jsonPath)) {
    throw new RuntimeException('No PostgreSQL state and no bundled migration JSON were found at ' . $jsonPath . '. Refusing to initialize empty data.');
}
$raw = file_get_contents($jsonPath);
if ($raw === false || $raw === '') throw new RuntimeException('Bundled migration JSON is empty or unreadable. Refusing migration.');
$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !isset($decoded['equipment'], $decoded['users'], $decoded['logs'])) {
    throw new RuntimeException('Bundled migration JSON does not have the expected application sections. Refusing migration.');
}
$hash = hash('sha256', $raw);
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO pm_app_state (id, data, revision, source_sha256) VALUES (1, CAST(:data AS JSONB), 1, :hash) ON CONFLICT (id) DO NOTHING');
    $stmt->execute([':data' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':hash' => $hash]);
    $check = $pdo->query('SELECT data, jsonb_typeof(data) AS data_type, revision FROM pm_app_state WHERE id = 1')->fetch();
    if (!$check || $check['data_type'] !== 'object') throw new RuntimeException('PostgreSQL import verification failed.');
    $stored = json_decode((string)$check['data'], true, 512, JSON_THROW_ON_ERROR);
    $sections = ['equipment', 'logs', 'users', 'audit_logs', 'aux_information', 'checklist_templates'];
    $counts = [];
    foreach ($sections as $section) {
        $sourceCount = is_array($decoded[$section] ?? null) ? count($decoded[$section]) : 0;
        $storedCount = is_array($stored[$section] ?? null) ? count($stored[$section]) : 0;
        if ($sourceCount !== $storedCount) throw new RuntimeException("PostgreSQL verification failed for {$section}: source {$sourceCount}, database {$storedCount}.");
        $counts[$section] = $storedCount;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
fwrite(STDOUT, "Initial JSON-to-PostgreSQL import completed. SHA-256: {$hash}. Verified record counts: " . json_encode($counts) . ". The bundled JSON source file was left untouched.\n");
