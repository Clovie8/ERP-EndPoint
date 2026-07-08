<?php
// 1. Custom function to parse .env file without Composer
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        die("Environment file not found.");
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; 
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'"); 
        
        $_ENV[$name] = $value; 
    }
}

// 2. Load the .env file from one folder up (backend/)
loadEnv(__DIR__ . '/../.env');


// =========================================================================
// 1. ENTERPRISE ERROR HANDLING (JSON ONLY)
// =========================================================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'System Error: Something went wrong on the server.', 
        // 'message' => 'System Error: ' . $e->getMessage()
    ]);
    exit;
});

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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

// =========================================================================
// 2. CORS HEADERS (CRITICAL FOR REACT SESSIONS)
// =========================================================================
$allowed_origins = [
    "http://localhost:5173",                     // local React app
    "https://vendora-63q.pages.dev",
    "https://dashboard.vendorapos.app"
];

// 2. Check who is making the request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// 3. If they are in the safe list, grant them access
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $origin);
}

header("Access-Control-Allow-Credentials: true"); // MUST be true for sessions to work
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Add these below your CORS headers
header("X-Frame-Options: DENY"); // Prevents other sites from embedding your API in an iframe
header("X-Content-Type-Options: nosniff"); // Stops browsers from guessing file types maliciously
header("Strict-Transport-Security: max-age=31536000; includeSubDomains"); // Forces browsers to ONLY use HTTPS for the next year

// =========================================================================
// 3. START SESSION MANAGER (Must be before any output)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');



// =========================================================================
// 4. DATABASE CONNECTION
// =========================================================================
require_once '../app/config/database.php';

try {
    $db = new Database();
    $pdo = $db->connect(); 
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Service Unavailable: Database offline.'
    ]);
    exit;
}

$action = $_GET['action'] ?? '';

// =========================================================================
// 5. PUBLIC ROUTES (NO SESSION REQUIRED)
// =========================================================================
// We must allow BOTH the initial login and the 2FA verification to bypass the firewall
if ($action === 'login') {
    require_once '../app/controllers/HQ/AuthControllerHQ.php';
    (new AuthControllerHQ($pdo))->login();
    exit;
}

if ($action === 'verify_2fa') {
    require_once '../app/controllers/HQ/AuthControllerHQ.php';
    (new AuthControllerHQ($pdo))->verify2FA();
    exit;
}

// =========================================================================
// 6. THE FIREWALL (STRICT SESSION VERIFICATION)
// =========================================================================
// If the code reaches here, they MUST have a valid God-Mode session.
if (!isset($_SESSION['hq_admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'HQ Access Denied. Session invalid or expired.']);
    exit;
}

// =========================================================================
// 7. PROTECTED ROUTES
// =========================================================================
switch($action) {
    
    // --- AUTH ACTIONS ---
    case 'logout':
        require_once '../app/controllers/HQ/AuthControllerHQ.php';
        (new AuthControllerHQ($pdo))->logout();
        break;
    case 'check_session':
        require_once '../app/controllers/HQ/AuthControllerHQ.php';
        (new AuthControllerHQ($pdo))->checkSession();
        break;

    // --- DASHBOARD ---
    case 'hq_dashboard_stats':
        require_once '../app/controllers/HQ/DashboardController.php';
        $controller = new DashboardController($pdo);
        $controller->getGlobalStats();
        break;

    // --- TENANT MANAGEMENT ---
    case 'hq_get_tenants':
        require_once '../app/controllers/HQ/TenantController.php';
        (new TenantController($pdo))->getAllTenants(); break;
    case 'hq_create_tenant':
        require_once '../app/controllers/HQ/TenantController.php';
        (new TenantController($pdo))->createCompany(); break;
    case 'hq_toggle_tenant':
        require_once '../app/controllers/HQ/TenantController.php';
        (new TenantController($pdo))->toggleStatus(); break;    
    case 'hq_update_plan':
        require_once '../app/controllers/HQ/TenantController.php';
        (new TenantController($pdo))->updatePlan(); break;
        
    // --- USER MANAGEMENT ---
    case 'hq_get_users':
        require_once '../app/controllers/HQ/UserController.php';
        (new UserController($pdo))->searchUsers(); break;
    case 'hq_override_password':
        require_once '../app/controllers/HQ/UserController.php';
        (new UserController($pdo))->overridePassword(); break;
    case 'hq_impersonate_user':
        require_once '../app/controllers/HQ/UserController.php';
        (new UserController($pdo))->impersonateUser(); break;
    case 'hq_toggle_user': 
        require_once '../app/controllers/HQ/UserController.php'; 
        (new UserController($pdo))->toggleUserStatus(); break;
    case 'hq_send_reset':
        require_once '../app/controllers/HQ/UserController.php';
        (new UserController($pdo))->sendPasswordReset(); break;
    case 'hq_send_verify':
        require_once '../app/controllers/HQ/UserController.php';
        (new UserController($pdo))->sendVerificationEmail(); break;

    // --- BILLING ENGINE ---
    case 'hq_get_billing':
        require_once '../app/controllers/HQ/BillingController.php';
        (new BillingController($pdo))->getBillingData(); break;
    case 'hq_renew_sub':
        require_once '../app/controllers/HQ/BillingController.php';
        (new BillingController($pdo))->renewSubscription(); break;
    case 'hq_run_auto_suspend':
        require_once '../app/controllers/HQ/BillingController.php';
        (new BillingController($pdo))->runAutoSuspend(); break;

    default:
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Invalid HQ Endpoint']);
        break;
}
