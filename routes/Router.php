<?php
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/IoTController.php';
require_once __DIR__ . '/../controllers/DashboardController.php';
require_once __DIR__ . '/../controllers/RoomController.php';
require_once __DIR__ . '/../controllers/BudgetController.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../controllers/SettingController.php';
require_once __DIR__ . '/../controllers/UserController.php';
require_once __DIR__ . '/../controllers/ForecastController.php';
require_once __DIR__ . '/../controllers/PaymentController.php';
require_once __DIR__ . '/../controllers/PenaltyController.php';
require_once __DIR__ . '/../controllers/AuditController.php';
require_once __DIR__ . '/../controllers/SyncController.php';
require_once __DIR__ . '/../controllers/TermsController.php';
require_once __DIR__ . '/../middlewares/IoTMiddleware.php';

class Router {
    /** @var AuthController */
    private $authController;
    /** @var IoTController */
    private $iotController;
    /** @var DashboardController */
    private $dashboardController;
    /** @var RoomController */
    private $roomController;
    /** @var BudgetController */
    private $budgetController;
    /** @var NotificationController */
    private $notificationController;
    /** @var SettingController */
    private $settingController;
    /** @var UserController */
    private $userController;
    /** @var ForecastController */
    private $forecastController;
    /** @var PaymentController */
    private $paymentController;
    /** @var PenaltyController */
    private $penaltyController;
    /** @var AuditController */
    private $auditController;
    /** @var SyncController */
    private $syncController;
    /** @var TermsController */
    private $termsController;
    /** @var IoTMiddleware */
    private $iotMiddleware;

    public function __construct($dbConnection) {
        $this->authController = new AuthController($dbConnection);
        $this->iotController = new IoTController($dbConnection);
        $this->dashboardController = new DashboardController($dbConnection);
        $this->roomController = new RoomController($dbConnection);
        $this->budgetController = new BudgetController($dbConnection);
        $this->notificationController = new NotificationController($dbConnection);
        $this->settingController = new SettingController($dbConnection);
        $this->userController = new UserController($dbConnection);
        $this->forecastController = new ForecastController($dbConnection);
        $this->paymentController = new PaymentController($dbConnection);
        $this->penaltyController = new PenaltyController($dbConnection);
        $this->auditController = new AuditController($dbConnection);
        $this->syncController = new SyncController($dbConnection);
        $this->termsController = new TermsController($dbConnection);
        $this->iotMiddleware = new IoTMiddleware($dbConnection);
    }

    /**
     * Routes the action to the correct controller.
     */
    public function handle($action, $data, $authenticatedUser = null) {
        // --- IoT SECURITY CHECK ---
        // Validate HMAC signature for logging consumption
        // if ($action === 'logConsumption') {
        //     $this->iotMiddleware->handle($action, $data);
        // }

        switch ($action) {
            case 'login':
                $this->authController->login($data);
                return true; 
            
            case 'register':
                $this->authController->register($data);
                return true;

            case 'sendVerificationCode':
            case 'resendVerificationCode':
                $this->authController->sendVerificationCode($data);
                return true;
            case 'verifyOTP':
                $this->authController->verifyOTP($data);
                return true;

            case 'refreshToken':
                $this->authController->refreshToken($data);
                return true;
            case 'logout':
                $this->authController->logout($authenticatedUser);
                return true;

            case 'logConsumption':
                $this->iotController->logConsumption($data);
                return true;

            case 'getLatestConsumption':
                $this->iotController->getLatestConsumption($authenticatedUser, $data);
                return true;

            case 'toggleRelay':
                $this->iotController->toggleRelay($authenticatedUser, $data);
                return true;

            case 'requestPasswordReset':
                $this->authController->requestPasswordReset($data);
                return true;

            case 'getActiveTerms':
                ResponseHelper::sendRaw($this->termsController->getActiveTerms());
                return true;
                
            case 'acceptTerms':
                $tenantId = $authenticatedUser['id'];
                $versionId = $data['version_id'] ?? null;
                $ipAddress = $data['ip_address'] ?? null;
                $deviceInfo = $data['device_info'] ?? null;
                if (!$versionId) {
                    ResponseHelper::error("Version ID is required.");
                    return true;
                }
                ResponseHelper::sendRaw($this->termsController->acceptTerms($tenantId, $versionId, $ipAddress, $deviceInfo));
                return true;
                
            case 'checkTermsAcceptance':
                $tenantId = $authenticatedUser['id'];
                ResponseHelper::sendRaw($this->termsController->checkAcceptance($tenantId));
                return true;

            case 'verifyResetOTP':
                $this->authController->verifyResetOTP($data);
                return true;

            case 'resetPassword':
                $this->authController->resetPassword($data);
                return true;

            case 'getLiveOverview':
                $this->dashboardController->getLiveOverview($authenticatedUser, $data);
                return true;

            case 'getBillingCycle':
                $this->dashboardController->getBillingCycle($authenticatedUser, $data);
                return true;

            case 'getTotalConsumptionToday':
                $this->dashboardController->getTotalConsumptionToday($authenticatedUser, $data);
                return true;
            case 'getTotalConsumptionWeek':
                $this->dashboardController->getTotalConsumptionWeek($authenticatedUser, $data);
                return true;
            case 'getTotalConsumptionMonth':
                $this->dashboardController->getTotalConsumptionMonth($authenticatedUser, $data);
                return true;
            case 'getMonthlyConsumptionFiltered':
                $this->dashboardController->getMonthlyConsumptionFiltered($authenticatedUser, $data);
                return true;
            case 'getHourlyBreakdown':
                $this->dashboardController->getHourlyBreakdown($authenticatedUser, $data);
                return true;
            case 'getDailyBreakdown':
            case 'getDailyBreakdownFiltered':
                $this->dashboardController->getDailyBreakdownFiltered($authenticatedUser, $data);
                return true;
            case 'getConsumptionComparison':
                $this->dashboardController->getConsumptionComparison($authenticatedUser, $data);
                return true;
            case 'getConsumptionHistory':
                $period = $data['period'] ?? 'daily';
                if ($period === 'daily') {
                    $this->dashboardController->getHourlyBreakdown($authenticatedUser, $data);
                } elseif ($period === 'weekly') {
                    $this->dashboardController->getWeeklyBreakdown($authenticatedUser, $data);
                } elseif ($period === 'yearly') {
                    $this->dashboardController->getYearlyBreakdown($authenticatedUser, $data);
                } else {
                    // For monthly, return daily breakdown for the requested month or current cycle
                    $data['year'] = $data['year'] ?? date('Y');
                    $data['month'] = $data['month'] ?? date('n');
                    $this->dashboardController->getDailyBreakdownFiltered($authenticatedUser, $data);
                }
                return true;
            case 'getTransactionHistory':
                $this->dashboardController->getTransactionHistory($authenticatedUser, $data);
                return true;
            case 'getAvailableBillingCycles':
                $this->dashboardController->getAvailableBillingCycles($authenticatedUser, $data);
                return true;

            // ROOMS
            case 'getAllRooms':
                $this->roomController->getAllRooms($authenticatedUser);
                return true;
            case 'addRoom':
                $this->roomController->addRoom($authenticatedUser, $data);
                return true;
            case 'updateRoom':
                $this->roomController->updateRoom($authenticatedUser, $data);
                return true;
            case 'archiveRoom':
                $this->roomController->archiveRoom($authenticatedUser, $data);
                return true;
            case 'restoreRoom':
                $this->roomController->restoreRoom($authenticatedUser, $data);
                return true;
            case 'getUserRooms':
                $this->roomController->getUserRooms($authenticatedUser, $data);
                return true;
            case 'getBuildingSummary':
                $this->roomController->getBuildingSummary($authenticatedUser);
                return true;
            case 'getRoomById':
                $this->roomController->getRoomById($data);
                return true;
            case 'updateRoomStatus':
                $this->roomController->updateRoomStatus($authenticatedUser, $data);
                return true;
            case 'getTenantHistory':
                $this->roomController->getTenantHistory($authenticatedUser, $data);
                return true;
            case 'getVacantRooms':
                $this->roomController->getVacantRooms($authenticatedUser);
                return true;
            case 'transferTenant':
                $this->roomController->transferTenant($authenticatedUser, $data);
                return true;
            case 'revokeTenant':
                $this->roomController->revokeTenant($authenticatedUser, $data);
                return true;
            case 'generateNewTenantCode':
                $this->roomController->generateNewTenantCode($authenticatedUser, $data);
                return true;
            case 'saveTenantInvitation':
                $this->roomController->saveTenantInvitation($authenticatedUser, $data);
                return true;
            case 'getInvitations':
                $this->roomController->getInvitations($authenticatedUser, $data);
                return true;
            case 'resendInvitation':
                $this->roomController->resendInvitation($authenticatedUser, $data);
                return true;
            case 'cancelInvitation':
                $this->roomController->cancelInvitation($authenticatedUser, $data);
                return true;
            case 'verifyAccessCode':
                $this->roomController->verifyAccessCode($data);
                return true;
            case 'getTenantInvitationByEmail':
                $this->roomController->getTenantInvitationByEmail($data);
                return true;
            case 'getSystemAuditLogs':
                $this->auditController->getSystemAuditLogs($authenticatedUser);
                return true;

            // BUDGET
            case 'getBudget':
                $this->budgetController->getBudget($authenticatedUser, $data);
                return true;
            case 'setBudget':
            case 'updateBudget':
                $this->budgetController->updateBudget($authenticatedUser, $data);
                return true;
            case 'resetBudget':
                $this->budgetController->resetBudget($authenticatedUser, $data);
                return true;

            // NOTIFICATIONS
            case 'getNotifications':
            case 'getNotificationHistory':
                $this->notificationController->getNotifications($authenticatedUser, $data);
                return true;
            case 'createNotification':
                $this->notificationController->createNotification($authenticatedUser, $data);
                return true;
            case 'markNotificationRead':
                $this->notificationController->markAsRead($data);
                return true;
            case 'getUnreadNotificationCount':
                $this->notificationController->getUnreadCount($authenticatedUser, $data);
                return true;
            case 'markAllNotificationsRead':
                $this->notificationController->markAllAsRead($authenticatedUser);
                return true;
            case 'deleteNotification':
                $this->notificationController->deleteNotification($authenticatedUser, $data);
                return true;
            case 'getAlertSettings':
                $this->notificationController->getAlertSettings($authenticatedUser, $data);
                return true;
            case 'updateAlertSettings':
                $this->notificationController->updateAlertSettings($authenticatedUser, $data);
                return true;
            case 'searchNotifications':
                $this->notificationController->searchNotifications($authenticatedUser, $data);
                return true;
            case 'getNotificationsByCategory':
                $this->notificationController->getNotificationsByCategory($authenticatedUser, $data);
                return true;

            // SETTINGS & TIPS
            case 'getSetting':
                $this->settingController->getSetting($data);
                return true;
            case 'setSetting':
            case 'updateSetting':
                $this->settingController->updateSetting($authenticatedUser, $data);
                return true;
            case 'addTips':
                $this->settingController->addTip($authenticatedUser, $data);
                return true;
            case 'updateTip':
                $this->settingController->updateTip($authenticatedUser, $data);
                return true;
            case 'deleteTip':
                $this->settingController->deleteTip($authenticatedUser, $data);
                return true;
            case 'likeTip':
                $this->settingController->likeTip($data);
                return true;
            case 'viewTip':
                $this->settingController->viewTip($authenticatedUser, $data);
                return true;
            case 'getSmartRecommendation':
                $this->settingController->getSmartRecommendation($authenticatedUser, $data);
                return true;
            case 'getTipOfTheDay':
                $this->settingController->getTipOfTheDay();
                return true;
            case 'getTrendingTips':
                $this->settingController->getTrendingTips($data);
                return true;
            case 'getElectricityTips':
                $this->settingController->getTips();
                return true;

            // USER
            case 'getUserByEmail':
                $this->userController->getUserByEmail($data);
                return true;
            case 'updateUserProfile':
                $this->userController->updateProfile($authenticatedUser, $data);
                return true;
            case 'updatePushToken':
            case 'registerPushToken':
                $this->userController->updatePushToken($authenticatedUser, $data);
                return true;

            // FORECAST
            case 'getMonthlyForecast':
                $this->forecastController->getMonthlyForecast($data);
                return true;
            case 'getPeakHourPrediction':
                $this->forecastController->getPeakHourPrediction($data);
                return true;

            // PAYMENTS
            case 'submitPayment':
                $this->paymentController->submitPayment($authenticatedUser, $data);
                return true;

            case 'getBillingDetails':
                $this->paymentController->getBillingDetails($authenticatedUser, $data);
                return true;

            case 'getBillingHistory':
                $this->paymentController->getBillingHistory($authenticatedUser, $data);
                return true;
            case 'getPaymentInsights':
                $this->paymentController->getPaymentInsights($authenticatedUser, $data);
                return true;
            case 'submitOfflinePayment':
                $this->paymentController->submitOfflinePayment($authenticatedUser, $data);
                return true;
            case 'verifyPayment':
                $this->paymentController->verifyPayment($authenticatedUser, $data);
                return true;
            case 'getPaymentHistory':
                $this->paymentController->getPaymentHistory($authenticatedUser, $data);
                return true;
            case 'getPaymentWidgets':
                $this->paymentController->getPaymentWidgets($authenticatedUser, $data);
                return true;
            case 'processPenalties':
                // In production, secure this by checking for a specific cron secret or internal IP
                $this->paymentController->processPenalties($data);
                return true;

            case 'getPenaltySettings':
                $this->penaltyController->getSettings($authenticatedUser);
                return true;

            case 'updatePenaltySettings':
                $this->penaltyController->updateSettings($authenticatedUser, $data);
                return true;

            case 'getOverdueAccounts':
                $this->penaltyController->getOverdueCenter($authenticatedUser);
                return true;

            case 'triggerPenaltyCalculation':
                $this->penaltyController->triggerCalculation($authenticatedUser);
                return true;

            // SYNC
            case 'syncState':
                $this->syncController->syncState($authenticatedUser, $data);
                return true;

            default:
                echo json_encode(["success" => false, "message" => "Unknown action", "action" => $action]);
                return false;
        }
    }
}
