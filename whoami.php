<?php
/**
 * WATTIPID IDENTITY AUDIT TOOL (V2)
 * 🕵️‍♂️ Checks exactly what the database thinks about your account.
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "<h1>Wattipid Identity Audit</h1>";
    echo "<hr>";

    // Manually load database config
    $host = 'localhost';
    $db   = 'wattipid';
    $user = 'root';
    $pass = ''; 
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $conn = new PDO($dsn, $user, $pass, $options);

    $email = $_GET['email'] ?? 'admin@wattipid.com'; 

    echo "<div style='padding:20px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:20px;'>";
    echo "<form method='GET' style='font-family:sans-serif;'>";
    echo "<b>Check Account Email:</b> <input type='text' name='email' value='".htmlspecialchars($email)."' style='padding:8px; width:250px;'> ";
    echo "<input type='submit' value='Search Database' style='padding:8px; background:#2563eb; color:white; border:none; border-radius:4px; cursor:pointer;'>";
    echo "</form>";
    echo "</div>";

    $stmt = $conn->prepare("SELECT id, name, email, role, room_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        echo "<div style='padding:20px; background:#fee2e2; border-radius:8px; color:#b91c1c;'>";
        echo "❌ <b>NO ACCOUNT FOUND</b> for email: <b>$email</b>";
        echo "</div>";
    } else {
        echo "<h3>Accounts found: " . count($results) . "</h3>";
        echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%; font-family:sans-serif;'>";
        echo "<tr style='background:#f1f5f9;'><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Room ID</th></tr>";
        
        foreach ($results as $user) {
            $color = ($user['role'] === 'landlord') ? '#dcfce7' : '#fff7ed';
            $textColor = ($user['role'] === 'landlord') ? '#166534' : '#9a3412';
            echo "<tr style='background:$color; color:$textColor;'>";
            echo "<td>".$user['id']."</td>";
            echo "<td>".$user['name']."</td>";
            echo "<td>".$user['email']."</td>";
            echo "<td><b style='text-transform:uppercase;'>".$user['role']."</b></td>";
            echo "<td>".($user['room_id'] ?: '<i>(Landlord Admin)</i>')."</td>";
            echo "</tr>";
        }
        echo "</table>";

        if (count($results) > 1) {
            echo "<br><div style='padding:20px; background:#fef2f2; border:1px solid #ef4444; border-radius:8px; color:#991b1b;'>";
            echo "⚠️ <b>CRITICAL: DUPLICATE ACCOUNTS DETECTED!</b><br>";
            echo "This is why your login is sending you to the Tenant dashboard. The database has two people with the same email.<br><br>";
            echo "<a href='fix_database.php' style='display:inline-block; padding:10px 20px; background:#ef4444; color:white; text-decoration:none; border-radius:4px;'>Click here to Purge Duplicates</a>";
            echo "</div>";
        } else {
            echo "<br><div style='padding:20px; background:#f0fdf4; border:1px solid #16a34a; border-radius:8px; color:#166534;'>";
            echo "✅ <b>Data Integrity OK!</b> Only one account exists for this email.<br>";
            echo "If you are a Landlord, your role must say <b>LANDLORD</b> above.";
            echo "</div>";
        }
    }

} catch (Exception $e) {
    echo "<div style='color:red; padding:20px; border:1px solid red;'>";
    echo "<b>DATABASE ERROR:</b> " . $e->getMessage();
    echo "</div>";
}
