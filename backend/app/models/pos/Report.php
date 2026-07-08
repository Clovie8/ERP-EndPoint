<?php
class Report {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getStockValuation($companyId) {
        $sql = "SELECT 
                    p.name, 
                    p.sku, 
                    p.stock_quantity, 
                    p.buy_price, 
                    p.sell_price,
                    
                    -- TRUE TOTAL COST (Calculated dynamically from Serials + Batches)
                    (
                        COALESCE((SELECT SUM(buy_price) FROM product_serials WHERE product_id = p.id AND status = 'In Stock' AND company_id = p.company_id), 0) + 
                        COALESCE((SELECT SUM(quantity_remaining * buy_price) FROM product_batches WHERE product_id = p.id AND company_id = p.company_id), 0)
                    ) AS total_cost_value,
                    
                    (p.stock_quantity * p.sell_price) AS total_sales_value,
                    
                    -- POTENTIAL PROFIT (Total Sales Value minus True Total Cost)
                    (p.stock_quantity * p.sell_price) - 
                    (
                        COALESCE((SELECT SUM(buy_price) FROM product_serials WHERE product_id = p.id AND status = 'In Stock' AND company_id = p.company_id), 0) + 
                        COALESCE((SELECT SUM(quantity_remaining * buy_price) FROM product_batches WHERE product_id = p.id AND company_id = p.company_id), 0)
                    ) AS potential_profit
                    
                FROM products p 
                -- NEW: Only show Active products that actually have stock
                WHERE p.company_id = ? AND p.stock_quantity > 0 AND p.status = 'Active'
                ORDER BY p.name ASC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLowStockReport($companyId) {
        $sql = "SELECT id, name, sku, stock_quantity, is_requested 
                FROM products 
                WHERE company_id = ? AND stock_quantity <= 10 AND status = 'Active' AND item_type = 'product'
                ORDER BY stock_quantity ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransactionSummary($companyId, $type, $startDate, $endDate, $productId = 0) {
        $params = [$companyId, $type, $startDate, $endDate];
        $productClause = "";
        if ($productId > 0) {
            $productClause = " AND t.product_id = ? ";
            $params[] = $productId;
        }

        $profitColumn = "";
        if ($type === 'sale') {
            // FIXED: Using true COGS for perfect profit calculation
            $profitColumn = ", ((t.price_at_time * t.quantity) - t.cogs) as profit";
        }

        $sql = "SELECT i.created_at as transaction_date, 
               p.name as product_name, 
               i.client_name,
               t.quantity, 
               t.price_at_time,
               (t.quantity * t.price_at_time) as total_amount $profitColumn,
               i.payment_status,
               i.payment_method_used,
               COALESCE(u.name, 'System') as user_name
        FROM transactions t
        JOIN invoices i ON t.invoice_id = i.id
        JOIN products p ON t.product_id = p.id
        LEFT JOIN users u ON i.user_id = u.id
        WHERE t.company_id = ? AND t.type = ? AND DATE(i.created_at) BETWEEN ? AND ? $productClause
        ORDER BY i.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- NEW: EXPENSES SUMMARY REPORT ---
    public function getExpensesSummary($companyId, $startDate, $endDate) {
        $sql = "SELECT 
                    e.date,
                    COALESCE(u.name, 'System') as user_name,
                    e.title,
                    e.category,
                    e.qty,
                    e.amount,
                    e.payment_method,
                    CONCAT_WS(' | ', NULLIF(e.auth_name, ''), NULLIF(e.auth_phone, ''), NULLIF(e.auth_place, '')) as authorized_by,
                    e.status
                FROM expenses e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.company_id = ? AND DATE(e.date) BETWEEN ? AND ?
                ORDER BY e.date DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // ------------------------------------

    public function getProductLedger($companyId, $productId, $startDate, $endDate) {
        if(!$productId) return [];
        $sql = "SELECT i.created_at as date, 
               t.type, 
               i.client_name, 
               t.quantity, 
               t.price_at_time, 
               (t.quantity * t.price_at_time) as total_amount, 
               i.payment_status, 
               i.payment_method_used, 
               COALESCE(u.name, 'System') as user_name
        FROM transactions t
        JOIN invoices i ON t.invoice_id = i.id
        LEFT JOIN users u ON i.user_id = u.id
        JOIN products p ON t.product_id = p.id
        WHERE t.company_id = ? AND t.product_id = ? AND DATE(i.created_at) BETWEEN ? AND ?
        ORDER BY i.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId, $productId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAuditLog($companyId, $startDate, $endDate) {
        $sql = "SELECT a.created_at, u.name as user, a.action, a.description 
                FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id
                WHERE a.company_id = ? AND DATE(a.created_at) BETWEEN ? AND ? ORDER BY a.created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFinancialStatement($companyId, $startDate, $endDate) {
        // FIXED: Using true COGS for the Income & Expense Report. 
        // (JOIN products removed to improve loading speed since t.cogs handles everything)
        $sqlSales = "SELECT 
                        COALESCE(SUM(t.quantity * t.price_at_time), 0) as total_revenue,
                        COALESCE(SUM((t.quantity * t.price_at_time) - t.cogs), 0) as gross_profit
                     FROM transactions t 
                     WHERE t.company_id = ? AND t.type = 'sale' AND DATE(t.date) BETWEEN ? AND ?";
        $stmt = $this->pdo->prepare($sqlSales);
        $stmt->execute([$companyId, $startDate, $endDate]);
        $salesData = $stmt->fetch(PDO::FETCH_ASSOC);

        $sqlExp = "SELECT COALESCE(SUM(amount), 0) as total_expenses FROM expenses WHERE company_id = ? AND DATE(date) BETWEEN ? AND ?";
        $stmtExp = $this->pdo->prepare($sqlExp);
        $stmtExp->execute([$companyId, $startDate, $endDate]);
        $totalExpenses = $stmtExp->fetchColumn();

        $data = [
            ['item' => 'Total Sales Revenue', 'type' => 'Income', 'amount' => $salesData['total_revenue']],
            ['item' => 'Cost of Goods Sold', 'type' => 'Cost', 'amount' => $salesData['total_revenue'] - $salesData['gross_profit']],
            ['item' => 'GROSS PROFIT', 'type' => 'PROFIT', 'amount' => $salesData['gross_profit']],
            ['item' => 'Total Operational Expenses', 'type' => 'Expense', 'amount' => $totalExpenses],
            ['item' => 'NET PROFIT (Real)', 'type' => 'NET', 'amount' => $salesData['gross_profit'] - $totalExpenses]
        ];

        return $data;
    }
}