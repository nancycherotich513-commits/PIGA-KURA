<?php
/**
 * layout.php - shared responsive shell, navigation and display blocks.
 */

function nav_items(string $area): array
{
    if ($area === 'voter') {
        return [
            ['Dashboard', 'voter/dashboard.php'],
            ['My ballot', 'voter/ballot.php'],
            ['Receipts', 'voter/receipts.php'],
            ['Results', 'voter/results.php'],
            ['Election info', 'voter/election_info.php'],
            ['Account help', 'auth/account_help.php'],
        ];
    }
    if ($area === 'admin') {
        return [
            ['Dashboard', 'admin/dashboard.php'],
            ['Elections', 'admin/elections.php'],
            ['Positions', 'admin/positions.php'],
            ['Candidates', 'admin/candidates.php'],
            ['Voters', 'admin/voters.php'],
            ['Results', 'admin/results_list.php'],
            ['Audit log', 'admin/audit.php'],
            ['Account help', 'admin/help.php'],
            ['Data safety', 'admin/backup.php'],
        ];
    }
    return [];
}

function render_head(string $title, string $area = 'public'): void
{
    $flash = take_flash();
    $brandLink = $area === 'admin' ? 'admin/dashboard.php' : ($area === 'voter' ? 'voter/dashboard.php' : 'index.php');
    $areaLabel = $area === 'admin' ? 'Admin' : ($area === 'voter' ? 'Voter' : '');
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
    $styleFile = __DIR__ . '/../assets/css/style.css';
    $styleVersion = is_file($styleFile) ? (string)filemtime($styleFile) : '1';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(config()['app_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . $styleVersion)) ?>">
</head>
<body class="area-<?= e($area) ?>">
<header class="topbar topbar-<?= e($area) ?>">
    <div class="topbar-inner">
        <a class="brand" href="<?= e(url($brandLink)) ?>">
            <span class="brand-mark" aria-hidden="true">V</span>
            <span class="brand-name"><?= e(config()['app_name']) ?></span>
            <?php if ($areaLabel): ?><small><?= e($areaLabel) ?></small><?php endif; ?>
        </a>
        <?php if ($area !== 'public'): ?>
            <p class="sidebar-section-label"><?= $area === 'admin' ? 'Management' : 'Voting' ?></p>
            <nav class="nav" aria-label="<?= $area === 'admin' ? 'Admin' : 'Voter' ?> navigation">
                <?php foreach (nav_items($area) as [$label, $path]): ?>
                    <a href="<?= e(url($path)) ?>" class="<?= str_ends_with($currentScript, '/' . $path) ? 'active' : '' ?>">
                        <span class="nav-icon" aria-hidden="true"><?= match ($label) {
                            'Dashboard', 'My elections' => '⌂', 'Elections' => '▣', 'Positions' => '☷',
                            'Candidates' => '♙', 'My ballot' => '▤', 'Receipts' => '✓', 'Results' => '▥',
                            'Election info' => 'ⓘ', 'Account help' => '?', default => '◎'
                        } ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
                <a href="<?= e(url('auth/logout.php')) ?>" class="nav-logout"><span class="nav-icon" aria-hidden="true">↪</span><span>Log out</span></a>
            </nav>
            <div class="sidebar-note"><span class="sidebar-note-mark">✓</span><span><strong>Secure voting</strong><small>Your voice matters</small></span></div>
        <?php endif; ?>
    </div>
</header>
<main class="container">
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['msg']) ?></div>
    <?php endif; ?>
<?php
}

function render_foot(): void
{
    $scriptFile = __DIR__ . '/../assets/js/app.js';
    $scriptVersion = is_file($scriptFile) ? (string)filemtime($scriptFile) : '1';
    ?>
</main>
<footer class="footer">
    © <?= date('Y') ?> <?= e(config()['app_name']) ?> · Every vote counts
</footer>
<script src="<?= e(url('assets/js/app.js?v=' . $scriptVersion)) ?>"></script>
</body>
</html>
<?php
}

/** Summary cards and a bar chart per position. Used by voter and admin results pages. */
function render_results_block(array $results, bool $final = false): void
{
    $turnout = percentage_value($results['voters'], $results['approved']);
    ?>
    <div class="stats">
        <div class="stat"><strong><?= (int)$results['voters'] ?></strong><span>Eligible voters who voted</span></div>
        <div class="stat alt"><strong><?= $turnout ?>%</strong><span>Turnout of approved voters</span></div>
    </div>

    <?php if (!$results['positions']): ?>
        <div class="card empty">No positions have been added to this election yet.</div>
    <?php endif; ?>

    <?php foreach ($results['positions'] as $position):
        $max = 0;
        foreach ($position['candidates'] as $c) {
            $max = max($max, $c['votes']);
        }
    ?>
        <section class="position">
            <div class="position-head">
                <h2><?= e($position['title']) ?></h2>
                <span class="muted"><?= (int)$position['voters_voted'] ?> voter(s) voted</span>
            </div>
            <?php if ($position['total'] > 0): ?>
                <p class="result-outcome <?= $position['is_tie'] ? 'result-tie' : 'result-winner' ?>">
                    <?php if ($position['is_tie']): ?>Tie between <?= e(implode(', ', $position['winners'])) ?>
                    <?php else: ?>Winner: <?= e($position['winners'][0]) ?><?php endif; ?>
                </p>
            <?php elseif ($final): ?>
                <p class="result-outcome result-no-winner">No votes were cast for this position.</p>
            <?php endif; ?>
            <?php if (!$position['candidates']): ?>
                <p class="muted">No candidates for this position.</p>
            <?php endif; ?>
            <?php foreach ($position['candidates'] as $c):
                $leading = $max > 0 && $c['votes'] === $max;
            ?>
                <div class="result-row <?= $leading ? 'leader' : '' ?>">
                    <img src="<?= e(candidate_photo_url($c['photo'])) ?>" alt="<?= e($c['full_name']) ?>">
                    <div>
                        <strong><?= e($c['full_name']) ?></strong>
                        <?php if ($leading): ?><span class="lead-tag"><?= $final ? ($position['is_tie'] ? 'Tied winner' : 'Winner') : 'Leading' ?></span><?php endif; ?>
                        <div class="bar"><span style="width: <?= (float)$c['percent'] ?>%"></span></div>
                    </div>
                    <div class="result-count"><?= (int)$c['votes'] ?> votes · <?= $c['percent'] ?>%</div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php
}
