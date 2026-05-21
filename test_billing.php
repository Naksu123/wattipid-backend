<?php
try {
    $c = new PDO('mysql:host=localhost;dbname=wattipid;charset=utf8mb4', 'root', '');
    $c->exec("UPDATE users SET move_in_date='2026-05-17', billing_start_date='2026-05-17', billing_end_date='2026-06-17' WHERE id=6");
    echo "Done";
} catch(Exception $e) {
    echo $e->getMessage();
}
