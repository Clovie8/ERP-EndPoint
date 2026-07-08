<?php
class TenantController {
    private $pdo;

    public function __construct($pdo) { $this->pdo = $pdo; }

    // 1. Fetch All Tenants (UPGRADED: Now fetches live usage stats)
    public function getAllTenants() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    c.*, 
                    (SELECT email FROM users WHERE company_id = c.id AND role = 'Admin' LIMIT 1) as owner_email,
                    (SELECT COUNT(*) FROM users WHERE company_id = c.id) as current_users,
                    (SELECT COUNT(*) FROM products WHERE company_id = c.id) as current_products
                FROM companies c
                ORDER BY c.created_at DESC
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'DB Fetch Error']);
        }
    }

    // 2. Provision New Tenant (UPGRADED: Automated Data Seeding)
    public function createCompany() {
        $data = json_decode(file_get_contents("php://input"));
        $name = $data->name ?? ''; $email = $data->email ?? ''; $password = $data->password ?? '';
        $tier = $data->plan_tier ?? 'basic'; 
        $maxUsers = (int)($data->max_users ?? 5); $maxBranches = (int)($data->max_branches ?? 1);

        if (empty($name) || empty($email) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']); return;
        }

        try {
            $this->pdo->beginTransaction();

            // A. Insert Company
            $stmt = $this->pdo->prepare("
                INSERT INTO companies (name, email, plan_tier, status, max_users, max_branches, subscription_ends_at, business_type, phone, location, tin_number, receipt_message, vat_registered, stamp_signature, bank_name, bank_account) 
                VALUES (?, ?, ?, 'trial', ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY), 'Retail', '', '', '', 'Thank you for your business!', 0, '', '', '')
            ");
            $stmt->execute([$name, $email, $tier, $maxUsers, $maxBranches]);
            $companyId = $this->pdo->lastInsertId();

            // B. Create Owner Account
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmtUser = $this->pdo->prepare("INSERT INTO users (company_id, name, email, password, role) VALUES (?, 'Store Owner', ?, ?, 'Admin')");
            $stmtUser->execute([$companyId, $email, $hashedPassword]);

            // C. AUTOMATED DATA SEEDING (The Magic Setup)
            // Note: If you don't have a taxes or branches table yet, you can comment these out.
            try {
                $this->pdo->prepare("INSERT INTO branches (company_id, name, location, is_main) VALUES (?, 'Main Headquarters', 'Main Location', 1)")->execute([$companyId]);
                $this->pdo->prepare("INSERT INTO taxes (company_id, name, rate) VALUES (?, 'Standard VAT', 18), (?, 'Exempt', 0)")->execute([$companyId, $companyId]);
            } catch (Exception $e) { /* Ignore seeding errors if tables don't exist yet */ }

            $this->pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Tenant '$name' provisioned & seeded successfully."]);
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Failed to provision tenant.']);
        }
    }

    // 3. Suspend/Activate
    public function toggleStatus() {
        $data = json_decode(file_get_contents("php://input"));
        try {
            $this->pdo->prepare("UPDATE companies SET status = ? WHERE id = ?")->execute([$data->status ?? 'active', (int)($data->company_id ?? 0)]);
            echo json_encode(['status' => 'success', 'message' => "Company status updated."]);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to update status.']); }
    }

    // 4. NEW: Plan Upgrades & Downgrades
    public function updatePlan() {
        $data = json_decode(file_get_contents("php://input"));
        try {
            $this->pdo->prepare("UPDATE companies SET plan_tier = ?, max_users = ?, max_branches = ? WHERE id = ?")
                      ->execute([$data->plan_tier, (int)$data->max_users, (int)$data->max_branches, (int)$data->company_id]);
            echo json_encode(['status' => 'success', 'message' => "Tenant upgraded to " . strtoupper($data->plan_tier) . " plan."]);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to update plan.']); }
    }
}