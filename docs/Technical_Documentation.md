# Wattipid Smart Electricity Monitoring System
## Final Technical Documentation

### 1. System Architecture
Wattipid is a cloud-based Real-Time Smart Electricity Monitoring system tailored for landlords and tenants. It features an ESP32-based hardware layer capturing real-time current and voltage, syncing directly with a PHP/MySQL backend, and a React Native (Expo) frontend for mobile consumption.

- **Frontend:** React Native (Expo)
- **Backend:** PHP 8, PDO, REST API
- **Database:** MariaDB/MySQL
- **IoT Layer:** ESP32, PZEM-004T v3

### 2. Modules & Features
#### Tenant Module (Phase 6 Finalized)
1. **Real-time Dashboard:** Instantly monitors power consumption in Watts, Voltage, and Amperage.
2. **Budgeting System:** Tracks daily/monthly allowances and fires Expo Push Notifications at 50%, 75%, and 90% thresholds.
3. **Billing System:** Generates PDF invoices. Allows tenants to view breakdowns of their consumption and upload proof of payment via GCash/Maya or log Cash payments.
4. **Notification Center:** Push-enabled alerts for over-budget warnings, due dates, and payment verifications.

### 3. Security Implementation
- **JWT Authentication:** Strict Bearer Token validation via `AuthMiddleware.php`.
- **Global Rate Limiting:** Built-in IP & Action-based Request throttling.
- **File Upload Security:** Base64 uploads enforce 10MB limits, strict MIME Regex parsing, and Magic Byte verification to prevent malware disguised as images.
- **SQL Injection Prevention:** 100% PDO Prepared statements. Recursive JSON payload sanitization via `SecurityMiddleware::sanitizeInput()`.

### 4. Database Optimization
The `phase6_db_optimization.sql` applies composite indexing for real-time querying:
- `idx_billing_start_end` (cycle_start, cycle_end)
- `idx_payments_tenant_status` (tenant_id, status)
- `idx_readings_room_date` (room_id, timestamp)
- `idx_notif_user_read_time` (user_id, is_read, created_at)

### 5. Deployment Guide
1. Run `backup_system.php` to secure local records.
2. Run `deploy_production.ps1` to zip the `wattipid_backend` (excluding `.env`, `.git`).
3. Upload `wattipid_prod.zip` to your cPanel or Cloud Host.
4. Setup `.env` manually on the remote server with production Database and Email (SMTP) credentials.
5. In `frontend/services/config.js`, change `API_URL` to your production URL.
6. Build APK via Expo `eas build -p android`.

---
*Generated for Capstone Defense. System is fully stable, production-ready, and QA validated.*
