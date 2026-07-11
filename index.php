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

function valid_day(mixed $s): string {
    $s = (string)$s;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $s;
    }
    fail('invalid date');
}

function get_targets(): array {
    $rows = db()->query("SELECT key, value FROM settings WHERE key IN ('kcal_target','protein_target')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'kcal'    => (float)($rows['kcal_target'] ?? 2200),
        'protein' => (float)($rows['protein_target'] ?? 160),
    ];
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

        case 'entry.add': {
            require_post();
            $b = body();
            $day  = valid_day($b['day'] ?? today());
            $type = (string)($b['type'] ?? 'quick');
            $db = db();
            switch ($type) {
                case 'quick':
                    $kcal    = num($b['kcal'] ?? null, 0, 20000, 'kcal');
                    $protein = num($b['protein'] ?? 0, 0, 1000, 'protein');
                    $label   = trim((string)($b['label'] ?? ''));
                    $label   = $label === '' ? 'Quick add' : clean_name($label, 'label');
                    break;
                case 'food':
                    $foodId = (int)num($b['food_id'] ?? null, 1, PHP_INT_MAX, 'food_id');
                    $grams  = num($b['grams'] ?? null, 0.1, 5000, 'grams');
                    $st = $db->prepare('SELECT * FROM foods WHERE id = ? AND archived = 0');
                    $st->execute([$foodId]);
                    $food = $st->fetch();
                    if (!$food) fail('food not found', 404);
                    $kcal    = round($food['kcal_per_100g'] * $grams / 100.0, 1);
                    $protein = round($food['protein_per_100g'] * $grams / 100.0, 1);
                    $label   = $food['name'] . ' ' . rtrim(rtrim(number_format($grams, 1, '.', ''), '0'), '.') . ' g';
                    $db->prepare('UPDATE foods SET use_count = use_count + 1, last_grams = ? WHERE id = ?')
                       ->execute([$grams, $foodId]);
                    break;
                case 'meal':
                    $mealId = (int)num($b['meal_id'] ?? null, 1, PHP_INT_MAX, 'meal_id');
                    $st = $db->prepare('SELECT * FROM meals WHERE id = ? AND archived = 0');
                    $st->execute([$mealId]);
                    $meal = $st->fetch();
                    if (!$meal) fail('meal not found', 404);
                    $t = meal_totals($mealId);
                    $kcal    = $t['kcal'];
                    $protein = $t['protein'];
                    $label   = $meal['name'];
                    $db->prepare('UPDATE meals SET use_count = use_count + 1 WHERE id = ?')->execute([$mealId]);
                    break;
                default:
                    fail('unknown entry type');
            }
            $db->prepare('INSERT INTO entries (day, label, kcal, protein, source) VALUES (?, ?, ?, ?, ?)')
               ->execute([$day, $label, $kcal, $protein, $type]);
            json_out(['ok' => true, 'entry' => [
                'id' => (int)$db->lastInsertId(), 'day' => $day, 'label' => $label,
                'kcal' => $kcal, 'protein' => $protein, 'source' => $type,
            ]]);
        }

        case 'entry.delete': {
            require_post();
            $id = (int)num(body()['id'] ?? null, 1, PHP_INT_MAX, 'id');
            db()->prepare('DELETE FROM entries WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'weight.set': {
            require_post();
            $b = body();
            $day = valid_day($b['day'] ?? today());
            $kg  = $b['kg'] ?? null;
            if ($kg === null || $kg === '') {
                db()->prepare('DELETE FROM weights WHERE day = ?')->execute([$day]);
                json_out(['ok' => true, 'kg' => null]);
            }
            $kg = num($kg, 20, 400, 'weight');
            db()->prepare('INSERT INTO weights (day, kg) VALUES (?, ?)
                           ON CONFLICT(day) DO UPDATE SET kg = excluded.kg')->execute([$day, $kg]);
            json_out(['ok' => true, 'kg' => $kg]);
        }

        case 'targets.set': {
            require_post();
            $b = body();
            $kcal    = num($b['kcal_target'] ?? null, 500, 20000, 'kcal target');
            $protein = num($b['protein_target'] ?? null, 10, 1000, 'protein target');
            $st = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                                 ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            $st->execute(['kcal_target', $kcal]);
            $st->execute(['protein_target', $protein]);
            json_out(['ok' => true]);
        }

        case 'day': {
            $date = ($_GET['date'] ?? '') === '' ? today() : valid_day($_GET['date']);
            $db = db();
            $st = $db->prepare('SELECT id, label, kcal, protein, source FROM entries
                                WHERE day = ? ORDER BY id DESC');
            $st->execute([$date]);
            $entries = $st->fetchAll();
            $totals = ['kcal' => 0.0, 'protein' => 0.0];
            foreach ($entries as $e) {
                $totals['kcal']    += $e['kcal'];
                $totals['protein'] += $e['protein'];
            }
            $totals['kcal']    = round($totals['kcal'], 1);
            $totals['protein'] = round($totals['protein'], 1);
            $st = $db->prepare('SELECT kg FROM weights WHERE day = ?');
            $st->execute([$date]);
            $weight = $st->fetchColumn();
            $st = $db->prepare('SELECT kg FROM weights WHERE day < ? ORDER BY day DESC LIMIT 1');
            $st->execute([$date]);
            $prev = $st->fetchColumn();
            json_out([
                'ok' => true, 'date' => $date, 'today' => today(),
                'entries' => $entries,
                'weight' => $weight === false ? null : (float)$weight,
                'prev_weight' => $prev === false ? null : (float)$prev,
                'totals' => $totals,
                'targets' => get_targets(),
            ]);
        }

        case 'trend': {
            $n = (int)num($_GET['days'] ?? 30, 1, 365, 'days');
            $db = db();
            $end = new DateTimeImmutable(today());
            $days = [];
            $kcalRows = $db->prepare('SELECT day, ROUND(SUM(kcal),1) AS kcal, ROUND(SUM(protein),1) AS protein
                                      FROM entries WHERE day >= ? GROUP BY day');
            $start = $end->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
            $kcalRows->execute([$start]);
            $byDay = [];
            foreach ($kcalRows->fetchAll() as $r) $byDay[$r['day']] = $r;
            $wRows = $db->prepare('SELECT day, kg FROM weights WHERE day >= ?');
            $wRows->execute([$start]);
            $wByDay = $wRows->fetchAll(PDO::FETCH_KEY_PAIR);
            for ($i = $n - 1; $i >= 0; $i--) {
                $d = $end->modify("-$i days")->format('Y-m-d');
                $days[] = [
                    'day'     => $d,
                    'kcal'    => (float)($byDay[$d]['kcal'] ?? 0),
                    'protein' => (float)($byDay[$d]['protein'] ?? 0),
                    'weight'  => isset($wByDay[$d]) ? (float)$wByDay[$d] : null,
                ];
            }
            json_out(['ok' => true, 'days' => $days, 'targets' => get_targets()]);
        }

        case 'selftest': {
            $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                          ->fetchAll(PDO::FETCH_COLUMN);
            $expected = ['settings', 'foods', 'meals', 'meal_items', 'entries', 'weights', 'steps'];
            $missing = array_values(array_diff($expected, $tables));
            $db = db();
            $db->beginTransaction();
            try {
                $db->exec("INSERT INTO foods (name, kcal_per_100g, protein_per_100g) VALUES ('__selftest__', 200, 10)");
                $fid = (int)$db->lastInsertId();
                $db->exec("INSERT INTO meals (name) VALUES ('__selftest__')");
                $mid = (int)$db->lastInsertId();
                $db->prepare('INSERT INTO meal_items (meal_id, food_id, grams) VALUES (?, ?, 50)')->execute([$mid, $fid]);
                $db->prepare('INSERT INTO meal_items (meal_id, raw_kcal, raw_protein) VALUES (?, 25, 3)')->execute([$mid]);
                $t = meal_totals($mid);
                $mathOk = abs($t['kcal'] - 125) < 0.01 && abs($t['protein'] - 8) < 0.01;
            } finally {
                $db->rollBack();
            }
            $pass = $missing === [] && $mathOk;
            json_out(['ok' => true, 'pass' => $pass, 'missing_tables' => $missing, 'meal_math' => $mathOk]);
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
    $authed = auth_ok();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#3E9B4F">
<title>glom</title>
<style>
:root {
  --green: #3E9B4F;
  --green-deep: #2E7A3C;
  --amber: #F2A93B;
  --red: #E4572E;
  --bg: #FAF8F4;
  --card: #FFFFFF;
  --ink: #22332B;
  --ink-soft: #6B7A70;
  --line: #E8E3D8;
  --track: #EFEAE0;
  --shadow: 0 1px 2px rgba(34,51,43,.06), 0 4px 16px rgba(34,51,43,.05);
}
@media (prefers-color-scheme: dark) {
  :root {
    --green: #5BBF6C;
    --green-deep: #7ED28C;
    --amber: #F5B95C;
    --red: #F07B57;
    --bg: #131A15;
    --card: #1C2620;
    --ink: #EDF3EE;
    --ink-soft: #93A69A;
    --line: #2A362E;
    --track: #263029;
    --shadow: 0 1px 2px rgba(0,0,0,.3), 0 4px 16px rgba(0,0,0,.25);
  }
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body {
  font-family: ui-rounded, "SF Pro Rounded", "Hiragino Maru Gothic ProN", Quicksand, Manjari, -apple-system, "Segoe UI", sans-serif;
  background:
    radial-gradient(1200px 500px at 50% -200px, color-mix(in srgb, var(--green) 7%, transparent), transparent),
    var(--bg);
  color: var(--ink);
  min-height: 100dvh;
  padding-bottom: env(safe-area-inset-bottom);
}
button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
input { font: inherit; color: inherit; }
#app, #login { max-width: 480px; margin: 0 auto; padding: 12px 16px 140px; }

/* ---------- login ---------- */
#login { padding-top: 18vh; text-align: center; }
#login .blob { width: 96px; height: 96px; margin: 0 auto 12px; }
#login h1 { font-size: 2.2rem; font-weight: 800; letter-spacing: -.02em; }
#login p { color: var(--ink-soft); margin: 6px 0 24px; }
#login input {
  width: 200px; text-align: center; font-size: 1.6rem; letter-spacing: .3em;
  padding: 10px 14px; border: 2px solid var(--line); border-radius: 16px;
  background: var(--card); outline: none;
}
#login input:focus { border-color: var(--green); }
#login button {
  display: block; margin: 16px auto 0; padding: 12px 42px; border-radius: 999px;
  background: var(--green); color: #fff; font-weight: 700; font-size: 1.05rem;
  box-shadow: var(--shadow);
}
#login .err { color: var(--red); margin-top: 14px; min-height: 1.2em; font-weight: 600; }

/* ---------- header ---------- */
header { display: flex; align-items: center; gap: 4px; padding: 8px 0 4px; }
header .nav { font-size: 1.5rem; padding: 6px 12px; color: var(--ink-soft); border-radius: 12px; }
header .nav:active { background: var(--track); }
#dateLabel { position: relative; font-size: 1.25rem; font-weight: 800; padding: 6px 4px; }
#dateLabel input {
  position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%;
}
header .spacer { flex: 1; }
header .iconbtn { padding: 8px; border-radius: 12px; color: var(--ink-soft); }
header .iconbtn:active { background: var(--track); }
header .iconbtn svg { width: 22px; height: 22px; display: block; }

/* ---------- weight ---------- */
.weightrow {
  display: flex; align-items: baseline; gap: 10px;
  background: var(--card); border: 1px solid var(--line); border-radius: 18px;
  padding: 12px 16px; margin-top: 8px; box-shadow: var(--shadow);
}
.weightrow .lbl { color: var(--ink-soft); font-weight: 600; }
.weightrow input {
  width: 90px; font-size: 1.5rem; font-weight: 800; text-align: right;
  border: 0; background: none; outline: none;
}
.weightrow .unit { color: var(--ink-soft); font-weight: 600; }
.weightrow .delta { margin-left: auto; color: var(--ink-soft); font-weight: 600; font-size: .95rem; }
.weightrow .delta.down { color: var(--green); }
.weightrow .delta.up { color: var(--amber); }

/* ---------- progress ---------- */
.progress {
  background: var(--card); border: 1px solid var(--line); border-radius: 22px;
  padding: 18px 16px 16px; margin-top: 12px; box-shadow: var(--shadow);
}
.meter + .meter { margin-top: 18px; }
.meter .nums { display: flex; align-items: baseline; gap: 8px; }
.meter .big { font-size: 2.4rem; font-weight: 800; letter-spacing: -.03em; line-height: 1; font-variant-numeric: tabular-nums; }
.meter .of { color: var(--ink-soft); font-weight: 600; }
.meter .side { margin-left: auto; font-weight: 700; font-size: .95rem; color: var(--ink-soft); }
.meter .side.over { color: var(--red); }
.meter .side.hit { color: var(--green-deep); }
.meter .bar {
  height: 14px; border-radius: 999px; background: var(--track);
  margin-top: 10px; overflow: hidden; display: flex;
}
.meter .fill { border-radius: 999px; transition: width .5s cubic-bezier(.22,1,.36,1); }
.meter.kcal .fill { background: var(--amber); }
.meter.kcal .fill.overfill { background: var(--red); border-radius: 0 999px 999px 0; margin-left: 2px; }
.meter.protein .fill { background: var(--green); }
.meter.protein.hit .fill { background: var(--green-deep); }
.meter .hitmsg { margin-top: 8px; font-weight: 700; color: var(--green-deep); font-size: .95rem; }

/* ---------- entries ---------- */
#entries { margin-top: 16px; }
#entries h2 { font-size: .85rem; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-soft); margin: 0 4px 8px; }
.entry {
  display: flex; align-items: center; gap: 10px;
  background: var(--card); border: 1px solid var(--line); border-radius: 16px;
  padding: 12px 14px; margin-bottom: 8px; box-shadow: var(--shadow);
}
.entry .name { font-weight: 700; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.entry .macros { color: var(--ink-soft); font-weight: 600; font-size: .95rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.entry .macros b { color: var(--ink); }
.entry .del {
  display: none; color: #fff; background: var(--red); border-radius: 10px;
  padding: 6px 10px; font-weight: 700;
}
.entry.reveal .del { display: block; }
.empty {
  text-align: center; color: var(--ink-soft); padding: 28px 20px; font-weight: 600;
  border: 2px dashed var(--line); border-radius: 18px;
}
.toast {
  position: fixed; left: 50%; bottom: 120px; transform: translateX(-50%) translateY(20px);
  background: var(--ink); color: var(--bg); padding: 10px 18px; border-radius: 999px;
  font-weight: 700; opacity: 0; pointer-events: none; transition: all .3s; z-index: 50;
}
.toast.show { opacity: 1; transform: translateX(-50%); }
</style>
</head>
<body>
<?php if (!$authed): ?>
<div id="login">
  <svg class="blob" viewBox="0 0 100 100" aria-hidden="true">
    <path fill="#3E9B4F" d="M50 6C69 4 92 18 94 40c2 21-12 40-31 46C43 92 18 88 9 68 0 49 9 25 27 14 34 10 42 7 50 6Z"/>
    <path fill="#2E7A3C" d="M62 20c8-8 20-9 26-4-2 9-11 16-20 16-4 0-7-6-6-12Z"/>
    <circle cx="38" cy="46" r="4.5" fill="#FAF8F4"/><circle cx="60" cy="46" r="4.5" fill="#FAF8F4"/>
    <path d="M38 62c7 6 17 6 24 0" stroke="#FAF8F4" stroke-width="4.5" stroke-linecap="round" fill="none"/>
  </svg>
  <h1>glom</h1>
  <p>weight · kcal · protein</p>
  <form id="pinform">
    <input id="pin" type="password" inputmode="numeric" autocomplete="current-password" placeholder="PIN" autofocus>
    <button type="submit">Open</button>
    <div class="err" id="pinerr"></div>
  </form>
</div>
<script>
document.getElementById('pinform').addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = await fetch('index.php?api=login', { method: 'POST', body: JSON.stringify({ pin: document.getElementById('pin').value }) });
  const d = await r.json();
  if (d.ok) location.reload();
  else document.getElementById('pinerr').textContent = d.error || 'wrong PIN';
});
</script>
<?php else: ?>
<div id="app">
  <header>
    <button class="nav" id="prevDay" aria-label="Previous day">&#8249;</button>
    <span id="dateLabel">Today<input type="date" id="datePick" aria-label="Pick date"></span>
    <button class="nav" id="nextDay" aria-label="Next day">&#8250;</button>
    <span class="spacer"></span>
    <button class="iconbtn" id="openBook" aria-label="Foods and meals">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
    </button>
    <button class="iconbtn" id="openGear" aria-label="Settings">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
  </header>

  <div class="weightrow">
    <span class="lbl">weight</span>
    <input id="weightIn" type="number" inputmode="decimal" step="0.1" min="20" max="400" placeholder="—">
    <span class="unit">kg</span>
    <span class="delta" id="weightDelta"></span>
  </div>

  <div class="progress">
    <div class="meter kcal" id="kcalMeter">
      <div class="nums">
        <span class="big" id="kcalNow">0</span>
        <span class="of" id="kcalOf">/ 0 kcal</span>
        <span class="side" id="kcalSide"></span>
      </div>
      <div class="bar" id="kcalBar"></div>
    </div>
    <div class="meter protein" id="proteinMeter">
      <div class="nums">
        <span class="big" id="proteinNow">0</span>
        <span class="of" id="proteinOf">/ 0 g protein</span>
        <span class="side" id="proteinSide"></span>
      </div>
      <div class="bar" id="proteinBar"></div>
      <div class="hitmsg" id="proteinMsg" hidden>Protein target hit &#128170;</div>
    </div>
  </div>

  <section id="entries">
    <h2>Logged</h2>
    <div id="entryList"></div>
  </section>

  <div class="toast" id="toast"></div>
</div>
<script>
const App = {
  state: { date: null, day: null },

  async api(action, body) {
    const opts = body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) };
    let r;
    try {
      r = await fetch('index.php?api=' + action, opts);
    } catch {
      this.toast('offline, not saved');
      throw new Error('offline');
    }
    const d = await r.json();
    if (!d.ok) { this.toast(d.error || 'error'); throw new Error(d.error); }
    return d;
  },

  async load(date) {
    const d = await this.api('day&date=' + (date || ''));
    this.state.date = d.date;
    this.state.day = d;
    this.render();
  },

  fmt(n) { return Number(n) % 1 === 0 ? String(Number(n)) : Number(n).toFixed(1); },

  dateLabel() {
    const { date, day } = this.state;
    const t = new Date(day.today + 'T12:00:00');
    const d = new Date(date + 'T12:00:00');
    const diff = Math.round((t - d) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  },

  shiftDay(n) {
    const d = new Date(this.state.date + 'T12:00:00');
    d.setDate(d.getDate() + n);
    this.load(d.toISOString().slice(0, 10));
  },

  render() {
    const day = this.state.day;
    const el = (id) => document.getElementById(id);

    el('dateLabel').firstChild.textContent = this.dateLabel();
    el('datePick').value = this.state.date;

    el('weightIn').value = day.weight ?? '';
    const delta = el('weightDelta');
    if (day.weight != null && day.prev_weight != null) {
      const diff = day.weight - day.prev_weight;
      delta.textContent = (diff <= 0 ? '▾ ' : '▴ ') + this.fmt(Math.abs(diff));
      delta.className = 'delta ' + (diff <= 0 ? 'down' : 'up');
    } else { delta.textContent = ''; delta.className = 'delta'; }

    const { totals, targets } = day;

    el('kcalNow').textContent = this.fmt(totals.kcal);
    el('kcalOf').textContent = '/ ' + this.fmt(targets.kcal) + ' kcal';
    const kcalLeft = targets.kcal - totals.kcal;
    const kside = el('kcalSide');
    kside.textContent = kcalLeft >= 0 ? this.fmt(kcalLeft) + ' left' : this.fmt(-kcalLeft) + ' over';
    kside.className = 'side' + (kcalLeft < 0 ? ' over' : '');
    const kbar = el('kcalBar');
    kbar.innerHTML = '';
    const kfill = document.createElement('div');
    kfill.className = 'fill';
    kfill.style.width = Math.min(100, totals.kcal / targets.kcal * 100) + '%';
    kbar.appendChild(kfill);
    if (totals.kcal > targets.kcal) {
      const over = document.createElement('div');
      over.className = 'fill overfill';
      kfill.style.width = (targets.kcal / totals.kcal * 100) + '%';
      over.style.width = ((totals.kcal - targets.kcal) / totals.kcal * 100) + '%';
      kbar.appendChild(over);
    }

    el('proteinNow').textContent = this.fmt(totals.protein);
    el('proteinOf').textContent = '/ ' + this.fmt(targets.protein) + ' g protein';
    const hit = totals.protein >= targets.protein;
    el('proteinMeter').classList.toggle('hit', hit);
    el('proteinMsg').hidden = !hit;
    const pside = el('proteinSide');
    pside.textContent = hit ? '✓' : this.fmt(targets.protein - totals.protein) + ' to go';
    pside.className = 'side' + (hit ? ' hit' : '');
    const pbar = el('proteinBar');
    pbar.innerHTML = '';
    const pfill = document.createElement('div');
    pfill.className = 'fill';
    pfill.style.width = Math.min(100, totals.protein / targets.protein * 100) + '%';
    pbar.appendChild(pfill);

    const list = el('entryList');
    list.innerHTML = '';
    if (!day.entries.length) {
      const e = document.createElement('div');
      e.className = 'empty';
      e.textContent = 'Nothing logged yet. Go eat something worth writing down.';
      list.appendChild(e);
      return;
    }
    for (const en of day.entries) {
      const row = document.createElement('div');
      row.className = 'entry';
      const name = document.createElement('span');
      name.className = 'name';
      name.textContent = en.label;
      const macros = document.createElement('span');
      macros.className = 'macros';
      macros.innerHTML = '<b>' + this.fmt(en.kcal) + '</b> kcal · <b>' + this.fmt(en.protein) + '</b> g';
      const del = document.createElement('button');
      del.className = 'del';
      del.textContent = '✕';
      del.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        await this.api('entry.delete', { id: en.id });
        this.load(this.state.date);
      });
      row.addEventListener('click', () => {
        document.querySelectorAll('.entry.reveal').forEach(r => r !== row && r.classList.remove('reveal'));
        row.classList.toggle('reveal');
      });
      row.append(name, macros, del);
      list.appendChild(row);
    }
  },

  toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(this._toastT);
    this._toastT = setTimeout(() => t.classList.remove('show'), 2200);
  },
};

document.getElementById('prevDay').addEventListener('click', () => App.shiftDay(-1));
document.getElementById('nextDay').addEventListener('click', () => App.shiftDay(1));
document.getElementById('datePick').addEventListener('change', (e) => App.load(e.target.value));
document.getElementById('weightIn').addEventListener('change', async (e) => {
  await App.api('weight.set', { day: App.state.date, kg: e.target.value });
  App.load(App.state.date);
});

App.load();
</script>
<?php endif; ?>
</body>
</html>
<?php
}
