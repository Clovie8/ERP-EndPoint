<?php
class StockStatus {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getFinancialSnapshots($companyId, $startDate, $endDate) {
        $sDate = $startDate;
        $eDate = $endDate;

        // 1. TRUE CURRENT CAPITAL 
        $stmtSerial = $this->pdo->prepare("SELECT COALESCE(SUM(s.buy_price), 0) FROM product_serials s JOIN products p ON s.product_id = p.id WHERE s.company_id = ? AND s.status = 'In Stock' AND p.status = 'Active'");
        $stmtSerial->execute([$companyId]);
        $serialValue = (float) $stmtSerial->fetchColumn();

        $stmtBatch = $this->pdo->prepare("SELECT COALESCE(SUM(b.quantity_remaining * b.buy_price), 0) FROM product_batches b JOIN products p ON b.product_id = p.id WHERE b.company_id = ? AND p.status = 'Active'");
        $stmtBatch->execute([$companyId]);
        $batchValue = (float) $stmtBatch->fetchColumn();

        $currCap = $serialValue + $batchValue;

        // 2. CHANGE AFTER END DATE 
        $sqlChangeAfter = "SELECT COALESCE(SUM(
                               CASE 
                                   WHEN t.type='purchase' THEN (t.quantity * t.price_at_time) 
                                   ELSE -(t.quantity * p.buy_price) 
                               END
                           ), 0) 
                           FROM transactions t
                           JOIN products p ON t.product_id = p.id
                           WHERE t.company_id = ? AND t.date > ?";
        $stmtAfter = $this->pdo->prepare($sqlChangeAfter);
        $stmtAfter->execute([$companyId, $eDate]);
        $changeAfter = (float) $stmtAfter->fetchColumn();
        
        $endCap = $currCap - $changeAfter;

        // 3. CHANGE DURING PERIOD
        $sqlChangeDuring = "SELECT COALESCE(SUM(
                               CASE 
                                   WHEN t.type='purchase' THEN (t.quantity * t.price_at_time) 
                                   ELSE -(t.quantity * p.buy_price) 
                               END
                            ), 0) 
                            FROM transactions t
                            JOIN products p ON t.product_id = p.id
                            WHERE t.company_id = ? AND t.date >= ? AND t.date <= ?";
        $stmtDuring = $this->pdo->prepare($sqlChangeDuring);
        $stmtDuring->execute([$companyId, $sDate, $eDate]);
        $changeDuring = (float) $stmtDuring->fetchColumn();

        $startCap = $endCap - $changeDuring;

        // 4. ADDED BACK: PROFIT CALCULATIONS (Using true COGS)
        $stmtStartProfit = $this->pdo->prepare("SELECT COALESCE(SUM((t.quantity * t.price_at_time) - t.cogs), 0) FROM transactions t WHERE t.company_id = ? AND t.type = 'sale' AND t.date < ?");
        $stmtStartProfit->execute([$companyId, $sDate]);
        $startProfit = (float) $stmtStartProfit->fetchColumn();

        $stmtEndProfit = $this->pdo->prepare("SELECT COALESCE(SUM((t.quantity * t.price_at_time) - t.cogs), 0) FROM transactions t WHERE t.company_id = ? AND t.type = 'sale' AND t.date <= ?");
        $stmtEndProfit->execute([$companyId, $eDate]);
        $endProfit = (float) $stmtEndProfit->fetchColumn();

        // 5. RETURN EVERYTHING TO REACT
        return [
            'start_capital' => $startCap,
            'end_capital' => $endCap,
            'change' => $changeDuring,
            'current_capital' => $currCap,
            'start_profit' => $startProfit, // Now React will see this!
            'end_profit' => $endProfit      // And this!
        ];
    }

    public function getChartData($companyId, $startDate, $endDate, $timeframe = 'daily') {
        // FIXED: Removed double-concatenation
        $sDate = $startDate;
        $eDate = $endDate;
        
        $dateSelect = "DATE(t.date)";
        $groupBy = "DATE(t.date)";
        
        if ($timeframe === 'weekly') {
            $dateSelect = "CONCAT(YEAR(t.date), '-W', LPAD(WEEK(t.date, 1), 2, '0'))"; 
            $groupBy = "YEARWEEK(t.date, 1)";
        } else if ($timeframe === 'monthly') {
            $dateSelect = "DATE_FORMAT(t.date, '%Y-%m')"; 
            $groupBy = "DATE_FORMAT(t.date, '%Y-%m')";
        }

        // FIXED ACCOUNTING BUG: Chart now correctly graphs Stock Out by COGS instead of Retail Price
        $sql = "SELECT 
                    $dateSelect as date,
                    COALESCE(SUM(CASE WHEN t.type='purchase' THEN (t.quantity * t.price_at_time) ELSE 0 END), 0) as stock_in_value,
                    COALESCE(SUM(CASE WHEN t.type='sale' THEN t.cogs ELSE 0 END), 0) as stock_out_cogs
                FROM transactions t
                JOIN products p ON t.product_id = p.id
                WHERE t.company_id = ? AND t.date >= ? AND t.date <= ?
                GROUP BY $groupBy
                ORDER BY MIN(t.date) ASC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId, $sDate, $eDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductMovement($companyId, $startDate, $endDate) {
        // FIXED: Removed double-concatenation
        $sDate = $startDate;
        $eDate = $endDate;

        // FIXED: Replaced BETWEEN with >= and <=
        $sql = "SELECT 
                    p.id, p.name, p.sku, p.buy_price, p.image, 
                    p.stock_quantity as current_stock,
                    
                    -- NEW: Fetch the True Current Cost from FIFO Batches & Serials
                    (
                        COALESCE((SELECT SUM(buy_price) FROM product_serials WHERE product_id = p.id AND status = 'In Stock' AND company_id = p.company_id), 0) + 
                        COALESCE((SELECT SUM(quantity_remaining * buy_price) FROM product_batches WHERE product_id = p.id AND company_id = p.company_id), 0)
                    ) AS current_true_value,

                    COALESCE(SUM(CASE WHEN t.date > ? THEN (CASE WHEN t.type='purchase' THEN t.quantity ELSE -t.quantity END) ELSE 0 END), 0) as change_future,
                    
                    -- NEW: Financial value of future changes to accurately walk the capital backward to the selected date
                    COALESCE(SUM(CASE WHEN t.date > ? THEN (CASE WHEN t.type='purchase' THEN t.quantity * t.price_at_time ELSE -t.quantity * p.buy_price END) ELSE 0 END), 0) as value_change_future,

                    COALESCE(SUM(CASE WHEN t.date >= ? AND t.date <= ? AND t.type='purchase' THEN t.quantity ELSE 0 END), 0) as in_qty,
                    COALESCE(SUM(CASE WHEN t.date >= ? AND t.date <= ? AND t.type='sale' THEN t.quantity ELSE 0 END), 0) as out_qty,
                    COALESCE(SUM(CASE WHEN t.date >= ? AND t.date <= ? AND t.type='sale' THEN (t.price_at_time * t.quantity) - t.cogs ELSE 0 END), 0) as period_profit
                FROM products p
                LEFT JOIN transactions t ON p.id = t.product_id AND t.company_id = p.company_id
                WHERE p.company_id = ? AND p.status = 'Active'
                GROUP BY p.id";

        $stmt = $this->pdo->prepare($sql);
        // Note: 9 placeholders (?) matching the array below
        $stmt->execute([$eDate, $eDate, $sDate, $eDate, $sDate, $eDate, $sDate, $eDate, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach($rows as $r) {
            $endQty = $r['current_stock'] - $r['change_future'];
            $startQty = $endQty - $r['in_qty'] + $r['out_qty'];
            
            // Reconcile the True End Value mathematically!
            $endValue = $r['current_true_value'] - $r['value_change_future'];
            // Safeguard against negatives if old static data was messy
            if ($endValue < 0 && $endQty == 0) $endValue = 0; 

            $result[] = [
                'id' => $r['id'],
                'image' => $r['image'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'buy_price' => $r['buy_price'],
                'start_qty' => $startQty,
                'in_qty' => $r['in_qty'],
                'out_qty' => $r['out_qty'],
                'end_qty' => $endQty,
                'end_value' => $endValue, // NEW: Passing exact value to React
                'period_profit' => $r['period_profit']
            ];
        }
        return $result;
    }
}
?>