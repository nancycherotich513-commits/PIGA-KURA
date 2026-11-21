<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$pdo = db();
$stats = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE status = 'approved'")->fetchColumn(),
    'voters_voted' => (int)$pdo->query('SELECT COUNT(DISTINCT voter_id) FROM votes')->fetchColumn(),
];
$elections = $pdo->query(
    'SELECT e.*,
       (SELECT COUNT(*) FROM positions p WHERE p.election_id=e.id) AS position_count,
       (SELECT COUNT(*) FROM candidates c WHERE c.election_id=e.id) AS candidate_count,
       (SELECT COUNT(*) FROM candidates c WHERE c.election_id=e.id AND c.photo IS NOT NULL AND c.photo <> "") AS photo_count,
       (SELECT COUNT(*) FROM votes v WHERE v.election_id=e.id) AS vote_count,
       (SELECT COUNT(DISTINCT v.voter_id) FROM votes v WHERE v.election_id=e.id) AS voter_count
     FROM elections e ORDER BY e.start_at DESC'
)->fetchAll();
$order = ['live'=>0,'upcoming'=>1,'closed'=>2];
foreach ($elections as &$election) $election['status'] = election_status($election);
unset($election);
usort($elections, fn($a,$b) => ($order[$a['status']] <=> $order[$b['status']]) ?: strcmp($a['start_at'],$b['start_at']));
$live = array_values(array_filter($elections, fn($e) => $e['status'] === 'live'));
$upcomingElections = array_values(array_filter($elections, fn($e) => $e['status'] === 'upcoming'));
$upcoming = count($upcomingElections);
$closed = count(array_filter($elections, fn($e) => $e['status'] === 'closed'));
$turnout = percentage_value($stats['voters_voted'], $stats['approved']);
$pendingList = $pdo->query("SELECT id, full_name, email FROM voters WHERE status='pending' ORDER BY created_at LIMIT 4")->fetchAll();
$activeElections = array_values(array_filter($elections, fn($e) => in_array($e['status'], ['live', 'upcoming'], true)));
$focus = $live[0] ?? ($upcomingElections[0] ?? null);
render_head('Command centre','admin');
?>
<section class="dashboard-hero"><div><p class="dashboard-eyebrow">Election management portal</p><h1>Command centre</h1><p class="dashboard-subtitle">Signed in as <?= e($admin['email']) ?> · <?= e(now_local()->format('l j F Y')) ?></p></div><div class="dashboard-actions"><a class="btn dashboard-white-btn" href="<?= e(url('admin/elections.php')) ?>">+ New election</a><a class="btn dashboard-outline-btn" href="<?= e(url('admin/voters.php')) ?>">Review voters</a></div></section>
<?php if ($live): $active=$live[0]; ?><a class="dashboard-live" href="<?= e(url('admin/results.php?id='.(int)$active['id'])) ?>"><span class="live-dot"></span><span><strong><?= count($live) ?> <?= count($live)===1?'election is':'elections are' ?> live now.</strong> Voting is open.</span><span class="dashboard-live-link">View live results →</span></a><?php elseif ($upcomingElections): $next=$upcomingElections[0]; ?><a class="dashboard-live dashboard-upcoming" href="<?= e(url('admin/results.php?id='.(int)$next['id'])) ?>"><span class="live-dot"></span><span><strong>No election is live.</strong> Next: <?= e($next['title']) ?> opens <?= e(format_when($next['start_at'])) ?>.</span><span class="dashboard-live-link">View election →</span></a><?php else: ?><div class="dashboard-live dashboard-idle"><span class="live-dot"></span><span><strong>No live or upcoming elections.</strong> <?= $closed ?> election<?= $closed===1?' is':'s are' ?> closed.</span><a class="dashboard-live-link" href="<?= e(url('admin/elections.php')) ?>">Manage elections →</a></div><?php endif; ?>
<section class="dashboard-stats" aria-label="Election summary">
<a class="dashboard-stat stat-red" href="<?= e(url('admin/voters.php?status=pending')) ?>"><span class="stat-icon">◷</span><strong><?= $stats['pending'] ?></strong><span>Awaiting approval</span></a>
<a class="dashboard-stat" href="<?= e(url('admin/voters.php?status=approved')) ?>"><span class="stat-icon">✓</span><strong><?= $stats['approved'] ?></strong><span>Approved voters</span></a>
<div class="dashboard-stat"><span class="stat-icon">▤</span><strong><?= $stats['voters_voted'] ?></strong><span>Voters voted · <?= e($turnout) ?>% of approved voters</span></div>
<a class="dashboard-stat" href="<?= e(url('admin/elections.php')) ?>"><span class="stat-icon">◉</span><strong><?= $live ? count($live).' live' : ($upcoming ? $upcoming.' upcoming' : 'None active') ?></strong><span><?= $live ? $upcoming.' upcoming · '.$closed.' closed' : ($upcoming ? 'Not open yet · '.$closed.' closed' : 'No live or upcoming elections · '.$closed.' closed') ?></span></a>
</section>
<div class="dashboard-columns"><section class="dashboard-panel"><div class="dashboard-heading"><h2>Live and upcoming elections</h2><a href="<?= e(url('admin/elections.php')) ?>">Manage all →</a></div>
<?php if (!$activeElections): ?><div class="dashboard-empty"><strong>No live or upcoming elections</strong><span>Create or schedule an election to open voting.</span><a class="btn btn-primary btn-sm" href="<?= e(url('admin/elections.php')) ?>">Manage elections</a></div><?php else: foreach(array_slice($activeElections,0,4) as $e): $duration=max(1,strtotime($e['end_at'])-strtotime($e['start_at'])); $elapsed=max(0,min($duration,now_local()->getTimestamp()-strtotime($e['start_at']))); $progress=$e['status']==='upcoming'?0:(int)round($elapsed/$duration*100); $eTurnout=percentage_value($e['voter_count'],$stats['approved']); ?>
<article class="dashboard-election"><div class="dashboard-election-title"><span class="dashboard-badge badge-<?= e($e['status']) ?>"><?= e($e['status']==='live'?'Live now':ucfirst($e['status'])) ?></span><a href="<?= e(url('admin/results.php?id='.(int)$e['id'])) ?>"><?= e($e['title']) ?></a></div><p><?= $e['status']==='upcoming'?'Opens '.e(format_when($e['start_at'])):'Opened '.e(format_when($e['start_at'])) ?> · Closes <?= e(format_when($e['end_at'])) ?></p><div class="dashboard-progress" role="progressbar" aria-label="Election time elapsed" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $progress ?>%"></span></div><p><?= (int)$e['position_count'] ?> positions · <?= (int)$e['candidate_count'] ?> candidates · <?= (int)$e['vote_count'] ?> votes cast · <?= $eTurnout ?>% turnout</p></article>
<?php endforeach; endif; ?></section><div class="dashboard-side">
<section class="dashboard-panel"><div class="dashboard-heading"><h2>Pending approvals</h2><a href="<?= e(url('admin/voters.php?status=pending')) ?>">View all →</a></div>
<?php if (!$pendingList): ?><div class="dashboard-empty compact"><strong>You're all caught up</strong><span>No voters are waiting for approval.</span></div><?php else: foreach($pendingList as $row): ?><div class="approval-row"><div><strong><?= e($row['full_name']) ?></strong><span><?= e($row['email']) ?></span></div><form method="post" action="<?= e(url('admin/voters.php?status=pending')) ?>"><?= csrf_field() ?><input type="hidden" name="voter_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn btn-primary btn-sm">Approve</button></form></div><?php endforeach; endif; ?></section>
<section class="dashboard-panel"><div class="dashboard-heading"><h2>Election readiness</h2><?php if($focus): ?><a href="<?= e(url('admin/elections.php')) ?>">Setup →</a><?php endif; ?></div>
<?php if(!$focus): ?><p class="readiness-intro">Create an election to see its setup checklist.</p><a class="check-item" href="<?= e(url('admin/elections.php')) ?>"><span class="check-icon">＋</span><span>Create an election</span></a><?php else: $checks=[['Election created',true,'admin/elections.php'],['Positions added',(int)$focus['position_count']>0,'admin/positions.php'],['Candidates added with photos',(int)$focus['candidate_count']>0&&(int)$focus['photo_count']>=(int)$focus['candidate_count'],'admin/candidates.php'],['Approved voters',$stats['approved']>0,'admin/voters.php?status=approved']]; ?>
<p class="readiness-intro">Setup checklist for <strong><?= e($focus['title']) ?></strong></p><?php foreach($checks as [$label,$done,$path]): ?><a class="check-item <?= $done?'is-done':'' ?>" href="<?= e(url($path)) ?>"><span class="check-icon"><?= $done?'✓':'○' ?></span><span><?= e($label) ?></span><small><?= $done?'Done':'Set up' ?></small></a><?php endforeach; ?><?php endif; ?><?php if($stats['pending']): ?><a class="clear-pending" href="<?= e(url('admin/voters.php?status=pending')) ?>"><?= $stats['pending'] ?> voter<?= $stats['pending']===1?'':'s' ?> still need approval →</a><?php endif; ?></section></div></div><p class="dashboard-logout"><a href="<?= e(url('auth/logout.php')) ?>">Log out</a></p>
<?php render_foot(); ?>
