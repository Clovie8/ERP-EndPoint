<?php
class AuthControllerHQ {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Ensure the session is active before trying to use $_SESSION
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function login() {
        // Support both JSON payload (React default) and standard POST
        $data = json_decode(file_get_contents("php://input"), true);
        $email = trim($data['email'] ?? $_POST['email'] ?? '');

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format provided.']);
            return;
        }

        $password = $data['password'] ?? $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
            return;
        }

        try {
            $ip_address = $_SERVER['REMOTE_ADDR']; // Get the user's IP

            // --- RATE LIMITING: Phase 1 (Clean up old bans) ---
            $stmtClean = $this->pdo->prepare("DELETE FROM login_attempts WHERE last_attempt < NOW() - INTERVAL 1 MINUTE");
            $stmtClean->execute();

            // --- RATE LIMITING: Phase 2 (Check current bans) ---
            $stmtCheckBan = $this->pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
            $stmtCheckBan->execute([$ip_address]);
            $attemptData = $stmtCheckBan->fetch(PDO::FETCH_ASSOC);

            if ($attemptData && $attemptData['attempts'] >= 5) {
                http_response_code(429); // 429 Too Many Requests
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'Too many failed attempts. Please try again in 1 minute.'
                ]);
                return; 
            }

            $stmt = $this->pdo->prepare("SELECT * FROM super_admins WHERE email = ?");
            $stmt->execute([$email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify Password
            if ($admin && password_verify($password, $admin['password_hash'])) {

                // Update last login (Token column update removed)
                $updateStmt = $this->pdo->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$admin['id']]);

                $stmtClear = $this->pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $stmtClear->execute([$ip_address]);

                // --- REFINED: OBLITERATE GHOST SESSIONS ---
                // Wipe any old memory before starting the 2FA process
                $_SESSION = array(); 
                session_regenerate_id(true); 
                // ------------------------------------------

                // --- 🌟 PHASE 1 OF 2FA (Generate & Email Code) ---
                $code = sprintf("%06d", mt_rand(1, 999999)); 
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                // Save code to database
                $stmtUpdate = $this->pdo->prepare("UPDATE super_admins SET two_factor_code = ?, two_factor_expires_at = ? WHERE id = ?");
                $stmtUpdate->execute([$code, $expires, $admin['id']]);

                // Send the email using your Mail.php
                require_once '../app/config/Mail.php';
                $emailBody = "
                <div style='font-family: sans-serif; text-align: center; padding: 40px;'>
                    <h2>Vendora HQ Security</h2>
                    <p>Your secure HQ login code is:</p>
                    <h1 style='font-size: 40px; color: #2563eb; letter-spacing: 5px;'>$code</h1>
                    <p style='color: #64748b;'>This code expires in 10 minutes.</p>
                </div>";
                
                Mail::send($admin['email'], $admin['name'], "Vendora HQ - Your Login Code", $emailBody, "Code: $code");

                // Tell React to switch to the 2FA screen!
                // NO SESSIONS CREATED YET. STRICTLY SECURE.
                echo json_encode([
                    'status' => '2fa_required', 
                    'user_id' => $admin['id'], 
                    'message' => 'Please check your email for the 6-digit security code.'
                ]);
                return; 
            
            } else {
                // FAILED LOGIN! Record the strike against their IP
                $stmtFail = $this->pdo->prepare("
                    INSERT INTO login_attempts (ip_address, attempts) 
                    VALUES (?, 1) 
                    ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP
                ");
                $stmtFail->execute([$ip_address]);
                echo json_encode(['status' => 'error', 'message' => 'Invalid HQ credentials.']);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    // 🌟 NEW: Server-Side Logout
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 1. Unset all session variables
        session_unset(); 
        
        // 2. Destroy the session entirely from the server
        session_destroy();
        
        // 3. Force the browser to delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Terminal Locked.']);
    }

    public function verify2FA() {
        try {
            $data = json_decode(file_get_contents("php://input"));
            $userId = $data->user_id ?? 0;
            $code = trim($data->code ?? '');

            // Check if the code is correct and hasn't expired
            $stmt = $this->pdo->prepare("SELECT * FROM super_admins WHERE id = ? AND two_factor_code = ? AND two_factor_expires_at > NOW()");
            $stmt->execute([$userId, $code]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin) {
                // --- 🌟 PHASE 2 OF 2FA (Create the Session!) ---
                
                // 1. Wipe the code so it can't be reused
                $this->pdo->prepare("UPDATE super_admins SET two_factor_code = NULL WHERE id = ?")->execute([$admin['id']]);

                // 2. Prevent Session Fixation
                if (session_status() === PHP_SESSION_NONE) { session_start(); }
                session_regenerate_id(true); 

                // 3. Create the God-Mode Session
                $_SESSION['hq_admin_id'] = $admin['id']; 
                $_SESSION['hq_admin_email'] = $admin['email'];
                $_SESSION['hq_admin_name'] = $admin['name'];
                $_SESSION['role'] = 'SuperAdmin'; 
                

                echo json_encode(['status' => 'success', 'message' => 'HQ Access Granted.']);
            } else {
                // 🛠️ REFINEMENT 2: Anti-Brute-Force Delay
                // Pauses execution for 1 second. Makes it impossible to guess 1,000,000 codes in 10 mins.
                sleep(1); 
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code.']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'System error during 2FA.']);
        }
    }

    // 🌟 NEW: Check if Session is Valid
    public function checkSession() {
        if (isset($_SESSION['hq_admin_id'])) {
            echo json_encode([
                'status' => 'success', 
                'user' => [
                    'name' => $_SESSION['hq_admin_name'],
                    'email' => $_SESSION['hq_admin_email']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
        }
    }
}