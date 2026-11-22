<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$voter = require_voter();
if ($voter['status'] !== 'approved') {
    flash('error', 'Your account must be approved before you can vote.');
    redirect('voter/dashboard.php');
}

$elections = db()->query('SELECT id, title, start_at, end_at, voting_override FROM elections')->fetchAll();
usort($elections, static function (array $a, array $b): int {
    $rank = ['live' => 0, 'upcoming' => 1, 'closed' => 2];
    $byStatus = ($rank[election_status($a)] ?? 3) <=> ($rank[election_status($b)] ?? 3);
    return $byStatus ?: strcmp($b['start_at'], $a['start_at']);
});

$election = $elections[0] ?? null;
if (!$election) {
    flash('info', 'There are no elections available yet.');
    redirect('voter/dashboard.php');
}

if (election_status($election) === 'closed') {
    redirect('voter/election_info.php?id=' . (int)$election['id']);
}
redirect('voter/election.php?id=' . (int)$election['id']);
