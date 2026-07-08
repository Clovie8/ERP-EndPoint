<?php
class User {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Login and Register were migrated to AuthController.php, 
    // but these fetch/update methods remain for the Profile Settings page.

    public function find($id) {
        $stmt = $this->pdo->prepare("SELECT id, company_id, name, email, role, status FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $name, $email, $password = null) {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
            return $stmt->execute([$name, $email, $hash, $id]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            return $stmt->execute([$name, $email, $id]);
        }
    }
}