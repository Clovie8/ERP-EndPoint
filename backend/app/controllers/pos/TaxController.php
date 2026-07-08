<?php
class TaxController {
    private $pdo;
    private $companyId;
    private $userId;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)$_SESSION['user_id']; // Needed to track who declared it
    }

    public function getMonthlyReport() {
        $month = $_GET['month'] ?? date('Y-m'); 
        $cid = $this->companyId;

        // 1. Calculate Output & Input VAT (Same as before)
        $stmtOut = $this->pdo->prepare("SELECT COALESCE(SUM(quantity * price_at_time), 0) as total_revenue, COALESCE(SUM(quantity * price_at_time * (p.tax_rate / (100 + p.tax_rate))), 0) as total_output_vat FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.company_id = ? AND t.type = 'sale' AND DATE_FORMAT(t.date, '%Y-%m') = ?");
        $stmtOut->execute([$cid, $month]);
        $outputData = $stmtOut->fetch(PDO::FETCH_ASSOC);

        $stmtIn = $this->pdo->prepare("SELECT COALESCE(SUM(quantity * price_at_time), 0) as total_cost, COALESCE(SUM(quantity * price_at_time * (p.tax_rate / (100 + p.tax_rate))), 0) as total_input_vat FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.company_id = ? AND t.type = 'purchase' AND DATE_FORMAT(t.date, '%Y-%m') = ?");
        $stmtIn->execute([$cid, $month]);
        $inputData = $stmtIn->fetch(PDO::FETCH_ASSOC);

        $outputVat = (float)$outputData['total_output_vat'];
        $inputVat = (float)$inputData['total_input_vat'];

        // 2. NEW: Check if there was a credit carried forward from the PREVIOUS month
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $stmtPrev = $this->pdo->prepare("SELECT carried_forward FROM vat_declarations WHERE company_id = ? AND month = ?");
        $stmtPrev->execute([$cid, $prevMonth]);
        $prevData = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        $previousCredit = $prevData ? (float)$prevData['carried_forward'] : 0;

        // 3. NEW: The Updated Ledger Formula
        // RRA Payable = Output VAT - Input VAT - Previous Credit
        $netPayable = $outputVat - $inputVat - $previousCredit;

        // 4. Check if THIS month has already been officially declared
        $stmtCurrent = $this->pdo->prepare("SELECT * FROM vat_declarations WHERE company_id = ? AND month = ?");
        $stmtCurrent->execute([$cid, $month]);
        $isDeclared = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

        // 5. Fetch Details
        $stmtDetails = $this->pdo->prepare("SELECT p.name, p.sku, t.type, t.date, t.quantity, t.price_at_time, p.tax_rate, (t.quantity * t.price_at_time) as total_value, (t.quantity * t.price_at_time * (100 / (100 + p.tax_rate))) as amount_without_vat, (t.quantity * t.price_at_time * (p.tax_rate / (100 + p.tax_rate))) as tax_amount FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.company_id = ? AND DATE_FORMAT(t.date, '%Y-%m') = ? ORDER BY t.date DESC");
        $stmtDetails->execute([$cid, $month]);
        $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'month' => $month,
            'is_declared' => $isDeclared ? true : false,
            'summary' => [
                'revenue' => (float)$outputData['total_revenue'],
                'cost' => (float)$inputData['total_cost'],
                'output_vat' => $outputVat,
                'input_vat' => $inputVat,
                'previous_credit' => $previousCredit,
                'net_payable' => $netPayable
            ],
            'details' => $details
        ]);
    }

    // NEW FUNCTION: Save the declaration to the database
    public function declareMonth() {
        $data = json_decode(file_get_contents("php://input"));
        $month = $data->month ?? '';
        $outputVat = (float)($data->output_vat ?? 0);
        $inputVat = (float)($data->input_vat ?? 0);
        $prevCredit = (float)($data->previous_credit ?? 0);
        $net = (float)($data->net_payable ?? 0);

        if(empty($month)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid month.']); return;
        }

        // Logic: If net is negative, it's a credit to carry forward. If positive, they owe money.
        $owedToRRA = $net > 0 ? $net : 0;
        $carriedForward = $net < 0 ? abs($net) : 0;

        try {
            $stmt = $this->pdo->prepare("INSERT INTO vat_declarations (company_id, month, output_vat, input_vat, previous_credit, net_payable, carried_forward, declared_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$this->companyId, $month, $outputVat, $inputVat, $prevCredit, $owedToRRA, $carriedForward, $this->userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'Tax month officially declared and locked.']);
        } catch(PDOException $e) {
            // Error 23000 is Duplicate Key (meaning they already declared it)
            if ($e->getCode() == 23000) {
                echo json_encode(['status' => 'error', 'message' => 'This month has already been declared.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error.']);
            }
        }
    }

    // NEW FUNCTION: Fetch the full audit history of VAT declarations
    public function getDeclarationHistory() {
        try {
            // UPDATED: Added a LEFT JOIN to fetch the user's actual name
            $stmt = $this->pdo->prepare("
                SELECT v.*, u.name as declared_by_name 
                FROM vat_declarations v
                LEFT JOIN users u ON v.declared_by = u.id
                WHERE v.company_id = ? 
                ORDER BY v.month DESC
            ");
            $stmt->execute([$this->companyId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $history]);
        } catch(PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch history.']);
        }
    }
}