<?php
class TeamController {
    private $pdo;
    private $companyId;
    private $userRole;
    private $userId; // NEW: Stored for Audit Logs

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
        $this->userId = (int)$_SESSION['user_id'];
    }

    private function requireAdmin() {
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Admins only.']);
            exit;
        }
    }

    public function getTeam() {
        $this->requireAdmin();
        
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, role, status 
            FROM users 
            WHERE company_id = ? 
            ORDER BY 
                CASE WHEN status = 'pending' THEN 1 ELSE 2 END ASC, 
                role ASC, 
                id DESC
        ");
        
        $stmt->execute([$this->companyId]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function addMember() {
        $this->requireAdmin();
        
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'Cashier'; 

        try {
            // NEW: Check User Limit against Company Subscription
            $stmtLimit = $this->pdo->prepare("SELECT max_users FROM companies WHERE id = ?");
            $stmtLimit->execute([$this->companyId]);
            $maxUsers = $stmtLimit->fetchColumn() ?: 1; // Fallback to 1 if missing

            $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND status = 'active'");
            $stmtCount->execute([$this->companyId]);
            $currentUsers = $stmtCount->fetchColumn();

            if ($currentUsers >= $maxUsers) {
                echo json_encode(['status' => 'error', 'message' => 'User limit reached. Please upgrade your plan to add more members.']);
                return;
            }

            // EXISTING: Check if Email already exists
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Email already exists.']);
                return;
            }

            // EXISTING: Hash password
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            $token = bin2hex(random_bytes(32)); 
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = $this->pdo->prepare("INSERT INTO users (company_id, name, email, password, role, status, verification_token, token_expires_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)");
            
            if ($stmt->execute([$this->companyId, $name, $email, $hash, $role, $token, $expiresAt])) {
                
                $verifyLink = "https://dashboard.vendorapos.app/verify-email?token=" . $token; 
                
                $emailBody = "
                <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                    <div style='max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); text-align: center;'>
                        <span style='color: #2563eb; font-size: 24px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 24px;'>VENDORA</span>
                        <h2 style='color: #0f172a; font-size: 22px; font-weight: 800; margin-top: 0; margin-bottom: 12px;'>You've been invited, $name!</h2>
                        <p style='color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;'>You have been added as a <b>$role</b> on Vendora. Please verify your email address to activate your account and access the system.</p>
                        <a href='$verifyLink' style='background-color: #2563eb; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block;'>Verify My Account</a>
                        <div style='margin-top: 32px; padding-top: 24px; border-top: 1px solid #f1f5f9; color: #94a3b8; font-size: 12px;'>
                            If you did not expect this invitation, please safely ignore this email.
                        </div>
                    </div>
                </div>";
                
                require_once '../app/config/Mail.php';
                Mail::send($email, $name, "Verify Your Vendora Account", $emailBody);
                
                $this->logAudit("Added Team Member", "Registered a new $role account for: $name ($email). Verification email sent.");

                echo json_encode(['status' => 'success', 'message' => "Team member added! A verification email has been sent to them."]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Failed to add team member."]);
            }

        } catch (PDOException $e) {
            // NEW: Try-Catch error handling
            echo json_encode(['status' => 'error', 'message' => 'Database error while trying to add team member.']);
        }
    }

    public function updateMember() {
        $this->requireAdmin();
        
        $id = (int)$_POST['id'];
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'Cashier';
        $status = !empty($_POST['status']) && $_POST['status'] !== 'null' ? $_POST['status'] : 'active'; 
        $password = $_POST['password'] ?? '';

        if ($id === (int)$_SESSION['user_id'] && $role !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'You cannot downgrade your own Admin status here.']);
            return;
        }

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Email already in use.']);
            return;
        }

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ?, password = ? WHERE id = ? AND company_id = ?");
                if (!$stmt->execute([$name, $email, $role, $status, $hash, $id, $this->companyId])) {
                    throw new Exception("Failed to update user record.");
                }
                
                // Log the update
                $this->logAudit("Updated Team Member", "Updated profile and reset password for: $name ($email)");
                
                $this->pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Member updated with new password.']);
            } else {
                $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ? AND company_id = ?");
                if (!$stmt->execute([$name, $email, $role, $status, $id, $this->companyId])) {
                    throw new Exception("Failed to update user record.");
                }
                
                // Log the update
                $this->logAudit("Updated Team Member", "Updated profile permissions for: $name ($email)");
                
                $this->pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Member updated successfully.']);
            }
        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Failed to update member safely.']);
        }
    }

    public function deleteMember() {
        $this->requireAdmin();
        $id = (int)$_POST['id'];

        if ($id === (int)$_SESSION['user_id']) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account.']);
            return;
        }

        // Fetch user name and email before deleting
        $stmtFetch = $this->pdo->prepare("SELECT name, email FROM users WHERE id = ? AND company_id = ?");
        $stmtFetch->execute([$id, $this->companyId]);
        $user = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
            if (!$stmt->execute([$id, $this->companyId])) {
                throw new Exception("Failed to delete user record.");
            }
            
            // Log the deletion
            if ($user) {
                $this->logAudit("Deleted Team Member", "Revoked access and deleted user: {$user['name']} ({$user['email']})");
            }
            
            $this->pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Team member removed.']);
        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Failed to remove member safely.']);
        }
    }

    public function updateProfile() {
        $userId = $_SESSION['user_id'];
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $newPassword = $_POST['password'] ?? '';

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ? AND company_id = ?");
                if (!$stmt->execute([$name, $email, $hash, $userId, $this->companyId])) {
                    throw new Exception("Failed to update profile record.");
                }
            } else {
                $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND company_id = ?");
                if (!$stmt->execute([$name, $email, $userId, $this->companyId])) {
                    throw new Exception("Failed to update profile record.");
                }
            }

            // Log personal profile update
            $this->logAudit("Profile Updated", "User updated their own personal profile/credentials");

            $this->pdo->commit();

            // Only update session after confirming the database saved correctly
            $_SESSION['user_name'] = $name;
            echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully']);
        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Failed to update profile safely.']);
        }
    }

    // NEW: The Audit Log Helper Function
    private function logAudit($action, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->companyId, $this->userId, $action, $description]);
    }
}