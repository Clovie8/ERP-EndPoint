<?php
class UserController {
    private $pdo;

    public function __construct($pdo) { $this->pdo = $pdo; }

    public function searchUsers() {
        try {
            // Note: Assuming your users table has a 'status' column. If it doesn't, 
            // you can run: ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active';
            $stmt = $this->pdo->query("
                SELECT u.id, u.name, u.email, u.role, COALESCE(u.status, 'active') as status, c.name as company_name, c.id as company_id 
                FROM users u 
                LEFT JOIN companies c ON u.company_id = c.id 
                ORDER BY u.created_at DESC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch users.']);
        }
    }

    // UPGRADE: Secure Auto-Password Generation
    public function overridePassword() {
        $data = json_decode(file_get_contents("php://input"));
        $userId = $data->user_id ?? 0;

        // Generate a secure 8-character random password
        $tempPassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ23456789!@#$'), 0, 8);
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Password reset successfully.',
                'new_password' => $tempPassword // Send back to React to display to the Admin
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to override password.']);
        }
    }

    // UPGRADE: Lock/Unlock Individual Users
    public function toggleUserStatus() {
        $data = json_decode(file_get_contents("php://input"));
        $userId = (int)($data->user_id ?? 0);
        $newStatus = $data->status ?? 'active';

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $userId]);
            echo json_encode(['status' => 'success', 'message' => "User account $newStatus."]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update user status.']);
        }
    }

    // UPGRADE: True Session-Based Impersonation (God Mode)
    public function impersonateUser() {
        $data = json_decode(file_get_contents("php://input"));
        $userId = $data->user_id ?? 0;

        try {
            // 1. Fetch the target user's details
            $stmt = $this->pdo->prepare("SELECT id, name, company_id, role, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['status'] !== 'suspended') {
                
                // Ensure session is started
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                // 2. INJECT POS SESSION VARIABLES 
                // We leave the $_SESSION['hq_admin_id'] intact so you don't lose Admin access,
                // but we add the exact variables the POS expects!
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['company_id'] = $user['company_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                
                // Optional: Flag this as an impersonated session so the backend knows
                $_SESSION['is_impersonating'] = true; 

                // Log the audit event for security
                // $this->logAudit($user['id'], 'Impersonation', "Super Admin logged into account.");

                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Impersonation established successfully.'
                ]);
                
            } else {
                echo json_encode(['status' => 'error', 'message' => 'User not found or account is suspended.']);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Impersonation failed.']);
        }
    }
    
    // UPGRADE: REAL Password Reset Email via PHPMailer
    public function sendPasswordReset() {
        // We use json_decode because the React frontend sends a JSON payload
        $data = json_decode(file_get_contents("php://input"));
        $email = $data->email ?? '';
        
        if (empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
            return;
        }

        try {
            // 1. Check if user exists
            $stmt = $this->pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 2. Generate token and 1-hour expiration
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // 3. Insert into your password_resets table
                $stmt = $this->pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$email, $token, $expiresAt]);

                $resetLink = "https://dashboard.vendorapos.app/reset-password?token=" . $token;
                
                // 4. Your exact HTML Template
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
                
                // 5. Send Email 
                // (If your UserController extends a base controller that has $this->sendEmail, 
                // you can use $this->sendEmail($email, $user['name'], "Reset Your Password", $emailBody);)
                // Otherwise, we use the Mail config we just built:
                require_once '../app/config/Mail.php';
                Mail::send($email, $user['name'], "Reset Your Password", $emailBody);
            }

            // Always return a generic success message for security (prevents email enumeration hacking)
            echo json_encode(['status' => 'success', 'message' => 'If that email exists, a reset link has been sent.']);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to process reset request.']);
        }
    }

    

    // UPGRADE: REAL Verification Email via PHPMailer
    public function sendVerificationEmail() {
        $data = json_decode(file_get_contents("php://input"));
        $email = $data->email ?? '';

        if(empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
            return;
        }

        try {
            // 1. Find the existing user to get their name
            $stmt = $this->pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'User not found in the system.']);
                return;
            }

            $name = $user['name'];

            // 2. Generate new 24-hour token
            $token = bin2hex(random_bytes(32)); 
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // 3. Update the user's specific verification columns
            $updateStmt = $this->pdo->prepare("UPDATE users SET verification_token = ?, token_expires_at = ? WHERE email = ?");
            $updateStmt->execute([$token, $expiresAt, $email]);

            // 4. Build the Verification Link
            $verifyLink = "https://dashboard.vendorapos.app/verify-email?token=" . $token; 
            
            // 5. Your Exact Professional HTML Template
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
            
            // 6. Send the email using the PHPMailer config we built
            require_once '../app/config/Mail.php';
            if (Mail::send($email, $name, "Verify Your Vendora Account", $emailBody)) {
                echo json_encode(['status' => 'success', 'message' => "Verification email delivered to $email."]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Email delivery failed. Check SMTP settings.']);
            }

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to process verification email.']);
        }
    }

    
}