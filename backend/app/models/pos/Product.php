<?php
class Product {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll($companyId, $search = '') {
        $sql = "SELECT * FROM products WHERE company_id = ?";
        $params = [$companyId];
        
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $stmt = $this->pdo->prepare($sql . " ORDER BY id DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPaged($companyId, $search = '', $page = 1, $limit = 10) {
        $offset = ($page - 1) * $limit;
        $params = [$companyId];
        $sql = "SELECT * FROM products WHERE company_id = ?";
        
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $countSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
        $stmtCount = $this->pdo->prepare($countSql);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['data' => $data, 'total' => $total];
    }

    public function find($companyId, $id) {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($companyId, $data) {
        // --- NEW: Handle Serialization Switch ---
        $is_serialized = isset($data['is_serialized']) ? (int)$data['is_serialized'] : 0;
        
        $sql = "INSERT INTO products (company_id, name, sku, buy_price, sell_price, stock_quantity, is_serialized) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$companyId, $data['name'], $data['sku'], $data['buy'], $data['sell'], $data['qty'], $is_serialized]);
    }

    public function update($companyId, $data) {
        // --- NEW: Handle Serialization Switch ---
        $is_serialized = isset($data['is_serialized']) ? (int)$data['is_serialized'] : 0;
        
        $sql = "UPDATE products SET name=?, buy_price=?, sell_price=?, is_serialized=? WHERE id=? AND company_id=?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$data['name'], $data['buy'], $data['sell'], $is_serialized, $data['id'], $companyId]);
    }

    public function updateStock($companyId, $id, $newQty) {
        $stmt = $this->pdo->prepare("UPDATE products SET stock_quantity = ? WHERE id = ? AND company_id = ?");
        return $stmt->execute([$newQty, $id, $companyId]);
    }

    public function delete($companyId, $id) {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ? AND company_id = ?");
        return $stmt->execute([$id, $companyId]);
    }
}