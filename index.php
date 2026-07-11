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

/* ---------- favourites strip + add bar ---------- */
#addbar {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 40;
  background: var(--card); border-top: 1px solid var(--line);
  box-shadow: 0 -6px 24px rgba(34,51,43,.08);
  padding: 0 0 env(safe-area-inset-bottom);
}
#addbar .inner { max-width: 480px; margin: 0 auto; padding: 8px 16px 10px; }
#chips {
  display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 8px;
  scrollbar-width: none; -webkit-overflow-scrolling: touch;
}
#chips::-webkit-scrollbar { display: none; }
.chip {
  flex: 0 0 auto; display: flex; align-items: center; gap: 6px;
  border: 1.5px solid var(--line); border-radius: 999px; padding: 7px 14px;
  font-weight: 700; font-size: .95rem; background: var(--bg); white-space: nowrap;
}
.chip:active { transform: scale(.96); }
.chip.meal { border-color: color-mix(in srgb, var(--green) 45%, var(--line)); }
.chip .k { color: var(--ink-soft); font-weight: 600; font-size: .85rem; }
.tabs { display: flex; gap: 4px; background: var(--track); border-radius: 14px; padding: 4px; }
.tabs button {
  flex: 1; padding: 8px 0; border-radius: 10px; font-weight: 700; color: var(--ink-soft);
}
.tabs button.on { background: var(--card); color: var(--ink); box-shadow: var(--shadow); }
.pane { padding-top: 10px; }
.pane[hidden] { display: none; }
.pane .row { display: flex; gap: 8px; }
.pane input {
  min-width: 0; border: 1.5px solid var(--line); border-radius: 12px; padding: 10px 12px;
  background: var(--bg); outline: none; font-weight: 600;
}
.pane input:focus { border-color: var(--green); }
.pane .grow { flex: 1; }
.addbtn {
  background: var(--green); color: #fff; font-weight: 800; border-radius: 12px;
  padding: 10px 20px; flex: 0 0 auto;
}
.addbtn:disabled { opacity: .4; }
.picklist { max-height: 34dvh; overflow-y: auto; margin-top: 8px; }
.pick {
  display: flex; align-items: baseline; gap: 8px; width: 100%; text-align: left;
  padding: 10px 8px; border-radius: 10px; font-weight: 700;
}
.pick:active, .pick.sel { background: var(--track); }
.pick .k { margin-left: auto; color: var(--ink-soft); font-weight: 600; font-size: .9rem; white-space: nowrap; }
.pick .zero { color: var(--ink-soft); font-weight: 600; }
.preview { margin-top: 8px; color: var(--ink-soft); font-weight: 700; text-align: center; min-height: 1.3em; }
.preview b { color: var(--ink); }

/* ---------- panels (manager + settings) ---------- */
.overlay {
  position: fixed; inset: 0; background: rgba(20,30,24,.45); z-index: 60;
  opacity: 0; pointer-events: none; transition: opacity .25s;
}
.overlay.open { opacity: 1; pointer-events: auto; }
.panel {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(420px, 92vw); z-index: 61;
  background: var(--bg); box-shadow: -8px 0 32px rgba(0,0,0,.18);
  transform: translateX(105%); transition: transform .3s cubic-bezier(.22,1,.36,1);
  overflow-y: auto; padding: 16px 16px calc(24px + env(safe-area-inset-bottom));
}
.panel.open { transform: none; }
.panel h2 { font-size: 1.3rem; font-weight: 800; margin-bottom: 4px; }
.panel .close { position: absolute; top: 12px; right: 12px; font-size: 1.4rem; padding: 6px 12px; color: var(--ink-soft); }
.panel .tabs { margin-top: 12px; }
.mgr-list { margin-top: 12px; }
.mgr-item {
  background: var(--card); border: 1px solid var(--line); border-radius: 14px;
  padding: 12px 14px; margin-bottom: 8px; box-shadow: var(--shadow);
}
.mgr-item .head { display: flex; align-items: baseline; gap: 8px; }
.mgr-item .head .nm { font-weight: 700; flex: 1; }
.mgr-item .head .k { color: var(--ink-soft); font-size: .9rem; font-weight: 600; white-space: nowrap; }
.mgr-item .acts { display: flex; gap: 12px; margin-top: 6px; }
.mgr-item .acts button { color: var(--green-deep); font-weight: 700; font-size: .9rem; }
.mgr-item .acts button.danger { color: var(--red); }
.mgr-form {
  background: var(--card); border: 1.5px dashed var(--line); border-radius: 14px;
  padding: 12px; margin-top: 12px; display: flex; flex-direction: column; gap: 8px;
}
.mgr-form .row { display: flex; gap: 8px; }
.mgr-form input {
  min-width: 0; border: 1.5px solid var(--line); border-radius: 10px; padding: 9px 10px;
  background: var(--bg); outline: none; font-weight: 600; flex: 1;
}
.mgr-form input:focus { border-color: var(--green); }
.mgr-form .hint { color: var(--ink-soft); font-size: .82rem; font-weight: 600; }
.mgr-form .itemrow { display: flex; gap: 6px; align-items: center; }
.mgr-form .itemrow .rm { color: var(--red); font-weight: 800; padding: 4px 8px; }
.mgr-form .switch { color: var(--green-deep); font-weight: 700; font-size: .85rem; text-align: left; }
.mgr-form .total { font-weight: 800; text-align: right; }
.btnrow { display: flex; gap: 8px; margin-top: 4px; }
.btn-primary { background: var(--green); color: #fff; font-weight: 800; border-radius: 10px; padding: 10px 18px; flex: 1; }
.btn-ghost { color: var(--ink-soft); font-weight: 700; padding: 10px 14px; }
.settings-row { display: flex; align-items: center; gap: 10px; margin-top: 14px; }
.settings-row label { flex: 1; font-weight: 700; }
.settings-row input {
  width: 100px; border: 1.5px solid var(--line); border-radius: 10px; padding: 9px 10px;
  background: var(--card); outline: none; font-weight: 700; text-align: right;
}
.logout { margin-top: 28px; color: var(--red); font-weight: 700; }
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

  <div id="addbar">
    <div class="inner">
      <div id="chips"></div>
      <div class="tabs" role="tablist">
        <button id="tabFood" data-pane="paneFood">Food</button>
        <button id="tabMeal" data-pane="paneMeal">Meal</button>
        <button id="tabQuick" data-pane="paneQuick">Quick</button>
      </div>
      <div class="pane" id="paneFood" hidden>
        <div class="row">
          <input class="grow" id="foodSearch" type="search" placeholder="Search foods…" autocomplete="off">
          <input id="foodGrams" type="number" inputmode="decimal" step="1" min="1" max="5000" placeholder="g" style="width:76px">
          <button class="addbtn" id="foodAdd" disabled>Add</button>
        </div>
        <div class="preview" id="foodPreview"></div>
        <div class="picklist" id="foodList"></div>
      </div>
      <div class="pane" id="paneMeal" hidden>
        <div class="picklist" id="mealList"></div>
      </div>
      <div class="pane" id="paneQuick" hidden>
        <div class="row">
          <input id="quickKcal" type="number" inputmode="decimal" min="0" max="20000" placeholder="kcal" style="width:86px">
          <input id="quickProtein" type="number" inputmode="decimal" min="0" max="1000" placeholder="protein g" style="width:100px">
          <input class="grow" id="quickLabel" type="text" maxlength="80" placeholder="label (optional)">
          <button class="addbtn" id="quickAdd">Add</button>
        </div>
      </div>
    </div>
  </div>

  <div class="overlay" id="overlay"></div>

  <div class="panel" id="bookPanel">
    <button class="close" data-close>&#10005;</button>
    <h2>Favourites</h2>
    <div class="tabs">
      <button id="mtabFoods" class="on">Foods</button>
      <button id="mtabMeals">Meals</button>
    </div>
    <div id="mgrFoods">
      <div class="mgr-list" id="mgrFoodList"></div>
      <form class="mgr-form" id="foodForm">
        <input type="hidden" id="ffId">
        <input id="ffName" type="text" maxlength="80" placeholder="Food name" required>
        <div class="row">
          <input id="ffKcal" type="number" inputmode="decimal" min="0" step="0.1" placeholder="kcal / 100 g" required>
          <input id="ffProtein" type="number" inputmode="decimal" min="0" step="0.1" placeholder="protein g / 100 g" required>
        </div>
        <div class="btnrow">
          <button class="btn-primary" type="submit" id="ffSave">Add food</button>
          <button class="btn-ghost" type="button" id="ffCancel" hidden>Cancel</button>
        </div>
      </form>
    </div>
    <div id="mgrMeals" hidden>
      <div class="mgr-list" id="mgrMealList"></div>
      <form class="mgr-form" id="mealForm">
        <input type="hidden" id="mfId">
        <input id="mfName" type="text" maxlength="80" placeholder="Meal name (e.g. glob)" required>
        <div id="mfItems"></div>
        <button class="switch" type="button" id="mfAddFoodItem">+ favourite food</button>
        <button class="switch" type="button" id="mfAddRawItem">+ raw kcal / protein</button>
        <div class="total" id="mfTotal"></div>
        <div class="btnrow">
          <button class="btn-primary" type="submit" id="mfSave">Add meal</button>
          <button class="btn-ghost" type="button" id="mfCancel" hidden>Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <div class="panel" id="gearPanel">
    <button class="close" data-close>&#10005;</button>
    <h2>Settings</h2>
    <div class="settings-row">
      <label for="setKcal">Daily kcal target</label>
      <input id="setKcal" type="number" inputmode="numeric" min="500" max="20000">
    </div>
    <div class="settings-row">
      <label for="setProtein">Daily protein target (g)</label>
      <input id="setProtein" type="number" inputmode="numeric" min="10" max="1000">
    </div>
    <div class="btnrow" style="margin-top:16px">
      <button class="btn-primary" id="setSave">Save targets</button>
    </div>
    <button class="logout" id="logoutBtn">Log out</button>
  </div>
</div>
<script>
const App = {
  state: { date: null, day: null, foods: [], meals: [], selFood: null },

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

  async loadFavs() {
    const [f, m] = await Promise.all([this.api('foods.list'), this.api('meals.list')]);
    this.state.foods = f.foods;
    this.state.meals = m.meals;
    this.renderChips();
    this.renderFoodList();
    this.renderMealList();
  },

  async addEntry(payload) {
    await this.api('entry.add', Object.assign({ day: this.state.date }, payload));
    await Promise.all([this.load(this.state.date), this.loadFavs()]);
  },

  renderChips() {
    const chips = document.getElementById('chips');
    chips.innerHTML = '';
    const cands = [
      ...this.state.meals.map(m => ({ kind: 'meal', it: m })),
      ...this.state.foods.map(f => ({ kind: 'food', it: f })),
    ].sort((a, b) => b.it.use_count - a.it.use_count).slice(0, 8);
    for (const { kind, it } of cands) {
      const c = document.createElement('button');
      c.className = 'chip ' + kind;
      const name = document.createElement('span');
      name.textContent = it.name;
      const k = document.createElement('span');
      k.className = 'k';
      k.textContent = kind === 'meal'
        ? this.fmt(it.kcal) + ' kcal'
        : this.fmt(it.last_grams ?? 100) + ' g';
      c.append(name, k);
      c.addEventListener('click', () => {
        if (kind === 'meal') this.addEntry({ type: 'meal', meal_id: it.id });
        else this.pickFood(it, true);
      });
      chips.appendChild(c);
    }
    chips.hidden = cands.length === 0;
  },

  pickFood(food, switchTab) {
    this.state.selFood = food;
    if (switchTab) this.setTab('tabFood');
    const grams = document.getElementById('foodGrams');
    grams.value = food.last_grams ?? 100;
    document.getElementById('foodSearch').value = food.name;
    this.renderFoodList(food.name);
    this.foodPreview();
    grams.focus();
    grams.select();
  },

  foodPreview() {
    const f = this.state.selFood;
    const g = parseFloat(document.getElementById('foodGrams').value);
    const prev = document.getElementById('foodPreview');
    const btn = document.getElementById('foodAdd');
    if (f && g > 0) {
      prev.innerHTML = '<b>' + this.fmt(Math.round(f.kcal_per_100g * g / 100 * 10) / 10) +
        '</b> kcal · <b>' + this.fmt(Math.round(f.protein_per_100g * g / 100 * 10) / 10) + '</b> g protein';
      btn.disabled = false;
    } else {
      prev.textContent = f ? '' : 'pick a food';
      btn.disabled = true;
    }
  },

  renderFoodList(filter) {
    const list = document.getElementById('foodList');
    list.innerHTML = '';
    const q = (filter || '').trim().toLowerCase();
    for (const f of this.state.foods.filter(f => !q || f.name.toLowerCase().includes(q))) {
      const b = document.createElement('button');
      b.className = 'pick' + (this.state.selFood?.id === f.id ? ' sel' : '');
      const name = document.createElement('span');
      name.textContent = f.name;
      const k = document.createElement('span');
      k.className = 'k';
      k.textContent = this.fmt(f.kcal_per_100g) + ' kcal · ' + this.fmt(f.protein_per_100g) + ' g / 100 g';
      b.append(name, k);
      b.addEventListener('click', () => this.pickFood(f, false));
      list.appendChild(b);
    }
    if (!list.children.length) {
      const e = document.createElement('div');
      e.className = 'pick zero';
      e.textContent = this.state.foods.length ? 'No match.' : 'No favourite foods yet. Add them from the book icon.';
      list.appendChild(e);
    }
  },

  renderMealList() {
    const list = document.getElementById('mealList');
    list.innerHTML = '';
    for (const m of this.state.meals) {
      const b = document.createElement('button');
      b.className = 'pick';
      const name = document.createElement('span');
      name.textContent = m.name;
      const k = document.createElement('span');
      k.className = 'k';
      k.textContent = this.fmt(m.kcal) + ' kcal · ' + this.fmt(m.protein) + ' g';
      b.append(name, k);
      b.addEventListener('click', () => this.addEntry({ type: 'meal', meal_id: m.id }));
      list.appendChild(b);
    }
    if (!list.children.length) {
      const e = document.createElement('div');
      e.className = 'pick zero';
      e.textContent = 'No favourite meals yet. Build them from the book icon.';
      list.appendChild(e);
    }
  },

  setTab(id) {
    for (const t of document.querySelectorAll('#addbar .tabs button')) {
      const on = t.id === id;
      t.classList.toggle('on', on);
      document.getElementById(t.dataset.pane).hidden = !on;
    }
    localStorage.setItem('glom_tab', id);
  },

  // ---------- manager panel ----------

  openPanel(id) {
    document.getElementById('overlay').classList.add('open');
    document.getElementById(id).classList.add('open');
    if (id === 'bookPanel') this.renderMgr();
    if (id === 'gearPanel') {
      document.getElementById('setKcal').value = this.state.day?.targets.kcal ?? '';
      document.getElementById('setProtein').value = this.state.day?.targets.protein ?? '';
    }
  },

  closePanels() {
    document.getElementById('overlay').classList.remove('open');
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('open'));
  },

  renderMgr() {
    const foods = document.getElementById('mgrFoodList');
    foods.innerHTML = '';
    for (const f of this.state.foods) {
      foods.appendChild(this.mgrItem(
        f.name,
        this.fmt(f.kcal_per_100g) + ' kcal · ' + this.fmt(f.protein_per_100g) + ' g / 100 g',
        () => {
          document.getElementById('ffId').value = f.id;
          document.getElementById('ffName').value = f.name;
          document.getElementById('ffKcal').value = f.kcal_per_100g;
          document.getElementById('ffProtein').value = f.protein_per_100g;
          document.getElementById('ffSave').textContent = 'Save food';
          document.getElementById('ffCancel').hidden = false;
        },
        async () => {
          if (!confirm('Remove "' + f.name + '" from favourites? Logged history keeps its numbers.')) return;
          await this.api('food.delete', { id: f.id });
          await this.loadFavs();
          this.renderMgr();
        }
      ));
    }
    const meals = document.getElementById('mgrMealList');
    meals.innerHTML = '';
    for (const m of this.state.meals) {
      meals.appendChild(this.mgrItem(
        m.name,
        this.fmt(m.kcal) + ' kcal · ' + this.fmt(m.protein) + ' g',
        () => this.editMeal(m),
        async () => {
          if (!confirm('Remove meal "' + m.name + '"? Logged history keeps its numbers.')) return;
          await this.api('meal.delete', { id: m.id });
          await this.loadFavs();
          this.renderMgr();
        }
      ));
    }
  },

  mgrItem(name, sub, onEdit, onDelete) {
    const d = document.createElement('div');
    d.className = 'mgr-item';
    const head = document.createElement('div');
    head.className = 'head';
    const nm = document.createElement('span');
    nm.className = 'nm';
    nm.textContent = name;
    const k = document.createElement('span');
    k.className = 'k';
    k.textContent = sub;
    head.append(nm, k);
    const acts = document.createElement('div');
    acts.className = 'acts';
    const eb = document.createElement('button');
    eb.textContent = 'Edit';
    eb.addEventListener('click', onEdit);
    const db = document.createElement('button');
    db.className = 'danger';
    db.textContent = 'Remove';
    db.addEventListener('click', onDelete);
    acts.append(eb, db);
    d.append(head, acts);
    return d;
  },

  // ---------- meal editor ----------

  mealItemRow(kind, preset) {
    const row = document.createElement('div');
    row.className = 'itemrow';
    row.dataset.kind = kind;
    if (kind === 'food') {
      const sel = document.createElement('select');
      sel.style.cssText = 'flex:2;min-width:0;padding:9px 6px;border:1.5px solid var(--line);border-radius:10px;background:var(--bg);font-weight:600;color:inherit';
      for (const f of this.state.foods) {
        const o = document.createElement('option');
        o.value = f.id;
        o.textContent = f.name;
        sel.appendChild(o);
      }
      if (preset?.food_id) sel.value = preset.food_id;
      const g = document.createElement('input');
      g.type = 'number'; g.min = '1'; g.max = '5000'; g.placeholder = 'g';
      g.style.flex = '1';
      g.value = preset?.grams ?? '';
      sel.addEventListener('change', () => this.mealFormTotal());
      g.addEventListener('input', () => this.mealFormTotal());
      row.append(sel, g);
    } else {
      const lbl = document.createElement('input');
      lbl.type = 'text'; lbl.maxLength = 80; lbl.placeholder = 'label';
      lbl.style.flex = '2';
      lbl.value = preset?.raw_label ?? '';
      const k = document.createElement('input');
      k.type = 'number'; k.min = '0'; k.placeholder = 'kcal';
      k.style.flex = '1';
      k.value = preset?.raw_kcal ?? '';
      const p = document.createElement('input');
      p.type = 'number'; p.min = '0'; p.placeholder = 'prot g';
      p.style.flex = '1';
      p.value = preset?.raw_protein ?? '';
      k.addEventListener('input', () => this.mealFormTotal());
      p.addEventListener('input', () => this.mealFormTotal());
      row.append(lbl, k, p);
    }
    const rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'rm';
    rm.textContent = '✕';
    rm.addEventListener('click', () => { row.remove(); this.mealFormTotal(); });
    row.appendChild(rm);
    document.getElementById('mfItems').appendChild(row);
    this.mealFormTotal();
  },

  mealFormItems() {
    const items = [];
    for (const row of document.querySelectorAll('#mfItems .itemrow')) {
      if (row.dataset.kind === 'food') {
        const [sel, g] = row.querySelectorAll('select, input');
        if (sel.value && parseFloat(g.value) > 0) items.push({ food_id: +sel.value, grams: +g.value });
      } else {
        const [lbl, k, p] = row.querySelectorAll('input');
        if (k.value !== '' || p.value !== '') {
          items.push({ raw_label: lbl.value, raw_kcal: +k.value || 0, raw_protein: +p.value || 0 });
        }
      }
    }
    return items;
  },

  mealFormTotal() {
    let kcal = 0, protein = 0;
    for (const it of this.mealFormItems()) {
      if (it.food_id) {
        const f = this.state.foods.find(f => f.id === it.food_id);
        if (f) { kcal += f.kcal_per_100g * it.grams / 100; protein += f.protein_per_100g * it.grams / 100; }
      } else {
        kcal += it.raw_kcal; protein += it.raw_protein;
      }
    }
    document.getElementById('mfTotal').textContent =
      this.fmt(Math.round(kcal * 10) / 10) + ' kcal · ' + this.fmt(Math.round(protein * 10) / 10) + ' g protein';
  },

  editMeal(m) {
    document.getElementById('mfId').value = m.id;
    document.getElementById('mfName').value = m.name;
    document.getElementById('mfItems').innerHTML = '';
    for (const it of m.items) this.mealItemRow(it.food_id ? 'food' : 'raw', it);
    document.getElementById('mfSave').textContent = 'Save meal';
    document.getElementById('mfCancel').hidden = false;
  },

  resetFoodForm() {
    document.getElementById('foodForm').reset();
    document.getElementById('ffId').value = '';
    document.getElementById('ffSave').textContent = 'Add food';
    document.getElementById('ffCancel').hidden = true;
  },

  resetMealForm() {
    document.getElementById('mealForm').reset();
    document.getElementById('mfId').value = '';
    document.getElementById('mfItems').innerHTML = '';
    document.getElementById('mfTotal').textContent = '';
    document.getElementById('mfSave').textContent = 'Add meal';
    document.getElementById('mfCancel').hidden = true;
  },
};

document.getElementById('prevDay').addEventListener('click', () => App.shiftDay(-1));
document.getElementById('nextDay').addEventListener('click', () => App.shiftDay(1));
document.getElementById('datePick').addEventListener('change', (e) => App.load(e.target.value));
document.getElementById('weightIn').addEventListener('change', async (e) => {
  await App.api('weight.set', { day: App.state.date, kg: e.target.value });
  App.load(App.state.date);
});

for (const t of document.querySelectorAll('.tabs button')) {
  t.addEventListener('click', () => App.setTab(t.id));
}
document.getElementById('foodSearch').addEventListener('input', (e) => {
  App.state.selFood = null;
  App.renderFoodList(e.target.value);
  App.foodPreview();
});
document.getElementById('foodGrams').addEventListener('input', () => App.foodPreview());
document.getElementById('foodAdd').addEventListener('click', async () => {
  const g = parseFloat(document.getElementById('foodGrams').value);
  if (!App.state.selFood || !(g > 0)) return;
  await App.addEntry({ type: 'food', food_id: App.state.selFood.id, grams: g });
  document.getElementById('foodSearch').value = '';
  App.state.selFood = null;
  App.renderFoodList();
  App.foodPreview();
});
document.getElementById('quickAdd').addEventListener('click', async () => {
  const kcal = parseFloat(document.getElementById('quickKcal').value);
  if (!(kcal >= 0)) { App.toast('kcal is required'); return; }
  await App.addEntry({
    type: 'quick',
    kcal,
    protein: parseFloat(document.getElementById('quickProtein').value) || 0,
    label: document.getElementById('quickLabel').value,
  });
  for (const id of ['quickKcal', 'quickProtein', 'quickLabel']) document.getElementById(id).value = '';
});

document.getElementById('openBook').addEventListener('click', () => App.openPanel('bookPanel'));
document.getElementById('openGear').addEventListener('click', () => App.openPanel('gearPanel'));
document.getElementById('overlay').addEventListener('click', () => App.closePanels());
document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => App.closePanels()));

document.getElementById('mtabFoods').addEventListener('click', () => {
  document.getElementById('mtabFoods').classList.add('on');
  document.getElementById('mtabMeals').classList.remove('on');
  document.getElementById('mgrFoods').hidden = false;
  document.getElementById('mgrMeals').hidden = true;
});
document.getElementById('mtabMeals').addEventListener('click', () => {
  document.getElementById('mtabMeals').classList.add('on');
  document.getElementById('mtabFoods').classList.remove('on');
  document.getElementById('mgrMeals').hidden = false;
  document.getElementById('mgrFoods').hidden = true;
});

document.getElementById('foodForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = document.getElementById('ffId').value;
  await App.api('food.save', {
    id: id ? +id : undefined,
    name: document.getElementById('ffName').value,
    kcal_per_100g: +document.getElementById('ffKcal').value,
    protein_per_100g: +document.getElementById('ffProtein').value,
  });
  App.resetFoodForm();
  await App.loadFavs();
  App.renderMgr();
});
document.getElementById('ffCancel').addEventListener('click', () => App.resetFoodForm());

document.getElementById('mfAddFoodItem').addEventListener('click', () => App.mealItemRow('food'));
document.getElementById('mfAddRawItem').addEventListener('click', () => App.mealItemRow('raw'));
document.getElementById('mealForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const items = App.mealFormItems();
  if (!items.length) { App.toast('a meal needs at least one item'); return; }
  const id = document.getElementById('mfId').value;
  await App.api('meal.save', {
    id: id ? +id : undefined,
    name: document.getElementById('mfName').value,
    items,
  });
  App.resetMealForm();
  await App.loadFavs();
  App.renderMgr();
});
document.getElementById('mfCancel').addEventListener('click', () => App.resetMealForm());

document.getElementById('setSave').addEventListener('click', async () => {
  await App.api('targets.set', {
    kcal_target: +document.getElementById('setKcal').value,
    protein_target: +document.getElementById('setProtein').value,
  });
  App.closePanels();
  App.load(App.state.date);
});
document.getElementById('logoutBtn').addEventListener('click', async () => {
  await App.api('logout', {});
  location.reload();
});

App.setTab(['tabFood', 'tabMeal', 'tabQuick'].includes(localStorage.getItem('glom_tab')) ? localStorage.getItem('glom_tab') : 'tabFood');
App.load();
App.loadFavs();
</script>
<?php endif; ?>
</body>
</html>
<?php
}
