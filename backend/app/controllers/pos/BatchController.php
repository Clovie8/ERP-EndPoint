<?php
require_once __DIR__ . '/../../models/pos/Batch.php';

class BatchController {
    private $model;
    private $pdo; 
    private $companyId;
    private $userId;
    private $userRole;

    public function __construct($pdo) {
        $this->model = new Batch($pdo);
        $this->pdo = $pdo; 
        
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
    }

    public function index() {
        $status = $_GET['status'] ?? 'all';
        $data = $this->model->getAllBatches($this->companyId, $status);
        echo json_encode(['status' => 'success', 'data' => $data]);
    }

    public function update() {
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Only Admins can adjust batch quantities.']);
            return;
        }

        $id = (int)$_POST['id'];
        $newQty = (int)$_POST['quantity'];

        if ($newQty < 0) {
            echo json_encode(['status' => 'error', 'message' => 'Quantity cannot be negative.']);
            return;
        }

        if ($this->model->adjustQuantity($this->companyId, $id, $newQty, $this->userId)) {
            echo json_encode(['status' => 'success', 'message' => 'Batch updated and main stock synchronized.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to adjust batch.']);
        }
    }

    public function getHistory() {
        $batchId = (int)$_GET['batch_id'];
        
        // 1. Security Check: Ensure this batch actually belongs to this company
        $stmtBatch = $this->pdo->prepare("SELECT id FROM product_batches WHERE id = ? AND company_id = ?");
        $stmtBatch->execute([$batchId, $this->companyId]);
        if (!$stmtBatch->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized or Batch not found']);
            return;
        }

        // 2. Fetch the ledger history
        $sql = "SELECT tbh.quantity_deducted, t.id as transaction_id, t.type, 
               i.client_name, -- <-- NEW: Pulling from invoices table
               t.date as created_at 
        FROM transaction_batch_history tbh 
        JOIN transactions t ON tbh.transaction_id = t.id 
        LEFT JOIN invoices i ON t.invoice_id = i.id -- <-- NEW: Joining invoices table
        WHERE tbh.batch_id = ? 
        ORDER BY t.date DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$batchId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $history]);
    }
}