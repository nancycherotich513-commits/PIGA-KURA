<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_admin();
$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';
if (!in_array($type, ['voters', 'results'], true) || !in_array($format, ['csv', 'pdf'], true)) {
    http_response_code(400);
    exit('Invalid export request.');
}

$headers = [];
$rows = [];
$title = '';
if ($type === 'voters') {
    $title = 'VoteHub voter list';
    $headers = ['Full name', 'Email', 'Phone', 'Status', 'Registered', 'Approved'];
    $records = db()->query('SELECT full_name, email, phone, status, created_at, approved_at FROM voters ORDER BY full_name')->fetchAll();
    foreach ($records as $record) {
        $rows[] = [$record['full_name'], $record['email'], $record['phone'], $record['status'], $record['created_at'], $record['approved_at']];
    }
} else {
    $electionId = (int)($_GET['election_id'] ?? 0);
    $election = $electionId ? find_election($electionId) : null;
    if (!$election) { http_response_code(404); exit('Election not found.'); }
    $title = 'Results — ' . $election['title'];
    $summary = election_results($electionId);
    $turnout = percentage_value($summary['voters'], $summary['approved']);
    $headers = ['Election', 'Status', 'Position', 'Candidate', 'Candidate votes', 'Candidate vote percent', 'Outcome', 'Eligible voters', 'Election voters voted', 'Position voters voted', 'Position turnout percent', 'Election turnout percent'];
    foreach ($summary['positions'] as $position) {
        $positionVoters = (int)$position['voters_voted'];
        $positionTurnout = percentage_value($positionVoters, $summary['approved']);
        foreach ($position['candidates'] as $candidate) {
            $outcome = $candidate['votes'] > 0 && $candidate['votes'] === max(array_column($position['candidates'], 'votes'))
                ? ($position['is_tie'] ? 'Tied winner' : 'Winner')
                : '';
            $rows[] = [$election['title'], election_status($election), $position['title'], $candidate['full_name'], $candidate['votes'], $candidate['percent'], $outcome, $summary['approved'], $summary['voters'], $positionVoters, $positionTurnout, $turnout];
        }
        if (!$position['candidates']) $rows[] = [$election['title'], election_status($election), $position['title'], 'No candidates', 0, 0, '', $summary['approved'], $summary['voters'], 0, '0', $turnout];
    }
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($title)) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title>
<style>
body{font:14px Arial,sans-serif;color:#202044;margin:36px}h1{margin:0 0 8px;color:#37348f}p{color:#666}button{padding:10px 16px;border:0;border-radius:8px;background:#625ee0;color:#fff;font-weight:bold;cursor:pointer}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:9px;border:1px solid #e3e2ef;text-align:left}th{background:#efeeff;color:#37348f}tr:nth-child(even){background:#fafaff}@media print{body{margin:0;font-size:11px}.print-action{display:none}th,td{padding:6px}}
</style></head><body><button class="print-action" onclick="window.print()">Print / Save as PDF</button><h1><?= e($title) ?></h1><p>Generated <?= e(now_local()->format('D j M Y, g:i A')) ?> · <?= count($rows) ?> rows</p>
<table><thead><tr><?php foreach ($headers as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= e($cell ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></body></html>
