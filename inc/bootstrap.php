<?php
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
const APP_NAME = 'Codice Stat';
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp',
    'txt', 'md', 'png', 'jpg', 'jpeg', 'zip', 'r', 'rmd', 'py', 'ipynb', 'sas', 'do', 'sql'];
const TOPIC_CATEGORIES = ['Lavoro', 'Concorsi', 'Nuove posizioni', 'Idee', 'Formazione', 'Varie'];

// Domande del cancello d'ingresso: servono almeno 2 risposte giuste su 3.
const QUIZ = [
    ['q' => 'Completa la frase. Correlazione non è:', 'options' => ['Sempre vera', 'Causalità', 'Robusta'], 'answer' => 1],
    ['q' => 'A quale carta tiene molto Marco Sisti?', 'options' => ['Credito', 'Docente', 'Forno'], 'answer' => 1],
    ['q' => 'Filippo Maurici di che zona è?', 'options' => ['Parioli', 'Non è di Roma', 'Vittorio Emanuele'], 'answer' => 2],
];
const QUIZ_MIN_CORRECT = 2;

date_default_timezone_set('Europe/Rome');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DATA_DIR . '/stat.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        migrate($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    bio TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    event_date TEXT NOT NULL,
    event_time TEXT NOT NULL DEFAULT '',
    location TEXT NOT NULL DEFAULT '',
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS event_rsvp (
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (event_id, user_id)
);
CREATE TABLE IF NOT EXISTS topics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'Varie',
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    parent_id INTEGER REFERENCES comments(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS votes (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_type TEXT NOT NULL CHECK (target_type IN ('topic', 'comment')),
    target_id INTEGER NOT NULL,
    value INTEGER NOT NULL CHECK (value IN (-1, 1)),
    PRIMARY KEY (user_id, target_type, target_id)
);
CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    size INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
SQL);

    $seeded = $pdo->query("SELECT v FROM meta WHERE k = 'seeded'")->fetchColumn();
    if (!$seeded) {
        $pdo->prepare('INSERT INTO events (title, description, event_date, event_time, location, user_id, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)')
            ->execute([
                'Cena per l\'anno di servizio',
                'Un anno in Ragioneria: festeggiamo insieme. Segna la tua presenza qui sotto così organizziamo la prenotazione.',
                '2026-12-02', '', 'Da definire', time(),
            ]);
        $pdo->exec("INSERT INTO meta (k, v) VALUES ('seeded', '1')");
    }
}

// ---------- helper ----------

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Testo utente: escape, link cliccabili, a capo. */
function rich(string $s): string
{
    $html = e($s);
    $html = preg_replace(
        '~(https?://[^\s<]+)~u',
        '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>',
        $html
    );
    return nl2br($html);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessione scaduta, torna indietro e ricarica la pagina.');
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect(empty($_SESSION['quiz_passed']) ? 'gate.php' : 'auth.php');
    }
    return $u;
}

const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const MONTHS_LONG = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto',
    'settembre', 'ottobre', 'novembre', 'dicembre'];
const WEEKDAYS = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];

function fmt_day(int $ts): string
{
    return date('j', $ts) . ' ' . MONTHS[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) return 'adesso';
    if ($d < 3600) return intdiv($d, 60) . ' min fa';
    if ($d < 86400) return intdiv($d, 3600) . ' h fa';
    if ($d < 7 * 86400) {
        $n = intdiv($d, 86400);
        return $n === 1 ? 'ieri' : "$n giorni fa";
    }
    return fmt_day($ts);
}

function fmt_size(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' KB';
    return number_format($b / 1048576, 1, ',', '') . ' MB';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

function avatar_color(string $name): string
{
    $palette = ['var(--coral)', 'var(--teal)', 'var(--sun)', 'var(--lilac)', 'var(--sky)'];
    return $palette[crc32(mb_strtolower($name)) % count($palette)];
}

function post_str(string $key, int $max = 10000): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}
