<?php
class Dashboard {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getKPIs($companyId) {
        $today = date('Y-m-d');
        
        $stmtProd = $this->pdo->prepare("SELECT COUNT(*) FROM products WHERE company_id = ?");
        $stmtProd->execute([$companyId]);
        $totalProducts = $stmtProd->fetchColumn();

        $stmtVal = $this->pdo->prepare("SELECT SUM(buy_price * stock_quantity) FROM products WHERE company_id = ?");
        $stmtVal->execute([$companyId]);
        $stockValue = $stmtVal->fetchColumn();

        $stmtSales = $this->pdo->prepare("SELECT COALESCE(SUM(quantity * price_at_time), 0) FROM transactions WHERE type = 'sale' AND DATE(date) = ? AND company_id = ?");
        $stmtSales->execute([$today, $companyId]);
        $todaySales = $stmtSales->fetchColumn();

        $stmtPurch = $this->pdo->prepare("SELECT COALESCE(SUM(quantity * price_at_time), 0) FROM transactions WHERE type = 'purchase' AND DATE(date) = ? AND company_id = ?");
        $stmtPurch->execute([$today, $companyId]);
        $todayPurch = $stmtPurch->fetchColumn();

        $stmtProfit = $this->pdo->prepare("SELECT COALESCE(SUM((t.price_at_time - p.buy_price) * t.quantity), 0) 
                      FROM transactions t 
                      JOIN products p ON t.product_id = p.id 
                      WHERE t.type = 'sale' AND DATE(t.date) = ? AND t.company_id = ?");
        $stmtProfit->execute([$today, $companyId]);
        $todayProfit = $stmtProfit->fetchColumn();

        return [
            'total_products' => $totalProducts ?: 0,
            'stock_value' => $stockValue ?: 0,
            'today_sales' => $todaySales,
            'today_purchases' => $todayPurch,
            'today_profit' => $todayProfit
        ];
    }

    public function getLowStock($companyId, $limit = 5) {
        // SECURED: Added company_id to WHERE clause
        $sql = "SELECT id, name, sku, stock_quantity, image 
                FROM products 
                WHERE company_id = :cid AND stock_quantity < 10 
                ORDER BY stock_quantity ASC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cid', (int)$companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentTransactions($companyId, $type, $limit = 5) {
        // SECURED: Added company_id to WHERE clause
        $sql = "SELECT t.*, p.name as product_name, p.image 
                FROM transactions t 
                JOIN products p ON t.product_id = p.id 
                WHERE t.company_id = :cid AND t.type = :type 
                ORDER BY t.date DESC LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cid', (int)$companyId, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getChartData($companyId) {
        // SECURED: Added company_id to WHERE clause
        $sql = "SELECT DATE(date) as date, type, SUM(quantity) as qty 
                FROM transactions 
                WHERE company_id = ? AND date >= DATE(NOW()) - INTERVAL 30 DAY 
                GROUP BY DATE(date), type 
                ORDER BY date ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}