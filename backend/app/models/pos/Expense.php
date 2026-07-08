<?php
class Expense {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAll($companyId, $search = '', $start = '', $end = '', $limit = 10, $offset = 0) {
        // Use 'e.' prefix because we are joining tables
        $where = "WHERE e.company_id = " . (int)$companyId;
        if($search) $where .= " AND (e.title LIKE '%$search%' OR e.category LIKE '%$search%' OR e.auth_name LIKE '%$search%')";
        if($start) $where .= " AND DATE(e.date) >= '$start'";
        if($end) $where .= " AND DATE(e.date) <= '$end'";

        // Left Join with users table to get the user_name
        $sql = "SELECT e.*, u.name as user_name 
                FROM expenses e 
                LEFT JOIN users u ON e.user_id = u.id 
                $where 
                ORDER BY e.date DESC 
                LIMIT $limit OFFSET $offset";
                
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalCount($companyId, $search = '', $start = '', $end = '') {
        $where = "WHERE e.company_id = " . (int)$companyId;
        if($search) $where .= " AND (e.title LIKE '%$search%' OR e.category LIKE '%$search%' OR e.auth_name LIKE '%$search%')";
        if($start) $where .= " AND DATE(e.date) >= '$start'";
        if($end) $where .= " AND DATE(e.date) <= '$end'";
        
        return $this->pdo->query("SELECT COUNT(*) FROM expenses e LEFT JOIN users u ON e.user_id = u.id $where")->fetchColumn();
    }

    public function getTotalAmount($companyId, $search = '', $start = '', $end = '') {
        $where = "WHERE e.company_id = " . (int)$companyId;
        if($search) $where .= " AND (e.title LIKE '%$search%' OR e.category LIKE '%$search%' OR e.auth_name LIKE '%$search%')";
        if($start) $where .= " AND DATE(e.date) >= '$start'";
        if($end) $where .= " AND DATE(e.date) <= '$end'";
        
        return $this->pdo->query("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e LEFT JOIN users u ON e.user_id = u.id $where")->fetchColumn();
    }

    public function add($companyId, $title, $category, $amount, $date, $userId, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status) {
        $stmt = $this->pdo->prepare("INSERT INTO expenses (company_id, title, category, amount, date, user_id, qty, auth_name, auth_phone, auth_place, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$companyId, $title, $category, $amount, $date, $userId, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status]);
    }

    public function update($companyId, $id, $title, $category, $amount, $date, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status) {
        $stmt = $this->pdo->prepare("UPDATE expenses SET title=?, category=?, amount=?, date=?, qty=?, auth_name=?, auth_phone=?, auth_place=?, payment_method=?, status=? WHERE id=? AND company_id=?");
        return $stmt->execute([$title, $category, $amount, $date, $qty, $auth_name, $auth_phone, $auth_place, $payment_method, $status, $id, $companyId]);
    }

    public function delete($companyId, $id) {
        $stmt = $this->pdo->prepare("DELETE FROM expenses WHERE id = ? AND company_id = ?");
        return $stmt->execute([$id, $companyId]);
    }
}