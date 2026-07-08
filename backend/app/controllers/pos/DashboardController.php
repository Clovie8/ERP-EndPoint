<?php
class DashboardController {
    private $pdo;
    private $companyId;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: No company session found.']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
    }

    public function getData() {
        $today = date('Y-m-d');
        $cid = $this->companyId;
        
        // Catch timeframe from frontend (Default to monthly for a clean overview)
        $timeframe = $_GET['timeframe'] ?? 'monthly'; 
        
        // 1. KPI Cards
        $totalProducts = $this->pdo->query("SELECT COUNT(*) FROM products WHERE company_id = $cid AND status = 'Active'")->fetchColumn();
        
        // ---> FIXED: TRUE STOCK VALUATION (FIFO Batches + Serials, IGNORING ARCHIVED) <---
        $serialValue = $this->pdo->query("
            SELECT COALESCE(SUM(s.buy_price), 0) 
            FROM product_serials s 
            JOIN products p ON s.product_id = p.id 
            WHERE s.company_id = $cid AND s.status = 'In Stock' AND p.status = 'Active'
        ")->fetchColumn();
        
        $batchValue = $this->pdo->query("
            SELECT COALESCE(SUM(b.quantity_remaining * b.buy_price), 0) 
            FROM product_batches b 
            JOIN products p ON b.product_id = p.id 
            WHERE b.company_id = $cid AND p.status = 'Active'
        ")->fetchColumn();
        
        $stockValue = $serialValue + $batchValue;
        // ---------------------------------------------------------------
        
        $todaySales = $this->pdo->query("SELECT COALESCE(SUM(quantity * price_at_time), 0) FROM transactions WHERE type='sale' AND DATE(date) = '$today' AND company_id = $cid")->fetchColumn();
        $todayPurch = $this->pdo->query("SELECT COALESCE(SUM(quantity * price_at_time), 0) FROM transactions WHERE type='purchase' AND DATE(date) = '$today' AND company_id = $cid")->fetchColumn();
        
        // Profit Today
        $todayProfit = $this->pdo->query("
            SELECT COALESCE(SUM((t.price_at_time * t.quantity) - t.cogs), 0)
            FROM transactions t 
            WHERE t.type='sale' AND DATE(t.date) = '$today' AND t.company_id = $cid
        ")->fetchColumn();

        // 2. Tables Data
        $lowStock = $this->pdo->query("SELECT id, name, image, stock_quantity FROM products WHERE stock_quantity <= 10 AND company_id = $cid AND status = 'Active' AND item_type = 'product' ORDER BY stock_quantity ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $requested = $this->pdo->query("SELECT id, name, image, stock_quantity FROM products WHERE is_requested = 1 AND company_id = $cid AND status = 'Active' ORDER BY name ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $sales = $this->pdo->query("SELECT t.date, p.name as product_name, p.image as product_image, t.quantity, t.price_at_time FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.type='sale' AND t.company_id = $cid ORDER BY t.date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $purchases = $this->pdo->query("SELECT t.date, p.name as product_name, p.image as product_image, t.quantity, t.price_at_time FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.type='purchase' AND t.company_id = $cid ORDER BY t.date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

        // 3. Top Performers Leaderboard
        $topSellers = $this->pdo->query("
            SELECT p.name, p.image, SUM(t.quantity) as total_sold, SUM(t.quantity * t.price_at_time) as total_revenue
            FROM transactions t
            JOIN products p ON t.product_id = p.id
            WHERE t.type = 'sale' AND t.company_id = $cid
            GROUP BY p.id
            ORDER BY total_sold DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 4. ADVANCED CHART DATA (FIXED: Dynamic Time-Series Grouping)
        $dateSelect = "DATE(t.date)";
        $groupBy = "DATE(t.date)";
        
        if ($timeframe === 'monthly') {
            $dateSelect = "DATE_FORMAT(t.date, '%Y-%m')"; // e.g. 2026-01
            $groupBy = "DATE_FORMAT(t.date, '%Y-%m')";
        } elseif ($timeframe === 'weekly') {
            $dateSelect = "CONCAT(YEAR(t.date), '-W', LPAD(WEEK(t.date, 1), 2, '0'))"; // e.g. 2026-W05
            $groupBy = "YEARWEEK(t.date, 1)";
        }

        // Removed the "30 DAY" limit so you can see all your data from Jan to April
        $chartData = $this->pdo->query("
            SELECT $dateSelect as date, 
                   SUM(t.quantity * t.price_at_time) as daily_revenue,
                   SUM(t.quantity * (t.price_at_time - p.buy_price)) as daily_profit
            FROM transactions t
            JOIN products p ON t.product_id = p.id
            WHERE t.type = 'sale' AND t.company_id = $cid
            GROUP BY $groupBy
            ORDER BY MIN(t.date) ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'kpi' => [
                'total_products' => $totalProducts,
                'stock_value' => $stockValue,
                'today_sales' => $todaySales,
                'today_purchases' => $todayPurch,
                'today_profit' => $todayProfit
            ],
            'low_stock' => $lowStock,
            'requested' => $requested,      
            'recent_sales' => $sales,
            'recent_purchases' => $purchases,
            'top_sellers' => $topSellers,   
            'chart' => $chartData           
        ]);
    }
}