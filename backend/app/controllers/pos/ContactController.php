<?php
class ContactController {
    private $pdo;
    private $companyId;
    private $userId;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Ensure the user is logged in
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)$_SESSION['user_id'];
    }

    // ==========================================
    // GET ALL CONTACTS (For React-Select Dropdown & CRM Table)
    // ==========================================
    public function getContacts() {
        $type = $_GET['type'] ?? ''; 
        
        $sql = "SELECT id, contact_code, name, phone, email, tin_number, type
                FROM contacts 
                WHERE company_id = ?";
        $params = [$this->companyId];

        // Optional filter if you only want to load Suppliers on the Purchases page
        if (!empty($type) && in_array($type, ['Customer', 'Supplier', 'Both'])) {
            $sql .= " AND (type = ? OR type = 'Both')";
            $params[] = $type;
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $contacts]);
    }

    // ==========================================
    // CREATE NEW CONTACT (Auto-generates COS001 / SUP001)
    // ==========================================
    public function createContact() {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tin_number = trim($_POST['tin_number'] ?? '');
        $type = $_POST['type'] ?? 'Customer';

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Contact name is required.']);
            return;
        }

        if (!in_array($type, ['Customer', 'Supplier', 'Both'])) {
            $type = 'Customer';
        }

        // 1. Determine the Human-Readable Prefix
        $prefix = 'COS';
        if ($type === 'Supplier') $prefix = 'SUP';
        if ($type === 'Both') $prefix = 'BTH';

        // 2. Count existing contacts of this type to determine the next number
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM contacts WHERE company_id = ? AND type = ?");
        $stmt->execute([$this->companyId, $type]);
        $count = (int)$stmt->fetchColumn();
        
        // 3. Format the new code (e.g., 1 becomes 001)
        $nextNumber = $count + 1;
        $contactCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        // 4. Insert into the database
        $insertSql = "INSERT INTO contacts (company_id, type, contact_code, name, phone, email, tin_number, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmtInsert = $this->pdo->prepare($insertSql);
        
        if ($stmtInsert->execute([$this->companyId, $type, $contactCode, $name, $phone, $email, $tin_number])) {
            $newId = $this->pdo->lastInsertId();
            
            // Return the newly created record instantly so React can select it in the dropdown!
            echo json_encode([
                'status' => 'success', 
                'message' => 'Contact saved successfully!',
                'data' => [
                    'id' => $newId,
                    'contact_code' => $contactCode,
                    'name' => $name,
                    'phone' => $phone,
                    'type' => $type
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create contact.']);
        }
    }


    // ==========================================
    // UPDATE CONTACT
    // ==========================================
    public function updateContact() {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tin_number = $_POST['tin_number'] ?? '';

        if (empty($name) || $id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Valid ID and Contact Name are required.']);
            return;
        }

        // Note: We intentionally do NOT update the 'contact_code' (e.g., COS001) or 'type' 
        // to maintain database stability and prevent UI confusion.
        $sql = "UPDATE contacts 
                SET name = ?, phone = ?, email = ?, tin_number = ? 
                WHERE id = ? AND company_id = ?";
                
        $stmt = $this->pdo->prepare($sql);

        if ($stmt->execute([$name, $phone, $email, $tin_number, $id, $this->companyId])) {
            echo json_encode(['status' => 'success', 'message' => 'Contact details updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update contact.']);
        }
    }

    // ==========================================
    // DELETE CONTACT (With Strict Safety Guardrails)
    // ==========================================
    public function deleteContact() {
        $id = (int)$_POST['id'];

        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Contact ID.']);
            return;
        }

        // --- NEW: THE PROFORMA SAFETY CHECK ---
        $checkProformaStmt = $this->pdo->prepare("SELECT COUNT(*) FROM proformas WHERE contact_id = ? AND company_id = ?");
        $checkProformaStmt->execute([$id, $this->companyId]);
        $hasProformas = (int)$checkProformaStmt->fetchColumn() > 0;

        if ($hasProformas) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Action Blocked: This contact is linked to an existing Proforma Invoice.'
            ]);
            return;
        }
        // --------------------------------------

        // 1. THE INVOICE SAFETY CHECK: Has this person ever bought or sold anything?
        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM invoices WHERE contact_id = ? AND company_id = ?");
        $checkStmt->execute([$id, $this->companyId]);
        $hasHistory = (int)$checkStmt->fetchColumn() > 0;

        // 2. THE BLOCK: If they have history, reject the deletion.
        if ($hasHistory) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Action Blocked: This contact is linked to existing invoices or debts. Please edit their name instead.'
            ]);
            return;
        }

        // 3. THE EXECUTION: If their history is clean (e.g., a cashier created them by mistake), delete them.
        $stmt = $this->pdo->prepare("DELETE FROM contacts WHERE id = ? AND company_id = ?");
        
        if ($stmt->execute([$id, $this->companyId])) {
            echo json_encode(['status' => 'success', 'message' => 'Contact permanently deleted.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete contact.']);
        }
    }
}