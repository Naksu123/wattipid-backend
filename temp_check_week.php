<?php
$db = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
$dayOfWeek = date('w');
$offset = ($dayOfWeek == 0 ? 6 : $dayOfWeek - 1);
$start = date('Y-m-d 00:00:00', strtotime("-$offset days"));
$end = date('Y-m-d 00:00:00', strtotime('+1 day'));

echo "Start: $start\n";
echo "End: $end\n";

$stmt = $db->query("SELECT SUM(energy) as e, SUM(cost) as c FROM consumption_logs WHERE timestamp >= '$start' AND timestamp < '$end'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

// Also check month
$mStart = date('Y-m-01 00:00:00');
$mEnd = date('Y-m-d 00:00:00', strtotime('first day of next month'));
$stmtM = $db->query("SELECT SUM(energy) as e, SUM(cost) as c FROM consumption_logs WHERE timestamp >= '$mStart' AND timestamp < '$mEnd'");
print_r($stmtM->fetch(PDO::FETCH_ASSOC));
?>
