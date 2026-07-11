<?php
/**
 * glom — the simplest possible calorie tracker.
 * One file. Tracks daily weight, kcal, protein. That's it.
 *
 * DEPLOY
 *   1. Copy this file to any PHP 8+ host with pdo_sqlite (enabled by default).
 *   2. Make sure the web server can WRITE to this directory (it creates glom.sqlite).
 *   3. Edit the config block below (PIN, SECRET, API TOKEN, timezone).
 *   4. Block direct access to the database file:
 *
 *      Apache (.htaccess in this directory):
 *        <FilesMatch "^glom\.sqlite">
 *          Require all denied
 *        </FilesMatch>
 *
 *      nginx (inside the server block):
 *        location ~ /glom\.sqlite { deny all; }
 *
 *   5. Open the URL, log in with your PIN, set targets, add your favourite foods.
 *
 * Local dev: php -S localhost:8000
 */

// ---------------------------------------------------------------- config ---
const GLOM_PIN       = 'changeme';                 // login PIN
const GLOM_SECRET    = 'change-this-to-a-long-random-string'; // signs the auth cookie
const GLOM_DB        = __DIR__ . '/glom.sqlite';
const GLOM_TZ        = 'Europe/Helsinki';
const GLOM_API_TOKEN = 'change-this-ingest-token'; // for ?api=ingest (Health pushes)
const GLOM_VERSION   = '0.1.0';                    // bump to bust the PWA cache

date_default_timezone_set(GLOM_TZ);

// --------------------------------------------------------------- helpers ---

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . GLOM_DB, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS foods (
                id               INTEGER PRIMARY KEY,
                name             TEXT NOT NULL,
                kcal_per_100g    REAL NOT NULL,
                protein_per_100g REAL NOT NULL,
                last_grams       REAL,
                use_count        INTEGER NOT NULL DEFAULT 0,
                archived         INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS meals (
                id        INTEGER PRIMARY KEY,
                name      TEXT NOT NULL,
                use_count INTEGER NOT NULL DEFAULT 0,
                archived  INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS meal_items (
                id          INTEGER PRIMARY KEY,
                meal_id     INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
                food_id     INTEGER REFERENCES foods(id),
                grams       REAL,
                raw_label   TEXT,
                raw_kcal    REAL,
                raw_protein REAL
            );
            CREATE TABLE IF NOT EXISTS entries (
                id         INTEGER PRIMARY KEY,
                day        TEXT NOT NULL,
                label      TEXT NOT NULL,
                kcal       REAL NOT NULL,
                protein    REAL NOT NULL,
                source     TEXT NOT NULL DEFAULT 'quick',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_entries_day ON entries(day);
            CREATE TABLE IF NOT EXISTS weights (
                day TEXT PRIMARY KEY,
                kg  REAL NOT NULL
            );
            CREATE TABLE IF NOT EXISTS steps (
                day   TEXT PRIMARY KEY,
                count INTEGER NOT NULL
            );
            SQL);
    }
    return $pdo;
}

function today(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(GLOM_TZ)))->format('Y-m-d');
}

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $status = 400): never {
    json_out(['ok' => false, 'error' => $msg], $status);
}

/** Decoded JSON body of a POST request (also accepts form fields as fallback). */
function body(): array {
    static $body = null;
    if ($body === null) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) $body = $_POST;
    }
    return $body;
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
}

// ---------------------------------------------------------------- router ---

$api   = $_GET['api']   ?? null;
$asset = $_GET['asset'] ?? null;

if ($api !== null) {
    try {
        api($api);
    } catch (Throwable $e) {
        fail('server error: ' . $e->getMessage(), 500);
    }
}

if ($asset !== null) {
    asset($asset);
}

html_shell();
exit;

// ------------------------------------------------------------------- api ---

function api(string $action): never {
    switch ($action) {
        case 'ping':
            json_out(['ok' => true, 'pong' => true]);
        default:
            fail('unknown action', 404);
    }
}

// ---------------------------------------------------------------- assets ---

function asset(string $name): never {
    fail('unknown asset', 404);
}

// ------------------------------------------------------------ html shell ---

function html_shell(): void {
    db(); // ensure schema exists on first visit
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>glom</title></head><body><h1>glom</h1></body></html>';
}
