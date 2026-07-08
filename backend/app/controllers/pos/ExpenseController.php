<?php
require_once '../app/models/pos/Expense.php';

class ExpenseController {
    private $expenseModel;
    private $companyId;
    private $pdo;       
    private $userId;    

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->expenseModel = new Expense($pdo);
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
    }

    public function index() {
        $search = $_GET['search'] ?? '';
        $start = $_GET['start'] ?? '';
        $end = $_GET['end'] ?? '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $data = $this->expenseModel->getAll($this->companyId, $search, $start, $end, $limit, $offset);
        $total = $this->expenseModel->getTotalCount($this->companyId, $search, $start, $end);
        $totalAmount = $this->expenseModel->getTotalAmount($this->companyId, $search, $start, $end);

        echo json_encode(['status' => 'success', 'data' => $data, 'total' => $total, 'total_amount' => $totalAmount, 'page' => $page, 'limit' => $limit]);
    }

    public function store() {
        $title = $_POST['title'];
        $category = $_POST['category'];
        $amount = $_POST['amount'];
        $date = $_POST['date'] . ' ' . date('H:i:s');
        $userId = $_SESSION['user_id'];
        
        $qty = $_POST['qty'] ?? 1;
        $auth_name = $_POST['auth_name'] ?? '';
        $auth_phone = $_POST['auth_phone'] ?? '';
        $auth_place = $_POST['auth_place'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $status = 'Pending'; // Forced default on creation

        if ($this->expenseModel->add($this->companyId, $title, $category, $amount, $date, $userId, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status)) {
            $this->logAudit("New Expense", "Added expense: $title (Amount: $amount Rwf)");
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save expense']);
        }
    }

    public function update() {
        $id = $_POST['id'];
        $title = $_POST['title'];
        $category = $_POST['category'];
        $amount = $_POST['amount'];
        
        $dateInput = $_POST['date'];
        $date = strlen($dateInput) <= 10 ? $dateInput . ' ' . date('H:i:s') : $dateInput;

        $qty = $_POST['qty'] ?? 1;
        $auth_name = $_POST['auth_name'] ?? '';
        $auth_phone = $_POST['auth_phone'] ?? '';
        $auth_place = $_POST['auth_place'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'Cash';
        $status = $_POST['status'] ?? 'Pending';

        if ($this->expenseModel->update($this->companyId, $id, $title, $category, $amount, $date, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status)) {
            $this->logAudit("Update Expense", "Updated expense: $title (Amount: $amount Rwf)");
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update expense']);
        }
    }

    public function destroy() {
        $userRole = $_SESSION['role'] ?? 'Cashier';
        if ($userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admins can delete records.']);
            return;
        }

        $id = $_POST['id'];
        $stmt = $this->pdo->prepare("SELECT title, amount FROM expenses WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $this->companyId]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($this->expenseModel->delete($this->companyId, $id)) {
            if ($expense) {
                $this->logAudit("Delete Expense", "Deleted expense: {$expense['title']} (Amount: {$expense['amount']} Rwf)");
            }
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    }

    private function logAudit($action, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->companyId, $this->userId, $action, $description]);
    }
}