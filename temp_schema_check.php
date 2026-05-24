<?php
$db = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
$stmt = $db->query("DESCRIBE rooms");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $db->query("DESCRIBE consumption_logs");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
?>
