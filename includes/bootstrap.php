<?php
/**
 * bootstrap.php - shared setup: config, database, sessions, security and helpers.
 * Every page loads this file first.
 */

$GLOBALS['app_config'] = require __DIR__ . '/../config.php';
date_default_timezone_set($GLOBALS['app_config']['timezone']);


if (session_status() === PHP_SESSION_NONE) {
    session_name('votehub_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Expire authenticated sessions after 30 minutes without activity.
$idleTimeout = 30 * 60;
if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $idleTimeout) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['flash'] = ['type' => 'info', 'msg' => 'You were logged out after 30 minutes of inactivity. Please sign in again.'];
}
$_SESSION['last_activity'] = time();

function config(): array
{
    return $GLOBALS['app_config'];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            ensure_feature_schema($pdo);
        } catch (PDOException $ex) {
            http_response_code(500);
            exit('<h2>Database connection failed</h2><p>Check the database settings in config.php and make sure MySQL is running.</p>');
        }
    }
    return $pdo;
}

/** Additive, safe migrations for existing local installs. No election data is removed. */
function ensure_feature_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = array_column($pdo->query('SHOW COLUMNS FROM elections')->fetchAll(), 'Field');
    if (!in_array('results_published_at', $columns, true)) {
        $pdo->exec('ALTER TABLE elections ADD COLUMN results_published_at DATETIME NULL');
    }
    if (!in_array('voting_override', $columns, true)) {
        $pdo->exec("ALTER TABLE elections ADD COLUMN voting_override ENUM('scheduled','open','closed') NOT NULL DEFAULT 'scheduled'");
    }
    $voteUnique = $pdo->query("SHOW INDEX FROM votes WHERE Key_name = 'uq_one_vote_per_position'")->fetch();
    if (!$voteUnique) {
        $pdo->exec('ALTER TABLE votes ADD UNIQUE KEY uq_one_vote_per_position (voter_id, position_id)');
    }

    $receiptsExisted = (bool)$pdo->query("SHOW TABLES LIKE 'participation_receipts'")->fetchColumn();
    $pdo->exec("CREATE TABLE IF NOT EXISTS participation_receipts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_id INT UNSIGNED NOT NULL,
        voter_id INT UNSIGNED NOT NULL,
        receipt_code CHAR(32) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_receipt_voter_election (voter_id, election_id),
        CONSTRAINT fk_receipts_election FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE,
        CONSTRAINT fk_receipts_voter FOREIGN KEY (voter_id) REFERENCES voters(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    if (!$receiptsExisted) {
        // Give existing ballots a participation-only receipt during the one-time migration.
        $pdo->exec("INSERT INTO participation_receipts (election_id, voter_id, receipt_code)
            SELECT election_id, voter_id, UPPER(REPLACE(UUID(), '-', '')) FROM votes GROUP BY election_id, voter_id");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        details TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created (created_at),
        CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS account_help_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(30) NULL,
        reason VARCHAR(80) NOT NULL,
        message TEXT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX idx_help_status_created (status, created_at)
    ) ENGINE=InnoDB");
    $done = true;
}

/** Record an administrator action without storing passwords or ballot choices. */
function audit_log(string $action, string $entityType, ?int $entityId = null, ?string $details = null): void
{
    if (empty($_SESSION['admin_id'])) {
        return;
    }
    $stmt = db()->prepare('INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([(int)$_SESSION['admin_id'], $action, $entityType, $entityId, $details]);
}

/** Escape any value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a full URL to a project path, respecting base_url. */
function url(string $path = ''): string
{
    return rtrim(config()['base_url'], '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/* ---------- flash messages (shown once after a redirect) ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------- CSRF protection ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(string $backTo): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        flash('error', 'Your session expired. Please try again.');
        redirect($backTo);
    }
}

/* ---------- logged-in users (voters and admins use separate sessions keys) ---------- */

function current_voter(): ?array
{
    if (empty($_SESSION['voter_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM voters WHERE id = ?');
    $stmt->execute([(int)$_SESSION['voter_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['voter_id']);
        return null;
    }
    return $row;
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['admin_id']);
        return null;
    }
    return $row;
}

function require_voter(): array
{
    $voter = current_voter();
    if (!$voter) {
        flash('error', 'Please log in as a voter to continue.');
        redirect('index.php?tab=voter-login');
    }
    return $voter;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        flash('error', 'Please log in as an administrator to continue.');
        redirect('index.php?tab=admin-login');
    }
    return $admin;
}

/* ---------- dates and times (all checks use the configured timezone) ---------- */

function timezone(): DateTimeZone
{
    return new DateTimeZone(config()['timezone']);
}

function now_local(): DateTimeImmutable
{
    return new DateTimeImmutable('now', timezone());
}

/** Current time floored to the minute, so "now" compares fairly with minute-level inputs. */
function now_minute(): DateTimeImmutable
{
    return new DateTimeImmutable(now_local()->format('Y-m-d H:i:00'), timezone());
}

/** Parse the value from an HTML datetime-local input (e.g. 2026-11-18T14:30). */
function parse_local_datetime(string $raw): ?DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, timezone());
    return $dt ?: null;
}

function to_db(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d H:i:s');
}

function format_when(string $dbValue): string
{
    return (new DateTimeImmutable($dbValue, timezone()))->format('D j M Y, g:i A');
}

/** Return an exact ratio as a compact percentage, without an averaged or forced whole-number value. */
function percentage_value(int|float $part, int|float $whole, int $precision = 1): string
{
    if ($whole <= 0) return '0';
    $value = number_format(round(($part / $whole) * 100, $precision), $precision, '.', '');
    return rtrim(rtrim($value, '0'), '.');
}

/** upcoming (before start), live (between start and end), closed (after end). */
function election_status(array $election): string
{
    if (($election['voting_override'] ?? 'scheduled') === 'open') {
        return 'live';
    }
    if (($election['voting_override'] ?? 'scheduled') === 'closed') {
        return 'closed';
    }
    $now = now_local();
    $start = new DateTimeImmutable($election['start_at'], timezone());
    $end = new DateTimeImmutable($election['end_at'], timezone());
    if ($now < $start) {
        return 'upcoming';
    }
    if ($now >= $end) {
        return 'closed';
    }
    return 'live';
}

function status_badge(string $status): string
{
    $labels = [
        'upcoming'  => 'Upcoming',
        'live'      => 'Live now',
        'closed'    => 'Closed',
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    ];
    return '<span class="badge badge-' . e($status) . '">' . e($labels[$status] ?? ucfirst($status)) . '</span>';
}

/* ---------- elections, positions, candidates ---------- */

function find_election(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM elections WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Returns every position of an election, each with its candidates. */
function election_ballot(int $electionId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM positions WHERE election_id = ? ORDER BY id');
    $stmt->execute([$electionId]);
    $positions = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE election_id = ? ORDER BY full_name');
    $stmt->execute([$electionId]);
    $byPosition = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $byPosition[(int)$candidate['position_id']][] = $candidate;
    }

    foreach ($positions as $i => $position) {
        $positions[$i]['candidates'] = $byPosition[(int)$position['id']] ?? [];
    }
    return $positions;
}

/** Vote totals and percentages for every position of an election. */
function election_results(int $electionId): array
{
    $pdo = db();
    $positions = election_ballot($electionId);

    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT voter_id) FROM votes WHERE election_id = ?');
    $stmt->execute([$electionId]);
    $voters = (int)$stmt->fetchColumn();
    $approved = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE status = 'approved'")->fetchColumn();

    foreach ($positions as $i => $position) {
        $stmt = $pdo->prepare('SELECT candidate_id, COUNT(*) AS total FROM votes WHERE election_id = ? AND position_id = ? GROUP BY candidate_id');
        $stmt->execute([$electionId, $position['id']]);

        $counts = [];
        $positionTotal = 0;
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int)$row['candidate_id']] = (int)$row['total'];
            $positionTotal += (int)$row['total'];
        }

        foreach ($position['candidates'] as $j => $candidate) {
            $votes = $counts[(int)$candidate['id']] ?? 0;
            $position['candidates'][$j]['votes'] = $votes;
            $position['candidates'][$j]['percent'] = $positionTotal ? round($votes / $positionTotal * 100, 1) : 0;
        }
        $voterCount = $pdo->prepare('SELECT COUNT(DISTINCT voter_id) FROM votes WHERE election_id = ? AND position_id = ?');
        $voterCount->execute([$electionId, $position['id']]);
        $position['voters_voted'] = (int)$voterCount->fetchColumn();
        usort($position['candidates'], fn($a, $b) => ($b['votes'] <=> $a['votes']) ?: strcmp($a['full_name'], $b['full_name']));

        $topVotes = $position['candidates'] ? (int)$position['candidates'][0]['votes'] : 0;
        $leaders = $topVotes > 0
            ? array_values(array_filter($position['candidates'], fn($candidate) => (int)$candidate['votes'] === $topVotes))
            : [];
        $position['winners'] = array_column($leaders, 'full_name');
        $position['is_tie'] = count($leaders) > 1;

        $position['total'] = $positionTotal;
        $positions[$i] = $position;
    }

    return ['positions' => $positions, 'voters' => $voters, 'approved' => $approved];
}

/* ---------- candidate photos ---------- */

function candidate_photo_url(?string $file): string
{
    // Falls back to the VOTE artwork if a photo is missing.
    return $file ? url('assets/uploads/candidates/' . $file) : url('assets/img/vote-watermark.png');
}

/** Validates and stores an uploaded candidate photo. Returns the stored file name. */
function save_candidate_photo(array $file): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload a photo for the candidate.');
    }
    if (($file['size'] ?? 0) > config()['upload_max_bytes']) {
        throw new RuntimeException('The photo must be 2 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('The photo must be a JPG, PNG or WEBP image.');
    }

    $dir = __DIR__ . '/../assets/uploads/candidates';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('The photo could not be saved. Check the uploads folder permissions.');
    }
    return $name;
}

function delete_candidate_photo(?string $file): void
{
    if ($file && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file)) {
        @unlink(__DIR__ . '/../assets/uploads/candidates/' . $file);
    }
}
