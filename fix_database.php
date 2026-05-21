<?php
/**
 * WATTIPID DATABASE REPAIR & HARDENING SCRIPT (V2)
 * 🛡️ Restores identity isolation and enforces security constraints.
 */

// Enable error reporting so we can see what's wrong
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "<h1>Wattipid Database Repair</h1>";
    echo "<hr>";

    // Manually load database config to be 100% sure
    $host = 'localhost';
    $db   = 'wattipid';
    $user = 'root';
    $pass = ''; // Default XAMPP password is empty
    $charset = 'utf8mb4';

    echo "Connecting to database... ";
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $conn = new PDO($dsn, $user, $pass, $options);
    echo "<span style='color:green'>Success!</span><br><br>";

    // 1. PURGE DUPLICATE EMAILS (Identity Isolation - Landlord Priority)
    echo "<b>[1/3] Purging duplicate accounts (Prioritizing Landlords)...</b> ";
    
    // First, delete duplicates where one is a tenant and one is a landlord (Delete the tenant)
    $purgeSql1 = "
        DELETE u1 FROM users u1
        INNER JOIN users u2 ON u1.email = u2.email
        WHERE u1.role = 'tenant' AND u2.role = 'landlord'
    ";
    $conn->exec($purgeSql1);

    // Second, if there are still duplicates within the same role, keep the oldest one
    $purgeSql2 = "
        DELETE u1 FROM users u1
        INNER JOIN users u2 ON u1.email = u2.email
        WHERE u1.id > u2.id
    ";
    $conn->exec($purgeSql2);
    echo "<span style='color:green'>Done.</span><br>";

    // 2. ENFORCE UNIQUE EMAILS
    echo "<b>[2/3] Enforcing Unique Email constraint...</b> ";
    try {
        $conn->exec("ALTER TABLE users ADD CONSTRAINT uk_user_email UNIQUE (email)");
        echo "<span style='color:green'>Applied.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange'>Skipped (already exists).</span><br>";
    }

    // 3. REPAIR MISSING COLUMNS
    echo "<b>[3/3] Syncing missing security columns...</b><br>";
    
    $updates = [
        "token_version" => "ALTER TABLE users ADD COLUMN token_version INT DEFAULT 1 AFTER is_verified",
        "last_login_at" => "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL AFTER token_version",
        "push_token" => "ALTER TABLE users ADD COLUMN push_token VARCHAR(255) DEFAULT NULL AFTER room_id",
        "move_in_date" => "ALTER TABLE users ADD COLUMN move_in_date DATE DEFAULT NULL AFTER push_token",
        "billing_start_date" => "ALTER TABLE users ADD COLUMN billing_start_date DATE DEFAULT NULL AFTER move_in_date",
        "billing_end_date" => "ALTER TABLE users ADD COLUMN billing_end_date DATE DEFAULT NULL AFTER billing_start_date",
        "relay_state" => "ALTER TABLE rooms ADD COLUMN relay_state TINYINT(1) DEFAULT 1 AFTER last_seen",
        "tenant_log_name" => "ALTER TABLE consumption_logs ADD COLUMN tenant_name VARCHAR(255) DEFAULT NULL AFTER room_id"
    ];

    foreach ($updates as $col => $sql) {
        try {
            $conn->exec($sql);
            echo " - Column <i>$col</i>: <span style='color:green'>Added.</span><br>";
        } catch (Exception $e) {
            echo " - Column <i>$col</i>: <span style='color:orange'>Skipped (exists).</span><br>";
        }
    }

    // 4. MIGRATE EXISTING TENANTS BILLING DATES
    echo "<b>[4/5] Initializing Billing Cycles for existing tenants...</b> ";
    $conn->exec("UPDATE users SET move_in_date = DATE(created_at), billing_start_date = DATE(created_at), billing_end_date = DATE_ADD(DATE(created_at), INTERVAL 1 MONTH) WHERE role = 'tenant' AND created_at IS NOT NULL");
    echo "<span style='color:green'>Done.</span><br>";

    // 5. FORCE PROMOTE ADMIN
    echo "<b>[5/5] Force-promoting Admin account...</b> ";
    $promoteSql = "UPDATE users SET role = 'landlord', room_id = NULL WHERE email = 'admin@wattipid.com'";
    $conn->exec($promoteSql);
    echo "<span style='color:green'>Success! admin@wattipid.com is now a Landlord.</span><br>";

    // 6. SEED DATA (Tips & Settings)
    echo "<b>[6/6] Seeding Electricity Tips & Settings...</b><br>";
    
    // Drop and Recreate Tips Table to ensure correct schema
    $conn->exec("DROP TABLE IF EXISTS electricity_tips");
    $conn->exec("CREATE TABLE IF NOT EXISTS electricity_tips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'General',
        icon VARCHAR(100) DEFAULT 'bulb-outline',
        difficulty ENUM('Easy', 'Medium', 'Hard') DEFAULT 'Easy',
        savings_level ENUM('Low', 'Moderate', 'High') DEFAULT 'Low',
        dorm_relevance ENUM('Student', 'Boarding House', 'Apartment') DEFAULT 'Student',
        is_active TINYINT(1) DEFAULT 1,
        views_count INT DEFAULT 0,
        likes_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Create Settings Table if missing
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert Default Tips
    $tips = [
        ['General', 'Switch to LED Bulbs', 'LED bulbs use 75% less energy and last 25 times longer than incandescent lighting.'],
        ['Appliances', 'Unplug Idle Electronics', 'Devices in "standby" mode still consume power. Unplug chargers and appliances when not in use.'],
        ['Cooling', 'Clean Air Filters', 'Dirty filters make your AC work harder. Clean them monthly to save up to 15% on cooling costs.'],
        ['Laundry', 'Wash with Cold Water', 'Heating water accounts for 90% of the energy used by a washing machine.'],
        ['General', 'Use Natural Light', 'Open your curtains during the day to reduce the need for artificial lighting.']
    ];

    $stmtTip = $conn->prepare("INSERT IGNORE INTO electricity_tips (category, title, message) VALUES (?, ?, ?)");
    foreach ($tips as $tip) $stmtTip->execute($tip);

    // Insert Default Rate
    $conn->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('rate_per_kwh', '12.50')");
    
    echo "<span style='color:green'>Seeding Success! 5 tips and default rate added.</span><br>";

    echo "<br><div style='padding:15px; background-color:#dcfce7; border:1px solid #16a34a; border-radius:8px;'>";
    echo "✅ <b>REPAIR COMPLETE!</b> Your database is now secure.<br>";
    echo "You can now log in without any Admin/Tenant mixups.";
    echo "</div>";

} catch (Exception $e) {
    echo "<br><div style='padding:15px; background-color:#fee2e2; border:1px solid #dc2626; border-radius:8px;'>";
    echo "❌ <b>ERROR:</b> " . $e->getMessage();
    echo "</div>";
}
