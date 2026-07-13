<?php
// 1. Custom function to parse .env file without Composer
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        die("Environment file not found.");
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue; 
        
        // Split variable name and value
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        // Remove spaces and quotes around values
        $value = trim($value, " \t\n\r\0\x0B\"'"); 
        
        // Inject into PHP's global $_ENV array
        $_ENV[$name] = $value; 
    }
}

// 2. Load the .env file from one folder up (backend/)
loadEnv(__DIR__ . '/../.env');

// =========================================================================
// 1. ENTERPRISE ERROR HANDLING (JSON ONLY)
// =========================================================================
// Hide raw HTML errors from the browser
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Catch every fatal crash and format it as JSON
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'System Error: Something went wrong on the server.', 
        // 'message' => 'System Error: ' . $e->getMessage()
        // Note: We deliberately hide $e->getMessage() here so hackers can't see database secrets!
    ]);
    exit;
});

// Convert minor warnings (like missing variables) into exceptions so they don't break JSON formatting
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
// =========================================================================

// =========================================================================
// 2. CORS HEADERS (CRITICAL FOR REACT)
// =========================================================================
// $allowed_origins = [
//     "http://localhost:5173",                     // local React app
//     "https://vendora-63q.pages.dev",
//     "https://dashboard.vendorapos.app"
// ];

// // 2. Check who is making the request
// $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// // 3. If they are in the safe list, grant them access
// if (in_array($origin, $allowed_origins)) {
//     header("Access-Control-Allow-Origin: " . $origin);
// }

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true"); 
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Add these below your CORS headers
header("X-Frame-Options: DENY"); // Prevents other sites from embedding your API in an iframe
header("X-Content-Type-Options: nosniff"); // Stops browsers from guessing file types maliciously
header("Strict-Transport-Security: max-age=31536000; includeSubDomains"); // Forces browsers to ONLY use HTTPS for the next year

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// =========================================================================

header('Content-Type: application/json');

// 3. START SESSION (Updated for Cross-Domain Azure)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        // 'domain' => 'localhost', <-- DELETE OR COMMENT OUT THIS LINE
        'secure' => true,       // CRITICAL: Must be true for Azure HTTPS
        'httponly' => true,
        'samesite' => 'None'    // CRITICAL: Tells the browser it's okay to share cookies between localhost and Azure
    ]);
    session_start();
}

require_once '../app/config/database.php';
require_once '../app/controllers/pos/AuthController.php';

// Initialize DB Safely
try {
    $db = new Database();
    $pdo = $db->connect();
    
    // ONLY initialize Auth globally because it doesn't block unauthenticated users
    $authCtrl = new AuthController($pdo);
    
} catch (Exception $e) {
    // If the database is offline, stop instantly and tell React gracefully
    http_response_code(503);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Service Unavailable: The database is currently offline for maintenance.'
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

// =========================================================================
// THE GLOBAL BOUNCER (ENTERPRISE ACCESS CONTROL)
// =========================================================================

if (isset($_SESSION['user_id'])) {
    $timeout_duration = 1800; // 1800 seconds = 30 minutes
    
    // 1. ENFORCE 30-MINUTE IDLE TIMEOUT
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();     
        session_destroy();   
        
        http_response_code(401); 
        echo json_encode([
            'status' => 'error', 
            'code' => 'SESSION_TIMEOUT',
            'message' => 'For your security, you have been logged out due to 30 minutes of inactivity.'
        ]);
        exit;
    }

    // 2. --- NEW: THE SINGLE ACTIVE DEVICE & SUSPENSION CHECK ---
    $userId = $_SESSION['user_id'];
    $localSessionToken = $_SESSION['active_session_token'] ?? '';
    
    // --- THE FIX: Check if the Super Admin holds the VIP Pass ---
    $isImpersonating = isset($_SESSION['is_impersonating']) && $_SESSION['is_impersonating'] === true;

    // Fetch the absolute latest token and status from the database
    $stmtCheck = $pdo->prepare("SELECT active_session_token, status FROM users WHERE id = ?");
    $stmtCheck->execute([$userId]);
    $userCheck = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    // If user no longer exists in database...
    if (!$userCheck) {
        session_unset();
        session_destroy();
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Account no longer exists.']);
        exit;
    }

    // --- THE FIX: Only kick for token mismatch if NOT impersonating ---
    if (!$isImpersonating && $userCheck['active_session_token'] !== $localSessionToken) {
        session_unset();
        session_destroy(); 
        
        http_response_code(401);
        echo json_encode([
            'status' => 'session_expired', 
            'message' => 'Your account was logged into from another device. You have been logged out here.'
        ]);
        exit;
    }
    
    // Account suspension check (Instantly kick them out if an Admin suspends them mid-session)
    if ($userCheck['status'] === 'suspended') {
        session_unset();
        session_destroy();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Your account has been suspended.']);
        exit;
    }
    // -----------------------------------------------------------
    
    // If they pass all security checks, reset the idle clock back to 0!
    $_SESSION['last_activity'] = time(); 
}

$userRole = $_SESSION['role'] ?? 'Guest';

// 2. Define routes that are strictly forbidden for Cashiers
$adminOnlyRoutes = [
    'add', 'update_product', 'delete', 'bulk_import', 'update_batch',
    'update_transaction', 'delete_transaction',
    'dashboard_data', 'fetch_expenses', 'add_expense', 'update_expense', 'delete_expense',
    'generate_report', 'stock_status_data', 'get_tax_report',
    'get_team', 'add_team_member', 'update_team_member', 'delete_team_member',
    'update_company',
    'update_proforma', 'delete_proforma'
];

// 2. Kick out unauthorized users BEFORE the switch statement even runs
if (in_array($action, $adminOnlyRoutes) && $userRole !== 'Admin'  && $userRole !== 'Manager') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error', 
        'message' => 'HTTP 403: Security Violation. You do not have Admin privileges to perform this action.'
    ]);
    exit; // Instantly kills the script
}
// =========================================================================


switch ($action) {
    // ==========================================
    // PUBLIC ENDPOINTS (No session required)
    // ==========================================
    case 'login': if ($_SERVER['REQUEST_METHOD'] === 'POST') $authCtrl->login(); break;
    case 'register': if ($_SERVER['REQUEST_METHOD'] === 'POST') $authCtrl->register(); break;
    case 'verify_email': $authCtrl->verifyEmail(); break;
    case 'forgot_password': if ($_SERVER['REQUEST_METHOD'] === 'POST') $authCtrl->forgotPassword(); break;
    case 'reset_password': if ($_SERVER['REQUEST_METHOD'] === 'POST') $authCtrl->resetPassword(); break;
    case 'logout': $authCtrl->logout(); break;

    // ==========================================
    // PROTECTED ENDPOINTS (Session required)
    // ==========================================
    case 'get_profile': 
        $authCtrl->getProfile(); 
        break; 

    // DASHBOARD
    case 'dashboard_data':
        require_once '../app/controllers/pos/DashboardController.php';
        $dash = new DashboardController($pdo);
        $dash->getData();
        break;

    // STOCK & PRODUCTS
    case 'fetch': 
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        $productCtrl->index(); 
        break;
    case 'add': 
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $productCtrl->store(); 
        break;
    case 'update_product': 
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $productCtrl->update(); 
        break;
    case 'delete': 
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $productCtrl->destroy(); 
        break;
    case 'toggle_request':
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        $productCtrl->toggleRequest();
        break;
    case 'get_serials': 
        require_once '../app/controllers/pos/ProductController.php';
        $productCtrl = new ProductController($pdo);
        $productCtrl->getSerialLifecycle(); 
        break;

    // TRANSACTIONS
    case 'transaction': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $transCtrl->handle(); 
        break;
    case 'transaction_history': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        $transCtrl->index(); 
        break;
    case 'update_transaction': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $transCtrl->update(); 
        break;
    case 'delete_transaction': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $transCtrl->destroy(); 
        break;
    case 'pay_debt': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $transCtrl->payDebt(); 
        break;
    case 'get_product_by_serial': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        $transCtrl->getProductBySerial(); 
        break;
    case 'get_payment_history': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        $transCtrl->getPaymentHistory(); 
        break;
    case 'get_all_payment_history': 
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        $transCtrl->getAllPaymentHistory(); 
        break;
    case 'get_invoice_products':
        require_once '../app/controllers/pos/TransactionController.php';
        $transCtrl = new TransactionController($pdo);
        $transCtrl->getInvoiceProducts();
        break;

    // REPORTS & STATUS
    case 'generate_report': 
        require_once '../app/controllers/pos/ReportController.php';
        $repCtrl = new ReportController($pdo);
        $repCtrl->generate();
        break;
    case 'stock_status_data':
        require_once '../app/controllers/pos/StockStatusController.php';
        $statusCtrl = new StockStatusController($pdo);
        $statusCtrl->getData();
        break;

    // EXPENSES
    case 'fetch_expenses':
        require_once '../app/controllers/pos/ExpenseController.php';
        $expCtrl = new ExpenseController($pdo);
        $expCtrl->index();
        break;
    case 'add_expense':
        require_once '../app/controllers/pos/ExpenseController.php';
        $expCtrl = new ExpenseController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $expCtrl->store();
        break;
    case 'update_expense':                                             
        require_once '../app/controllers/pos/ExpenseController.php';       
        $expCtrl = new ExpenseController($pdo);                        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $expCtrl->update(); 
        break;                                                         
    case 'delete_expense':
        require_once '../app/controllers/pos/ExpenseController.php';
        $expCtrl = new ExpenseController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $expCtrl->destroy();
        break;

    // PROFILE & TEAM MANAGEMENT
    case 'update_profile': 
        require_once '../app/controllers/pos/TeamController.php';
        $teamCtrl = new TeamController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $teamCtrl->updateProfile(); 
        break; 
    case 'get_team': 
        require_once '../app/controllers/pos/TeamController.php';
        $teamCtrl = new TeamController($pdo);
        $teamCtrl->getTeam(); 
        break;
    case 'add_team_member': 
        require_once '../app/controllers/pos/TeamController.php';
        $teamCtrl = new TeamController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $teamCtrl->addMember(); 
        break;    
    case 'update_team_member': 
        require_once '../app/controllers/pos/TeamController.php';
        $teamCtrl = new TeamController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $teamCtrl->updateMember(); 
        break; 
    case 'delete_team_member': 
        require_once '../app/controllers/pos/TeamController.php';
        $teamCtrl = new TeamController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $teamCtrl->deleteMember(); 
        break; 

    // COMPANY BRANDING
    case 'get_company':
        require_once '../app/controllers/pos/CompanyController.php';
        $compCtrl = new CompanyController($pdo);
        $compCtrl->getSettings();
        break;
    case 'update_company':
        require_once '../app/controllers/pos/CompanyController.php';
        $compCtrl = new CompanyController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $compCtrl->updateSettings();
        break;
    case 'get_billing_info':
        require_once '../app/controllers/pos/CompanyController.php';
        (new CompanyController($pdo))->getBillingInfo(); 
        break;

    // SHIFT MANAGEMENT
    case 'check_shift':
        require_once '../app/controllers/pos/ShiftController.php';
        $shiftCtrl = new ShiftController($pdo);
        $shiftCtrl->current();
        break;
    case 'get_shifts':
        require_once '../app/controllers/pos/ShiftController.php';
        $shiftCtrl = new ShiftController($pdo);
        $shiftCtrl->index();
        break;
    case 'start_shift':
        require_once '../app/controllers/pos/ShiftController.php';
        $shiftCtrl = new ShiftController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $shiftCtrl->start();
        break;
    case 'end_shift':
        require_once '../app/controllers/pos/ShiftController.php';
        $shiftCtrl = new ShiftController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $shiftCtrl->end();
        break;

    // TAX & COMPLIANCE
    case 'get_tax_report': 
        require_once '../app/controllers/pos/TaxController.php';
        $taxCtrl = new TaxController($pdo);
        $taxCtrl->getMonthlyReport();
        break;
    case 'declare_tax_month': 
        require_once '../app/controllers/pos/TaxController.php';
        (new TaxController($pdo))->declareMonth(); 
        break;
    case 'get_tax_history': 
        require_once '../app/controllers/pos/TaxController.php';
        (new TaxController($pdo))->getDeclarationHistory(); 
        break;

    // Add this inside your routing switch statement in api.php
    case 'bulk_import':
        require_once '../app/controllers/pos/ProductController.php';
        $controller = new ProductController($pdo);
        $controller->bulkImport();
        break;

    // PROFORMA INVOICES
    case 'get_proformas':
        require_once '../app/controllers/pos/ProformaController.php';
        $piCtrl = new ProformaController($pdo);
        $piCtrl->index();
        break;
    case 'get_single_proforma':
        require_once '../app/controllers/pos/ProformaController.php';
        $piCtrl = new ProformaController($pdo);
        $piCtrl->show();
        break;
    case 'save_proforma':
        require_once '../app/controllers/pos/ProformaController.php';
        $piCtrl = new ProformaController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $piCtrl->store();
        break;
    case 'delete_proforma':
        require_once '../app/controllers/pos/ProformaController.php';
        $piCtrl = new ProformaController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $piCtrl->destroy();
        break;
    case 'update_proforma':
        require_once '../app/controllers/pos/ProformaController.php';
        $piCtrl = new ProformaController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $piCtrl->update();
        break;


    // BATCHES
    case 'batches':
        require_once '../app/controllers/pos/BatchController.php';
        $controller = new BatchController($pdo);
        $controller->index();
        break;
    case 'update_batch':
        require_once '../app/controllers/pos/BatchController.php';
        $controller = new BatchController($pdo);
        $controller->update();
        break;
    case 'get_batch_history':
        require_once '../app/controllers/pos/BatchController.php';
        $controller = new BatchController($pdo);
        $controller->getHistory();
        break;

    // NOTIFICATIONS    
    case 'get_notifications':
        require_once '../app/controllers/pos/NotificationController.php';
        $notifCtrl = new NotificationController($pdo);
        $notifCtrl->fetch();
        break;
    case 'mark_notifications_read':
        require_once '../app/controllers/pos/NotificationController.php';
        $notifCtrl = new NotificationController($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $notifCtrl->markRead();
        break;
    case 'run_daily_checks':
        require_once '../app/controllers/pos/NotificationController.php';
        $notifCtrl = new NotificationController($pdo);
        $notifCtrl->runDailyChecks();
        break;

    case 'resend_verification':
        require_once '../app/controllers/pos/AuthController.php';
        (new AuthController($pdo))->resendVerification(); 
        break;

    // Contact Management
    case 'get_contacts':
        require_once '../app/controllers/pos/ContactController.php';
        $contactController = new ContactController($pdo);
        $contactController->getContacts();
        break;
    case 'create_contact':
        require_once '../app/controllers/pos/ContactController.php';
        $contactController = new ContactController($pdo);
        $contactController->createContact();
        break; 
    case 'update_contact':
        require_once '../app/controllers/pos/ContactController.php';
        $contactController = new ContactController($pdo);
        $contactController->updateContact();
        break;
    case 'delete_contact':
        require_once '../app/controllers/pos/ContactController.php';
        $contactController = new ContactController($pdo);
        $contactController->deleteContact();
        break;   
    
    // NOT FOUND
    default: 
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Action']);
        break;
}
