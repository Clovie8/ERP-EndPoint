<?php
// Include PHPMailer classes manually
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Require the actual files (adjust the path if your folder structure is different)
require '../vendor/PHPMailer/src/Exception.php';
require '../vendor/PHPMailer/src/PHPMailer.php';
require '../vendor/PHPMailer/src/SMTP.php';

class AuthController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // ==========================================================
    // 1. BUSINESS & USER REGISTRATION
    // ==========================================================
    public function register() {
        $compName = trim($_POST['company_name'] ?? '');
        $compType = trim($_POST['business_type'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $tin = trim($_POST['tin_number'] ?? null);

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? ''; 

        if ($compName === '' || $compType === '' || $phone === '' || $name === '' || $email === '' || $password === '') {
            echo json_encode(['status' => 'error', 'message' => 'All required fields must be filled']);
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $this->pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Email already exists in the system.']);
                return;
            }

            $trialEndsAt = date('Y-m-d H:i:s', strtotime('+14 days'));
            $stmt = $this->pdo->prepare(" INSERT INTO companies (name, business_type, phone, email, location, tin_number, status, plan_tier, subscription_ends_at, max_users, max_branches) VALUES (?, ?, ?, ?, ?, ?, 'trial', 'basic', ?, 5, 1)");
            $stmt->execute([ $compName, $compType, $phone, $email, $location, $tin, $trialEndsAt ]);
            $companyId = $this->pdo->lastInsertId();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32)); 
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $this->pdo->prepare("INSERT INTO users (company_id, name, email, password, role, status, verification_token, token_expires_at) VALUES (?, ?, ?, ?, 'Admin', 'pending', ?, ?)");
            $stmt->execute([$companyId, $name, $email, $hash, $token, $expiresAt]);
            $userId = $this->pdo->lastInsertId();

            $verifyLink = "https://dashboard.vendorapos.app/verify-email?token=" . $token; 
            
            // --- PROFESSIONAL HTML EMAIL TEMPLATE ---
            $emailBody = "
            <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                <div style='max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); text-align: center;'>
                    <span style='color: #2563eb; font-size: 24px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 24px;'>VENDORA</span>
                    <h2 style='color: #0f172a; font-size: 22px; font-weight: 800; margin-top: 0; margin-bottom: 12px;'>Welcome aboard, $name!</h2>
                    <p style='color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;'>You're just one step away from managing your inventory smarter. Please verify your email address to activate your account and access your dashboard.</p>
                    <a href='$verifyLink' style='background-color: #2563eb; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block;'>Verify My Account</a>
                    <div style='margin-top: 32px; padding-top: 24px; border-top: 1px solid #f1f5f9; color: #94a3b8; font-size: 12px;'>
                        If you did not request this account, please safely ignore this email.
                    </div>
                </div>
            </div>";
            
            // $this->sendEmail($email, $name, "Verify Your Vendora Account", $emailBody);
            require_once '../app/config/Mail.php';
                    Mail::send($email, $name, "Verify Your Vendora Account", $emailBody);
            
            // Log Audit for Registration
            $this->logAudit($userId, 'Register', "New business registered: $compName by $email", $companyId);

            $this->pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Registration successful!']);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Registration failed: ' . $e->getMessage()]);
        }
    }

    public function resendVerification() {
        // 1. Read the raw JSON payload sent from React
        $data = json_decode(file_get_contents("php://input"), true);
        $email = trim($data['email'] ?? '');

        if ($email === '') {
            echo json_encode(['status' => 'error', 'message' => 'Email address is required.']);
            return;
        }

        try {
            // 2. Verify the user actually exists
            $stmt = $this->pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'No account found with this email.']);
                return;
            }

            // 3. Generate a new secure verification token & expiration
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // 4. Save the new token and expiration to the database
            $updateStmt = $this->pdo->prepare("UPDATE users SET verification_token = ?, token_expires_at = ? WHERE email = ?");
            $updateStmt->execute([$token, $expiresAt, $email]);

            // 5. --- PROFESSIONAL HTML EMAIL TEMPLATE ---
            $verifyLink = "https://dashboard.vendorapos.app/verify-email?token=" . $token; 
            $name = $user['name'];

            $emailBody = "
            <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                <div style='max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); text-align: center;'>
                    <span style='color: #2563eb; font-size: 24px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 24px;'>VENDORA</span>
                    <h2 style='color: #0f172a; font-size: 22px; font-weight: 800; margin-top: 0; margin-bottom: 12px;'>Hello again, $name!</h2>
                    <p style='color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;'>You recently requested a new verification link. Please verify your email address to activate your account and access your dashboard. This link will expire in 24 hours.</p>
                    <a href='$verifyLink' style='background-color: #2563eb; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block;'>Verify My Account</a>
                    <div style='margin-top: 32px; padding-top: 24px; border-top: 1px solid #f1f5f9; color: #94a3b8; font-size: 12px;'>
                        If you did not request this email, please safely ignore it.
                    </div>
                </div>
            </div>";

            // 6. Send the email using your Mail config
            require_once '../app/config/Mail.php';
            try {
                Mail::send($email, $name, "Verify Your Vendora Account", $emailBody);
                echo json_encode(['status' => 'success', 'message' => 'Verification email resent successfully.']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to send email. Check server configuration.']);
            }

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error while requesting new token.']);
        }
    }

    // ==========================================================
    // 2. SECURE LOGIN (WITH BRUTE FORCE PROTECTION)
    // ==========================================================
    public function login() {
        try {
            $email = trim($_POST['email'] ?? '');
            if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                echo json_encode(['status' => 'error', 'message' => 'Invalid email format provided.']);
                return;
            }
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
                return;
            }

            $ip_address = $_SERVER['REMOTE_ADDR']; // Get the user's IP

            // --- RATE LIMITING: Phase 1 (Clean up old bans) ---
            // Automatically forgive IPs after 1 minute of cooling off
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

            // --- AUTHENTICATION: Phase 3 (Verify Credentials) ---
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                
                // Account Status Checks
                if ($user['status'] === 'pending') {
                    echo json_encode(['status' => 'error', 'message' => 'Please verify your email address before logging in.']);
                    return;
                }
                if ($user['status'] === 'suspended') {
                    echo json_encode(['status' => 'error', 'message' => 'This account has been suspended. Contact support.']);
                    return;
                }

                // --- NEW: COMPANY SUBSCRIPTION & STATUS CHECK ---
                $stmtCompany = $this->pdo->prepare("SELECT status, subscription_ends_at FROM companies WHERE id = ?");
                $stmtCompany->execute([$user['company_id']]);
                $company = $stmtCompany->fetch(PDO::FETCH_ASSOC);

                if ($company) {
                    if ($company['status'] === 'suspended') {
                        echo json_encode(['status' => 'error', 'message' => 'Your company account is suspended. Please contact support.']);
                        return;
                    }

                    if (!empty($company['subscription_ends_at']) && strtotime($company['subscription_ends_at']) < time()) {
                        echo json_encode(['status' => 'error', 'message' => 'Your subscription has expired. Please renew to continue using the system.']);
                        return;
                    }
                }
                // --- END NEW CHECK ---

                // SUCCESS! Clear their IP from the penalty box
                $stmtClear = $this->pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $stmtClear->execute([$ip_address]);

                // Wipe any temporary data left over from registration/verification
                $_SESSION = array();

                // Prevent Session Fixation Attacks
                session_regenerate_id(true); 

                // SINGLE ACTIVE DEVICE TOKEN ---
                // 1. Generate a cryptographically secure token for this specific login
                $sessionToken = bin2hex(random_bytes(32));
                
                // 2. Overwrite the database token (This instantly invalidates any other device!)
                $stmtToken = $this->pdo->prepare("UPDATE users SET active_session_token = ? WHERE id = ?");
                $stmtToken->execute([$sessionToken, $user['id']]);
                
                // 3. Save it to the current server session
                $_SESSION['active_session_token'] = $sessionToken;
                // ----------------------------------------

                // Create Secure Session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['company_id'] = $user['company_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time(); // Start the 30-minute idle clock!

                $this->logAudit($user['id'], 'Login', 'User logged in successfully');
                echo json_encode(['status' => 'success']);

            } else {
                // FAILED LOGIN! Record the strike against their IP
                $stmtFail = $this->pdo->prepare("
                    INSERT INTO login_attempts (ip_address, attempts) 
                    VALUES (?, 1) 
                    ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP
                ");
                $stmtFail->execute([$ip_address]);

                echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
            }
            
        } catch (Exception $e) {
            // --- NEW: ERROR HANDLING ---
            // Catch any database or system crashes securely
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'System error during login: ' . $e->getMessage()]);
        }
    }

    // ==========================================================
    // 3. LOGOUT
    // ==========================================================
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            
            // 1. Log the audit trail
            $this->logAudit($userId, 'Logout', 'User logged out successfully');
            
            // 2. --- NEW: Obliterate the database session token ---
            // This guarantees the session is permanently dead server-side
            $stmt = $this->pdo->prepare("UPDATE users SET active_session_token = NULL WHERE id = ?");
            $stmt->execute([$userId]);
        }
        
        // 3. Clear the session data in memory
        $_SESSION = array();
        
        // 4. --- REFINED: Destroy the cookie securely across domains ---
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            
            // Using modern PHP array syntax ensures SameSite=None is respected during deletion
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params["path"],
                'domain' => $params["domain"],
                'secure' => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => $params["samesite"] ?? 'None'
            ]);
        }
        
        // 5. Destroy the session file on the server
        session_destroy();
        
        echo json_encode(['status' => 'success', 'message' => 'Logged out successfully']);
        exit;
    }

    // ==========================================================
    // 4. FORGOT PASSWORD FLOW
    // ==========================================================
    public function forgotPassword() {
        $email = $_POST['email'] ?? '';
        
        $stmt = $this->pdo->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $this->pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expiresAt]);

            $resetLink = "https://dashboard.vendorapos.app/reset-password?token=" . $token;
            
            $emailBody = "
            <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                <div style='max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); text-align: center;'>
                    <span style='color: #2563eb; font-size: 24px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 24px;'>VENDORA</span>
                    <h2 style='color: #0f172a; font-size: 22px; font-weight: 800; margin-top: 0; margin-bottom: 12px;'>Password Reset Request</h2>
                    <p style='color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;'>We received a request to reset the password for your Vendora account. Click the button below to create a new password. This link will expire in 1 hour.</p>
                    <a href='$resetLink' style='background-color: #0f172a; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block;'>Reset Password</a>
                    <div style='margin-top: 32px; padding-top: 24px; border-top: 1px solid #f1f5f9; color: #94a3b8; font-size: 12px;'>
                        If you didn't request a password reset, you can safely ignore this email.
                    </div>
                </div>
            </div>";
            
            // $this->sendEmail($email, $user['name'], "Reset Your Password", $emailBody);
            require_once '../app/config/Mail.php';
                    Mail::send($email, $user['name'], "Reset Your Password", $emailBody);
        }

        echo json_encode(['status' => 'success', 'message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword() {
        try {
            $token = $_POST['token'] ?? '';
            $newPassword = $_POST['password'] ?? '';

            $stmt = $this->pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
            $stmt->execute([$token]);
            $resetRequest = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($resetRequest) {
                $email = $resetRequest['email'];
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->execute([$hash, $email]);

                $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                $stmt->execute([$email]);

                echo json_encode(['status' => 'success', 'message' => 'Password reset successfully. You can now log in.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired reset token.']);
            }
        } catch (Exception $e) {
            // --- NEW: ERROR HANDLING ---
            // Catch any database or system crashes securely
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'System error during password reset: ' . $e->getMessage()]);
        }
    }

    // ==========================================================
    // EMAIL VERIFICATION ENDPOINT
    // ==========================================================
    public function verifyEmail() {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            echo json_encode(['status' => 'error', 'message' => 'No verification token provided.']);
            return;
        }

        try {
            // 1. Fetch User AND Company Details using a JOIN
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.name, u.email, c.name AS company_name, c.status AS company_status, c.plan_tier, c.subscription_ends_at 
                FROM users u
                JOIN companies c ON u.company_id = c.id
                WHERE u.verification_token = ? 
                AND u.token_expires_at > NOW() 
                AND u.status = 'pending'
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 2. Update the user status to active
                $updateStmt = $this->pdo->prepare("UPDATE users SET status = 'active', verification_token = NULL WHERE id = ?");
                $updateStmt->execute([$user['id']]);

                // 3. Format Variables for the Email
                $name = htmlspecialchars($user['name']);
                $companyName = htmlspecialchars($user['company_name']);
                $planTier = strtoupper($user['plan_tier']);
                $status = ucfirst($user['company_status']);
                
                // Format the date nicely (e.g., "October 15, 2024")
                $endDate = !empty($user['subscription_ends_at']) 
                    ? date('F j, Y', strtotime($user['subscription_ends_at'])) 
                    : 'Lifetime / N/A';
                
                $loginLink = "https://dashboard.vendorapos.app/login"; // Adjust if your login URL is different

                // 4. Highly Professional Welcome HTML Template
                $emailBody = "
                <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);'>
                        
                        <div style='text-align: center; margin-bottom: 32px;'>
                            <span style='color: #2563eb; font-size: 26px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 16px;'>VENDORA</span>
                            <h2 style='color: #0f172a; font-size: 24px; font-weight: 800; margin: 0 0 12px 0;'>Verification Successful!</h2>
                            <p style='color: #64748b; font-size: 16px; line-height: 1.6; margin: 0;'>Welcome aboard, $name! Your email has been verified. You are now fully set up to streamline your inventory, track sales, and scale your business with Vendora's smart Point-of-Sale ecosystem.</p>
                        </div>

                        <div style='background-color: #f1f5f9; border-radius: 16px; padding: 24px; margin-bottom: 32px; border: 1px solid #e2e8f0;'>
                            <h3 style='color: #0f172a; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0; margin-bottom: 16px; border-bottom: 1px solid #cbd5e1; padding-bottom: 12px;'>Your Account Overview</h3>
                            
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500;'>Company Name:</td>
                                    <td style='padding: 10px 0; color: #0f172a; font-size: 15px; font-weight: 700; text-align: right;'>$companyName</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>Account Status:</td>
                                    <td style='padding: 10px 0; color: #10b981; font-size: 15px; font-weight: 800; text-align: right; border-top: 1px dashed #cbd5e1;'>$status</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>Active Plan:</td>
                                    <td style='padding: 10px 0; color: #2563eb; font-size: 15px; font-weight: 800; text-align: right; border-top: 1px dashed #cbd5e1;'>$planTier Plan</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>Subscription Ends:</td>
                                    <td style='padding: 10px 0; color: #ef4444; font-size: 15px; font-weight: 700; text-align: right; border-top: 1px dashed #cbd5e1;'>$endDate</td>
                                </tr>
                            </table>
                        </div>

                        <div style='text-align: center;'>
                            <a href='$loginLink' style='background-color: #0f172a; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block; transition: background-color 0.3s;'>Sign In to Dashboard</a>
                            <p style='margin-top: 24px; color: #94a3b8; font-size: 12px;'>Need help? Reply to this email to reach our support team.</p>
                        </div>

                    </div>
                </div>";

                // 5. Send the Welcome Email (Using the 5-argument format)
                require_once '../app/config/Mail.php';
                Mail::send($user['email'], $user['name'], "Welcome to Vendora - Account Verified!", $emailBody, "");

                echo json_encode(['status' => 'success', 'message' => 'Email verified successfully! You can now log in.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid or expired verification link.']);
            }
        } catch (Exception $e) {
            // Error handling via Try/Catch
            echo json_encode(['status' => 'error', 'message' => 'An error occurred during verification: ' . $e->getMessage()]);
        }
    }

    // ==========================================================
    // HELPER FUNCTIONS
    // ==========================================================
    // private function sendEmail($toEmail, $toName, $subject, $body) {
    //     $mail = new PHPMailer(true);
    //     try {
    //         $mail->isSMTP();
    //         $mail->Host       = 'smtp.gmail.com';
    //         $mail->SMTPAuth   = true;
    //         $mail->Username   = 'dkndclovis8@gmail.com'; 
    //         $mail->Password   = 'ndgr pcqs donf gwty';  
    //         // $mail->Username   = $_ENV['SMTP_USER']; 
    //         // $mail->Password   = $_ENV['SMTP_PASS'];

    //         $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    //         $mail->Port       = 587;

    //         $mail->setFrom('noreply@vendora.com', 'Vendora SaaS');
    //         $mail->addAddress($toEmail, $toName);

    //         $mail->isHTML(true);
    //         $mail->Subject = $subject;
    //         $mail->Body    = $body;

    //         $mail->send();
    //         return true;
    //     } catch (Exception $e) {
    //         error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    //         return false;
    //     }
    // }

    public function getProfile() {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
            return;
        }
        $stmt = $this->pdo->prepare("SELECT name, email, role, status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            echo json_encode(['status' => 'success', 'data' => $user]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'User not found']);
        }
    }

    // UPDATED FOR MULTI-TENANCY: Now securely fetches and uses company_id
    private function logAudit($userId, $action, $description, $companyIdOverride = null) {
        try {
            $companyId = $companyIdOverride ?? ($_SESSION['company_id'] ?? null);
            
            if (!$companyId) {
                $stmt = $this->pdo->prepare("SELECT company_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $companyId = $stmt->fetchColumn();
            }

            if ($companyId) {
                $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$companyId, $userId, $action, $description]);
            }
        } catch (Exception $e) {
            // Silently fail if audit log crashes so it doesn't break the app flow
        }
    }
}