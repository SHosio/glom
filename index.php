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

// ------------------------------------------------------------ validators ---

function num(mixed $v, float $min, float $max, string $what = 'value'): float {
    if (!is_numeric($v)) fail("$what must be a number");
    $f = (float)$v;
    if ($f < $min || $f > $max) fail("$what out of range ($min\u{2013}$max)");
    return $f;
}

function clean_name(mixed $s, string $what = 'name'): string {
    $s = trim((string)$s);
    if ($s === '' || mb_strlen($s) > 80) fail("$what must be 1\u{2013}80 characters");
    return $s;
}

// ----------------------------------------------------------------- meals ---

/** @return array{kcal: float, protein: float} */
function meal_totals(int $mealId): array {
    $row = db()->prepare(
        'SELECT
            COALESCE(SUM(COALESCE(mi.raw_kcal, 0) + COALESCE(f.kcal_per_100g * mi.grams / 100.0, 0)), 0) AS kcal,
            COALESCE(SUM(COALESCE(mi.raw_protein, 0) + COALESCE(f.protein_per_100g * mi.grams / 100.0, 0)), 0) AS protein
         FROM meal_items mi LEFT JOIN foods f ON f.id = mi.food_id
         WHERE mi.meal_id = ?'
    );
    $row->execute([$mealId]);
    $t = $row->fetch();
    return ['kcal' => round((float)$t['kcal'], 1), 'protein' => round((float)$t['protein'], 1)];
}

// ------------------------------------------------------------------ auth ---

function auth_token(): string {
    return hash_hmac('sha256', 'glom-auth-v1', GLOM_SECRET);
}

function auth_ok(): bool {
    return hash_equals(auth_token(), $_COOKIE['glom_auth'] ?? '');
}

function require_auth(): void {
    if (!auth_ok()) fail('unauthorized', 401);
}

function auth_cookie(string $value, int $maxAge): void {
    setcookie('glom_auth', $value, [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
    if (!in_array($action, ['ping', 'login'], true)) require_auth();
    switch ($action) {
        case 'ping':
            json_out(['ok' => true, 'pong' => true]);

        case 'login':
            require_post();
            $pin = (string)(body()['pin'] ?? '');
            if (!hash_equals(GLOM_PIN, $pin)) {
                sleep(2);
                fail('wrong PIN', 401);
            }
            auth_cookie(auth_token(), 400 * 86400);
            json_out(['ok' => true]);

        case 'logout':
            require_post();
            auth_cookie('', -3600);
            json_out(['ok' => true]);

        case 'foods.list':
            $foods = db()->query(
                'SELECT id, name, kcal_per_100g, protein_per_100g, last_grams, use_count
                 FROM foods WHERE archived = 0 ORDER BY use_count DESC, name'
            )->fetchAll();
            json_out(['ok' => true, 'foods' => $foods]);

        case 'food.save': {
            require_post();
            $b = body();
            $name    = clean_name($b['name'] ?? '');
            $kcal    = num($b['kcal_per_100g'] ?? null, 0, 20000, 'kcal per 100 g');
            $protein = num($b['protein_per_100g'] ?? null, 0, 1000, 'protein per 100 g');
            $id = $b['id'] ?? null;
            if ($id !== null) {
                $id = (int)num($id, 1, PHP_INT_MAX, 'id');
                $st = db()->prepare('UPDATE foods SET name = ?, kcal_per_100g = ?, protein_per_100g = ? WHERE id = ? AND archived = 0');
                $st->execute([$name, $kcal, $protein, $id]);
                if ($st->rowCount() === 0) fail('food not found', 404);
            } else {
                db()->prepare('INSERT INTO foods (name, kcal_per_100g, protein_per_100g) VALUES (?, ?, ?)')
                    ->execute([$name, $kcal, $protein]);
                $id = (int)db()->lastInsertId();
            }
            json_out(['ok' => true, 'id' => $id]);
        }

        case 'food.delete': {
            require_post();
            $id = (int)num(body()['id'] ?? null, 1, PHP_INT_MAX, 'id');
            db()->prepare('UPDATE foods SET archived = 1 WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'meals.list': {
            $meals = db()->query(
                'SELECT id, name, use_count FROM meals WHERE archived = 0 ORDER BY use_count DESC, name'
            )->fetchAll();
            $itemsStmt = db()->prepare(
                'SELECT mi.id, mi.food_id, f.name AS food_name, mi.grams, mi.raw_label, mi.raw_kcal, mi.raw_protein
                 FROM meal_items mi LEFT JOIN foods f ON f.id = mi.food_id
                 WHERE mi.meal_id = ? ORDER BY mi.id'
            );
            foreach ($meals as &$m) {
                $itemsStmt->execute([$m['id']]);
                $m['items'] = $itemsStmt->fetchAll();
                $m += meal_totals((int)$m['id']);
            }
            json_out(['ok' => true, 'meals' => $meals]);
        }

        case 'meal.save': {
            require_post();
            $b = body();
            $name  = clean_name($b['name'] ?? '');
            $items = $b['items'] ?? null;
            if (!is_array($items) || $items === []) fail('a meal needs at least one item');
            $parsed = [];
            foreach ($items as $it) {
                if (!is_array($it)) fail('invalid meal item');
                if (isset($it['food_id'])) {
                    $foodId = (int)num($it['food_id'], 1, PHP_INT_MAX, 'food_id');
                    $grams  = num($it['grams'] ?? null, 0.1, 5000, 'grams');
                    $parsed[] = ['food_id' => $foodId, 'grams' => $grams,
                                 'raw_label' => null, 'raw_kcal' => null, 'raw_protein' => null];
                } elseif (isset($it['raw_kcal']) || isset($it['raw_protein'])) {
                    $parsed[] = [
                        'food_id' => null, 'grams' => null,
                        'raw_label'   => isset($it['raw_label']) && trim((string)$it['raw_label']) !== ''
                                         ? clean_name($it['raw_label'], 'item label') : null,
                        'raw_kcal'    => num($it['raw_kcal'] ?? 0, 0, 20000, 'item kcal'),
                        'raw_protein' => num($it['raw_protein'] ?? 0, 0, 1000, 'item protein'),
                    ];
                } else {
                    fail('invalid meal item');
                }
            }
            $db = db();
            $db->beginTransaction();
            try {
                $id = $b['id'] ?? null;
                if ($id !== null) {
                    $id = (int)num($id, 1, PHP_INT_MAX, 'id');
                    $st = $db->prepare('UPDATE meals SET name = ? WHERE id = ? AND archived = 0');
                    $st->execute([$name, $id]);
                    if ($st->rowCount() === 0) fail('meal not found', 404);
                    $db->prepare('DELETE FROM meal_items WHERE meal_id = ?')->execute([$id]);
                } else {
                    $db->prepare('INSERT INTO meals (name) VALUES (?)')->execute([$name]);
                    $id = (int)$db->lastInsertId();
                }
                $ins = $db->prepare(
                    'INSERT INTO meal_items (meal_id, food_id, grams, raw_label, raw_kcal, raw_protein)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($parsed as $p) {
                    $ins->execute([$id, $p['food_id'], $p['grams'], $p['raw_label'], $p['raw_kcal'], $p['raw_protein']]);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            json_out(['ok' => true, 'id' => $id] + meal_totals($id));
        }

        case 'meal.delete': {
            require_post();
            $id = (int)num(body()['id'] ?? null, 1, PHP_INT_MAX, 'id');
            db()->prepare('UPDATE meals SET archived = 1 WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

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
