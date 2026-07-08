<?php
class ShiftController {
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
        $this->userId = (int)$_SESSION['user_id'];
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
    }

    // ==========================================
    // ADMIN: GET ALL SHIFT HISTORY
    // ==========================================
    public function index() {
        if ($this->userRole !== 'Admin' && $this->userRole !== 'Manager') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT s.*, u.name as cashier_name 
            FROM shifts s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.company_id = ? 
            ORDER BY s.start_time DESC
        ");
        $stmt->execute([$this->companyId]);
        $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate Global Summaries
        $totalExpected = 0;
        $totalActual = 0;
        $totalShortage = 0;

        foreach ($shifts as $s) {
            if ($s['status'] === 'closed') {
                $totalExpected += (float)$s['expected_cash'];
                $totalActual += (float)$s['actual_cash'];
                $variance = (float)$s['actual_cash'] - (float)$s['expected_cash'];
                if ($variance < 0) {
                    $totalShortage += abs($variance);
                }
            }
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $shifts,
            'summary' => [
                'expected' => $totalExpected,
                'actual' => $totalActual,
                'shortage' => $totalShortage
            ]
        ]);
    }

    // ==========================================
    // CASHIER: CURRENT SHIFT LOGIC
    // ==========================================
    public function current() {
        $stmt = $this->pdo->prepare("SELECT * FROM shifts WHERE user_id = ? AND company_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$this->userId, $this->companyId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $shift ?: null]);
    }

    public function start() {
        $cash = (float)($_POST['starting_cash'] ?? 0);
        $stmt = $this->pdo->prepare("INSERT INTO shifts (company_id, user_id, start_time, starting_cash, status) VALUES (?, ?, NOW(), ?, 'open')");
        if ($stmt->execute([$this->companyId, $this->userId, $cash])) {
            echo json_encode(['status' => 'success', 'message' => 'Register opened successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to open register.']);
        }
    }

    public function end() {
        $shiftId = (int)$_POST['shift_id'];
        $actualCash = (float)$_POST['actual_cash'];

        // 1. REFINED: Fetch the start_time and user_id to know exactly when to start counting sales!
        $stmt = $this->pdo->prepare("SELECT starting_cash, start_time, user_id FROM shifts WHERE id = ? AND company_id = ?");
        $stmt->execute([$shiftId, $this->companyId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            echo json_encode(['status' => 'error', 'message' => 'Shift not found.']);
            return;
        }

        $starting = (float)$shift['starting_cash'];

        // 2. REFINED: Look at the new INVOICES table! Sum all actual money paid to this cashier since their shift began.
        $stmtSales = $this->pdo->prepare("
            SELECT COALESCE(SUM(amount_paid), 0) 
            FROM invoices 
            WHERE user_id = ? 
              AND company_id = ? 
              AND type = 'sale' 
              AND created_at >= ?
        ");
        $stmtSales->execute([$shift['user_id'], $this->companyId, $shift['start_time']]);
        $totalCollected = (float)$stmtSales->fetchColumn();

        // 3. Calculate Expectations
        $expected = $starting + $totalCollected;
        
        $stmtUpdate = $this->pdo->prepare("UPDATE shifts SET end_time = NOW(), expected_cash = ?, actual_cash = ?, status = 'closed' WHERE id = ? AND company_id = ?");
        
        if ($stmtUpdate->execute([$expected, $actualCash, $shiftId, $this->companyId])) {
            $variance = $actualCash - $expected;
            $msg = $variance < 0 ? "Shift closed. Shortage of Rwf " . number_format(abs($variance)) : "Shift closed successfully!";
            echo json_encode(['status' => 'success', 'message' => $msg]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to close register.']);
        }
    }
}