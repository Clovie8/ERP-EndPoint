<?php
class CompanyController {
    private $pdo;
    private $companyId;
    private $userRole;
    private $userId; // NEW: Stored for Audit Logs

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
    }

    public function getSettings() {
        $stmt = $this->pdo->prepare("SELECT name, logo, phone, email, location, tin_number, receipt_message, vat_registered, stamp_signature, bank_name, bank_account FROM companies WHERE id = ?");
        $stmt->execute([$this->companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $company]);
    }

    public function updateSettings() {
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only Admins can update business settings.']);
            return;
        }

        $name = $_POST['name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        $location = $_POST['location'] ?? '';
        $tin = $_POST['tin_number'] ?? '';
        $msg = $_POST['receipt_message'] ?? '';
        $bankName = $_POST['bank_name'] ?? '';
        $bankAccount = $_POST['bank_account'] ?? '';
        
        $vat = isset($_POST['vat_registered']) ? (int)$_POST['vat_registered'] : 1;

        $stmt = $this->pdo->prepare("SELECT logo, stamp_signature FROM companies WHERE id = ?");
        $stmt->execute([$this->companyId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $logoPath = $current['logo'] ?? null;
        $stampPath = $current['stamp_signature'] ?? null;

        // Handle Image Upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
            $targetDir = "../public/uploads/logos/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES["logo"]["name"]));
            $targetFilePath = $targetDir . $fileName;
            
            if(move_uploaded_file($_FILES["logo"]["tmp_name"], $targetFilePath)) {
                if ($logoPath && file_exists("../public/" . $logoPath)) unlink("../public/" . $logoPath);
                $logoPath = "uploads/logos/" . $fileName;
            }
        }

        // Handle Stamp/Signature Upload
        if (isset($_FILES['stamp_signature']) && $_FILES['stamp_signature']['error'] === 0) {
            $targetDir = "../public/uploads/stamps/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $fileName = time() . '_stamp_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES["stamp_signature"]["name"]));
            $targetFilePath = $targetDir . $fileName;
            
            if(move_uploaded_file($_FILES["stamp_signature"]["tmp_name"], $targetFilePath)) {
                if ($stampPath && file_exists("../public/" . $stampPath)) unlink("../public/" . $stampPath);
                $stampPath = "uploads/stamps/" . $fileName;
            }
        }

        $stmt = $this->pdo->prepare("UPDATE companies SET name=?, phone=?, email=?, location=?, tin_number=?, receipt_message=?, logo=?, stamp_signature=?, bank_name=?, bank_account=?, vat_registered=? WHERE id=?");
        if ($stmt->execute([$name, $phone, $email, $location, $tin, $msg, $logoPath, $stampPath, $bankName, $bankAccount, $vat, $this->companyId])) {
            // NEW: Log the setting change
            $this->logAudit("Settings Updated", "Modified company branding and preferences for: $name");
            echo json_encode(['status' => 'success', 'message' => 'Business branding updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
    }

    // NEW FUNCTION: Fetch Tenant Billing & Subscription Information
    public function getBillingInfo() {
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
            return;
        }
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only Admins can view billing settings.']);
            return;
        }
        
        $companyId = (int)$_SESSION['company_id'];

        try {
            // 1. Fetch Subscription & Limits directly from the companies table
            $stmt = $this->pdo->prepare("
                SELECT 
                    plan_tier, 
                    status, 
                    DATE_FORMAT(subscription_ends_at, '%M %d, %Y %h:%i %p') as subscription_ends_at,
                    max_users,
                    max_branches
                FROM companies 
                WHERE id = ?
            ");
            $stmt->execute([$companyId]);
            $billingData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billingData) {
                // Safe fallback if company record is somehow missing
                $billingData = [
                    'plan_tier' => 'Basic',
                    'status' => 'active',
                    'subscription_ends_at' => 'Lifetime',
                    'max_users' => 1,
                    'max_branches' => 1
                ];
            }

            // 2. Calculate CURRENT active users from the users table
            $stmtUsers = $this->pdo->prepare("SELECT COUNT(*) as total_users FROM users WHERE company_id = ? AND status = 'active'");
            $stmtUsers->execute([$companyId]);
            $currentUsers = $stmtUsers->fetch(PDO::FETCH_ASSOC)['total_users'];

            // 3. Hardcode current branches to 1 (since there is no branches table yet)
            $currentBranches = 1;

            // Ensure limits aren't null from the DB (fallback to 1 just in case)
            $billingData['max_users'] = $billingData['max_users'] ?? 1;
            $billingData['max_branches'] = $billingData['max_branches'] ?? 1;

            // 4. Merge the current real-time usage into the billing data array so React can read it
            $billingData['current_users'] = (int)$currentUsers;
            $billingData['current_branches'] = (int)$currentBranches;

            echo json_encode(['status' => 'success', 'data' => $billingData]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error while fetching billing info.']);
        }
    }

    // NEW: The Audit Log Helper Function
    private function logAudit($action, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->companyId, $this->userId, $action, $description]);
    }
}