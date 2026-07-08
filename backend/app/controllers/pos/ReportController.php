<?php
require_once '../app/models/pos/Report.php';

class ReportController {
    private $reportModel;
    private $companyId;

    public function __construct($pdo) {
        $this->reportModel = new Report($pdo);
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
    }

    public function generate() {
        $type = $_GET['type'] ?? '';
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

        $data = []; $columns = [];

        try {
            switch ($type) {
                case 'valuation':
                    $data = $this->reportModel->getStockValuation($this->companyId);
                    $columns = ['Product', 'SKU', 'Qty', 'Buy Price', 'Sell Price', 'Total Cost', 'Total Value', 'Potential Profit'];
                    break;
                case 'sales':
                    $data = $this->reportModel->getTransactionSummary($this->companyId, 'sale', $start, $end, $productId);
                    $columns = ['Date', 'Product', 'Customer', 'Qty', 'Unit Price', 'Total Amount', 'Profit', 'Status', 'Method', 'User'];
                    break;
                case 'purchases':
                    $data = $this->reportModel->getTransactionSummary($this->companyId, 'purchase', $start, $end, $productId);
                    $columns = ['Date', 'Product', 'Supplier', 'Qty', 'Unit Price', 'Total Cost', 'Status', 'Method', 'User'];
                    break;
                
                // --- NEW EXPENSES REPORT ---
                case 'expenses':
                    $data = $this->reportModel->getExpensesSummary($this->companyId, $start, $end);
                    $columns = ['Date & Time', 'User', 'Expense Title', 'Category', 'Qty', 'Amount', 'Method', 'Authorized By', 'Status'];
                    break;
                // ---------------------------

                case 'ledger':
                    $data = $this->reportModel->getProductLedger($this->companyId, $productId, $start, $end);
                    $columns = ['Date', 'Type', 'Customer/Supplier', 'Qty', 'Price', 'Total Amount', 'Status', 'Method', 'User'];
                    break;
                case 'audit':
                    $data = $this->reportModel->getAuditLog($this->companyId, $start, $end);
                    $columns = ['Time', 'User', 'Action', 'Description'];
                    break;
                case 'financial':
                    $data = $this->reportModel->getFinancialStatement($this->companyId, $start, $end);
                    $columns = ['Item Description', 'Type', 'Amount (Rwf)'];
                    break;
                case 'low_stock':
                    $data = $this->reportModel->getLowStockReport($this->companyId);
                    $columns = ['Product', 'SKU', 'Current Stock', 'Action'];
                    break;
                default:
                    echo json_encode(['status' => 'error', 'message' => 'Invalid report type']);
                    return;
            }
            echo json_encode(['status' => 'success', 'data' => $data, 'columns' => $columns]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}