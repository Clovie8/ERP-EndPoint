<?php
class Transaction {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function create($companyId, $productId, $type, $qty) {
        $stmt = $this->pdo->prepare("SELECT sell_price, buy_price FROM products WHERE id = ? AND company_id = ?");
        $stmt->execute([$productId, $companyId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$product) return false;
        
        $price = ($type == 'sale') ? $product['sell_price'] : $product['buy_price'];

        // REFINED: Removed ghost columns to match new schema
        $sql = "INSERT INTO transactions (company_id, product_id, type, quantity, price_at_time) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$companyId, $productId, $type, $qty, $price]);
    }

    public function getFilteredHistory($companyId, $type, $search, $startDate, $endDate, $page, $limit) {
        $offset = ($page - 1) * $limit;
        $params = [$companyId, $type];
        
        $baseSql = "FROM transactions t 
                    JOIN products p ON t.product_id = p.id 
                    WHERE t.company_id = ? AND t.type = ?";

        if (!empty($search)) {
            $baseSql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (!empty($startDate) && !empty($endDate)) {
            $baseSql .= " AND DATE(t.date) BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        } elseif (!empty($startDate)) {
            $baseSql .= " AND DATE(t.date) = ?";
            $params[] = $startDate;
        }

        $sumSql = "SELECT SUM(t.quantity * t.price_at_time) as total_amount, SUM((t.price_at_time - p.buy_price) * t.quantity) as total_profit " . $baseSql;
        $stmtSum = $this->pdo->prepare($sumSql);
        $stmtSum->execute($params);
        $sums = $stmtSum->fetch(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) " . $baseSql;
        $stmtCount = $this->pdo->prepare($countSql);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        $sql = "SELECT t.*, p.name as product_name, p.sku, p.buy_price " . $baseSql . " ORDER BY t.date DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['data' => $data, 'total' => $total, 'sums' => $sums];
    }

    public function find($companyId, $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM transactions WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($companyId, $id, $qty) {
        $stmt = $this->pdo->prepare("UPDATE transactions SET quantity = ? WHERE id = ? AND company_id = ?");
        return $stmt->execute([$qty, $id, $companyId]);
    }

    public function delete($companyId, $id) {
        $stmt = $this->pdo->prepare("DELETE FROM transactions WHERE id = ? AND company_id = ?");
        return $stmt->execute([$id, $companyId]);
    }
}