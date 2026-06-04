<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

try {
    $db = $conn;
    
    echo "Creating Terms and Conditions tables...\n";
    
    // 1. terms_versions
    $db->exec("
        CREATE TABLE IF NOT EXISTS terms_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version_number VARCHAR(50) NOT NULL,
            effective_date DATE NOT NULL,
            is_active BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 2. terms_content
    $db->exec("
        CREATE TABLE IF NOT EXISTS terms_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version_id INT NOT NULL,
            section_title VARCHAR(255) NOT NULL,
            section_content TEXT NOT NULL,
            display_order INT NOT NULL,
            FOREIGN KEY (version_id) REFERENCES terms_versions(id) ON DELETE CASCADE
        )
    ");
    
    // 3. terms_acceptance_logs
    $db->exec("
        CREATE TABLE IF NOT EXISTS terms_acceptance_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            version_id INT NOT NULL,
            accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NULL,
            device_info TEXT NULL,
            FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (version_id) REFERENCES terms_versions(id) ON DELETE CASCADE
        )
    ");
    
    // Check if version 1.0 exists
    $stmt = $db->query("SELECT id FROM terms_versions WHERE version_number = '1.0'");
    if ($stmt->rowCount() == 0) {
        echo "Seeding Version 1.0 terms...\n";
        
        $db->exec("UPDATE terms_versions SET is_active = FALSE");
        
        $stmt = $db->prepare("INSERT INTO terms_versions (version_number, effective_date, is_active) VALUES ('1.0', CURDATE(), TRUE)");
        $stmt->execute();
        $versionId = $db->lastInsertId();
        
        $termsData = [
            [
                'title' => '1. ACCEPTANCE OF TERMS',
                'content' => 'By creating an account, accessing the application, or using Wattipid services, users agree to comply with all terms, conditions, billing policies, and payment regulations established by the property owner and system administrator.'
            ],
            [
                'title' => '2. DESCRIPTION OF SERVICES',
                'content' => "Wattipid provides:\n\n✓ Real-Time Electricity Monitoring\n✓ Electricity Consumption Tracking\n✓ Smart Billing Management\n✓ Payment Monitoring\n✓ Notification Services\n✓ Consumption Analytics\n✓ Billing Reports\n✓ Tenant and Landlord Account Management\n\nWattipid is a monitoring and billing platform and does not act as an electric utility provider."
            ],
            [
                'title' => '3. ELECTRICITY BILLING POLICY',
                'content' => "Electricity charges are calculated using actual consumption data collected through the ESP32 Controller, SCT-013 Current Sensor, and ZMPT101B Voltage Sensor.\n\nBilling Formula:\nElectricity Charge = Total kWh Consumed × Current kWh Rate\n\nThe electricity rate per kWh is determined by the property owner or administrator and may be adjusted based on utility provider rate changes.\n\nExample:\nConsumption: 150 kWh\nRate: ₱12.00 / kWh\nElectricity Charge: ₱1,800.00"
            ],
            [
                'title' => '4. MONTHLY ROOM RENT',
                'content' => 'Monthly room rental fees are separate from electricity charges and may be included in the total billing statement. Rental charges are determined by the landlord.'
            ],
            [
                'title' => '5. MISCELLANEOUS FEES',
                'content' => "The system may apply a Miscellaneous Fee equivalent to 2% of the total electricity charge for administrative and operational purposes.\n\nExample:\nElectricity Charge: ₱1,000\nMiscellaneous Fee: 2%\nFee Amount: ₱20\n\nPurpose:\n✓ System Maintenance\n✓ Cloud Infrastructure\n✓ Billing Administration\n✓ Reporting Services"
            ],
            [
                'title' => '6. ADDITIONAL CHARGES',
                'content' => "Additional charges may be applied when necessary.\n\nPossible Additional Charges:\n✓ Service Fees\n✓ Maintenance Fees\n✓ Utility Adjustments\n✓ Property-Related Charges\n✓ Other Charges Authorized by the Property Owner\n\nAll charges must be reflected in the billing statement before payment is due."
            ],
            [
                'title' => '7. PENALTY POLICY',
                'content' => "Overdue accounts are subject to penalties.\n\nPenalty Rules:\n✓ Payments not settled on or before the due date may incur penalties.\n✓ Penalties are automatically reflected in billing summaries.\n✓ Penalty amounts are determined by the property owner.\n\nExample:\nOutstanding Balance: ₱1,000\nPenalty Rate: 5%\nPenalty Charge: ₱50\nNew Balance: ₱1,050\n\nRepeated late payments may result in additional actions as permitted by the landlord."
            ],
            [
                'title' => '8. PAYMENT POLICY',
                'content' => "Supported Payment Methods:\n✓ Cash Payment\n✓ GCash Payment\n✓ Maya Payment\n\nPolicies:\n✓ Wattipid does not process payments directly.\n✓ The system only records and tracks payment submissions.\n✓ GCash and Maya payments require screenshot proof and reference numbers.\n✓ All payments are subject to landlord verification."
            ],
            [
                'title' => '9. PAYMENT VERIFICATION',
                'content' => "Submitted payments remain under \"Pending Verification\" until reviewed by the landlord.\n\nPossible Statuses:\n✓ Pending Verification\n✓ Approved\n✓ Rejected\n✓ Paid\n✓ Overdue\n\nLandlords reserve the right to reject payment submissions if:\n✓ Reference number is invalid.\n✓ Uploaded proof is unclear.\n✓ Payment amount is incorrect.\n✓ Payment cannot be verified."
            ],
            [
                'title' => '10. BILLING DISPUTES',
                'content' => "Tenants may contact the landlord regarding:\n✓ Billing Concerns\n✓ Consumption Discrepancies\n✓ Penalty Concerns\n✓ Additional Charges\n✓ Payment Verification Issues\n\nDisputes must be raised within a reasonable period after bill generation."
            ],
            [
                'title' => '11. USER RESPONSIBILITIES',
                'content' => "Users agree to:\n✓ Provide accurate information.\n✓ Maintain account security.\n✓ Submit valid payment proofs.\n✓ Review billing statements regularly.\n✓ Pay charges before the due date.\n\nUsers must not:\n✗ Upload fraudulent payment proofs.\n✗ Manipulate consumption records.\n✗ Attempt unauthorized access.\n✗ Abuse system functionality."
            ],
            [
                'title' => '12. DATA PRIVACY',
                'content' => "Wattipid collects and stores:\n✓ User Information\n✓ Billing Records\n✓ Consumption Data\n✓ Payment Records\n✓ Notification History\n\nAll data is stored securely and used solely for system operations and account management."
            ],
            [
                'title' => '13. LIMITATION OF LIABILITY',
                'content' => "Wattipid serves as a monitoring and billing management platform. The application is not responsible for:\n✓ Utility provider outages.\n✓ Internet connectivity issues.\n✓ Third-party service interruptions.\n✓ Incorrect information provided by users."
            ],
            [
                'title' => '14. CHANGES TO TERMS',
                'content' => 'Wattipid and the property owner reserve the right to update these Terms and Conditions when necessary. Users will be notified of significant changes through the application.'
            ],
            [
                'title' => '15. CONTACT AND SUPPORT',
                'content' => "For concerns regarding:\n✓ Billing\n✓ Payments\n✓ Electricity Monitoring\n✓ Account Issues\n✓ Technical Support\n\nUsers may contact the property administrator or landlord through the application's support section."
            ]
        ];
        
        $insertStmt = $db->prepare("INSERT INTO terms_content (version_id, section_title, section_content, display_order) VALUES (?, ?, ?, ?)");
        
        foreach ($termsData as $index => $section) {
            $insertStmt->execute([
                $versionId,
                $section['title'],
                $section['content'],
                $index + 1
            ]);
        }
        echo "Terms version 1.0 seeded successfully.\n";
    } else {
        echo "Version 1.0 already exists. Skipping seed.\n";
    }
    
    echo "Migration completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
