<?php
$pdo = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
$stmt = $pdo->query('DESCRIBE electricity_tips');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
