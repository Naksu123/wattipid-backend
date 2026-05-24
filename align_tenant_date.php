<?php
$db = new PDO('mysql:host=localhost;dbname=wattipid', 'root', '');
// Force tenant start date to April 17 to match the user's previous manual tracking
$db->exec("UPDATE rooms SET tenant_start_date = '2026-04-17' WHERE room_id = 'Room 1'");
echo "Updated tenant_start_date to 2026-04-17.\n";
?>
