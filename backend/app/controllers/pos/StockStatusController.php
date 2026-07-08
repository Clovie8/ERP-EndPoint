<?php
require_once '../app/models/pos/StockStatus.php';

class StockStatusController {
    private $model;
    private $companyId;

    public function __construct($pdo) {
        $this->model = new StockStatus($pdo);
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
    }

    public function getData() {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');

        $start = ($_GET['start'] ?? date('Y-m-01')) . ' 00:00:00'; 
        $end = ($_GET['end'] ?? date('Y-m-d')) . ' 23:59:59';      
        
        // NEW: Catch the dropdown value from the frontend
        $timeframe = $_GET['timeframe'] ?? 'daily'; 

        try {
            $financials = $this->model->getFinancialSnapshots($this->companyId, $start, $end);
            
            // NEW: Pass the $timeframe to the model so it can group by day/week/month
            $chart = $this->model->getChartData($this->companyId, $start, $end, $timeframe);
            
            $table = $this->model->getProductMovement($this->companyId, $start, $end);

            echo json_encode(['status' => 'success', 'financials' => $financials, 'chart' => $chart, 'table' => $table]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}