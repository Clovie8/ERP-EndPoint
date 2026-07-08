<?php
class ProformaController {
    private $pdo;
    private $companyId;
    private $userId;
    private $userRole;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
    }

    // 1. FETCH ALL PROFORMAS FOR THE DATA TABLE
    public function index() {
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT p.*, u.name as creator_name 
                FROM proformas p 
                LEFT JOIN users u ON p.created_by = u.id 
                WHERE p.company_id = ? AND (p.proforma_number LIKE ? OR p.client_name LIKE ?) 
                ORDER BY p.id DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->companyId, "%$search%", "%$search%"]);
        $proformas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $proformas]);
    }

    // 2. FETCH A SINGLE PROFORMA (WITH ITS ITEMS) FOR PRINTING/EDITING
    // 2. FETCH A SINGLE PROFORMA (WITH ITS ITEMS) FOR PRINTING/EDITING
    public function show() {
        $id = (int)$_GET['id'];

        // --- REFINED: Added LEFT JOIN to pull real-time CRM data ---
        $sql = "SELECT p.*, 
                       c.tin_number as crm_tin, 
                       c.email as crm_email,
                       c.phone as crm_phone
                FROM proformas p 
                LEFT JOIN contacts c ON p.contact_id = c.id 
                WHERE p.id = ? AND p.company_id = ?";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id, $this->companyId]);
        $proforma = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proforma) {
            echo json_encode(['status' => 'error', 'message' => 'Proforma not found']);
            return;
        }

        // --- NEW: Smart Data Merge ---
        // If a CRM contact is linked, override the static fields with the freshest CRM data!
        if (!empty($proforma['contact_id'])) {
            $proforma['client_tin']   = $proforma['crm_tin'] ?: $proforma['client_tin'];
            $proforma['client_email'] = $proforma['crm_email'] ?: $proforma['client_email'];
            $proforma['client_phone'] = $proforma['crm_phone'] ?: $proforma['client_phone'];
        }

        // Clean up the extra variables so the JSON response stays perfectly clean
        unset($proforma['crm_tin'], $proforma['crm_email'], $proforma['crm_phone']);
        // -------------------------------------------------------------

        // Get Children (Items)
        $stmtItems = $this->pdo->prepare("SELECT * FROM proforma_items WHERE proforma_id = ?");
        $stmtItems->execute([$id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $proforma['items'] = $items;
        echo json_encode(['status' => 'success', 'data' => $proforma]);
    }

    // 3. CREATE A NEW PROFORMA
    public function store() {
        $payload = file_get_contents("php://input");
        $data = json_decode($payload, true);

        if (!$data || empty($data['items'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data or empty invoice']);
            return;
        }

        $proformaNumber = 'PI-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        $date = date('Y-m-d H:i:s');

        try {
            $this->pdo->beginTransaction();

            $contactId = !empty($data['contact_id']) ? (int)$data['contact_id'] : null;

            $stmt = $this->pdo->prepare("INSERT INTO proformas (company_id, contact_id, proforma_number, client_name, client_tin, client_phone, client_email, date, subtotal, tax_rate, tax_amount, total_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $this->companyId, 
                $contactId, 
                $proformaNumber, 
                $data['client_name'], 
                $data['client_tin'] ?? null, 
                $data['client_phone'] ?? null, 
                $data['client_email'] ?? null, 
                $date, 
                $data['subtotal'], 
                $data['tax_rate'], 
                $data['tax_amount'], 
                $data['total_amount'], 
                $data['status'] ?? 'Draft', 
                $this->userId
            ]);
            
            $proformaId = $this->pdo->lastInsertId();

            // Insert Children
            // Insert Children
            $stmtItem = $this->pdo->prepare("INSERT INTO proforma_items (proforma_id, product_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($data['items'] as $item) {
                $productId = (isset($item['product_id']) && is_numeric($item['product_id'])) ? $item['product_id'] : null;

                $stmtItem->execute([
                    $proformaId, 
                    $productId, // Pass the cleaned variable here!
                    $item['description'], 
                    $item['quantity'], 
                    $item['unit_price'], 
                    $item['total_price']
                ]);
            }

            $this->pdo->commit();
            $this->logAudit("Create Proforma", "Generated Proforma Invoice: $proformaNumber for {$data['client_name']}");
            
            echo json_encode(['status' => 'success', 'message' => 'Proforma saved successfully', 'id' => $proformaId]);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'System error during save']);

        } 
    }

    // 3.5 UPDATE EXISTING PROFORMA
    public function update() {
        $payload = file_get_contents("php://input");
        $data = json_decode($payload, true);

        if (!$data || empty($data['items']) || empty($data['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data or empty invoice']);
            return;
        }

        $id = (int)$data['id'];

        try {
            $this->pdo->beginTransaction();

            $contactId = !empty($data['contact_id']) ? (int)$data['contact_id'] : null;

            // Update Parent
            $stmt = $this->pdo->prepare("UPDATE proformas SET contact_id=?, client_name=?, client_tin=?, client_phone=?, client_email=?, subtotal=?, tax_rate=?, tax_amount=?, total_amount=?, status=? WHERE id=? AND company_id=?");
            $stmt->execute([
                $contactId,
                $data['client_name'], 
                $data['client_tin'] ?? null, 
                $data['client_phone'] ?? null, 
                $data['client_email'] ?? null, 
                $data['subtotal'], 
                $data['tax_rate'], 
                $data['tax_amount'], 
                $data['total_amount'], 
                $data['status'] ?? 'Draft', 
                $id, 
                $this->companyId
            ]);
            
            // Delete old items completely
            $this->pdo->prepare("DELETE FROM proforma_items WHERE proforma_id = ?")->execute([$id]);

            // Insert new/edited items
            $stmtItem = $this->pdo->prepare("INSERT INTO proforma_items (proforma_id, product_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($data['items'] as $item) {
                $rawId = $item['product_id'] ?? null;
                $productId = (is_numeric($rawId) && $rawId < 2147483600) ? (int)$rawId : null;

                $stmtItem->execute([
                    $id, 
                    $productId, // Pass the cleaned variable
                    $item['description'], 
                    $item['quantity'], $item['unit_price'], $item['total_price']
                ]);
            }

            $this->pdo->commit();
            $this->logAudit("Update Proforma", "Updated Proforma Invoice ID: $id for {$data['client_name']}");
            
            echo json_encode(['status' => 'success', 'message' => 'Proforma updated successfully']);

        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'System error during update']);
        }
    }

    // 4. DELETE A PROFORMA (PROTECTED)
    public function destroy() {
        // --- ENTERPRISE SECURITY CHECK ---
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admins can delete Proforma Invoices.']);
            return;
        }
        // ------------------------------------

        $id = (int)($_POST['id'] ?? json_decode(file_get_contents("php://input"), true)['id']);
        
        $stmt = $this->pdo->prepare("SELECT proforma_number, client_name FROM proformas WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $this->companyId]);
        $pi = $stmt->fetch(PDO::FETCH_ASSOC);

        if($pi) {
            // --- START VAULT ---
            $this->pdo->beginTransaction();

            try {
                // Deleting the parent automatically deletes the children because of 'ON DELETE CASCADE' in MySQL
                $delStmt = $this->pdo->prepare("DELETE FROM proformas WHERE id = ? AND company_id = ?");
                
                if (!$delStmt->execute([$id, $this->companyId])) {
                    throw new Exception("Failed to execute deletion.");
                }
                
                $this->logAudit("Delete Proforma", "Deleted Proforma {$pi['proforma_number']} for {$pi['client_name']}");
                
                // --- COMMIT: Save deletion and audit log permanently ---
                $this->pdo->commit();
                
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                // --- ROLLBACK: Abort if either the delete or the audit log fails ---
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => 'System error: Deletion safely aborted.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Proforma not found']);
        }
    }

    private function logAudit($action, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->companyId, $this->userId, $action, $description]);
    }
}