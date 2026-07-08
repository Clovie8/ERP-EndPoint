<?php
class Database {
    public $conn;

    public function connect() {
        $this->conn = null;

        try {
            // 1. SET PHP TIMEZONE TO RWANDA
            date_default_timezone_set('Africa/Kigali');

            // 2. LOAD CREDENTIALS SECURELY FROM GLOBAL MEMORY
            // (These were loaded by api.php before this file was even called!)
            $host = $_ENV['DB_HOST'] ?? '';
            $db_name = $_ENV['DB_NAME'] ?? '';
            $username = $_ENV['DB_USER'] ?? '';
            $password = $_ENV['DB_PASS'] ?? '';

            // 3. CONNECT
            $this->conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
            
            // 4. SET STRICT ERROR MODE
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 5. SET MYSQL CONNECTION TIMEZONE
            $this->conn->exec("SET time_zone = '+02:00'");

        } catch(PDOException $e) {
            // SECURITY FIX: Silently log the error, don't show hackers the problem!
            error_log("CRITICAL DB ERROR: " . $e->getMessage());
            throw new Exception("Database Connection Failed");
        }

        return $this->conn;
    }
}