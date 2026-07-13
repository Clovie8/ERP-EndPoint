<?php
class Database {
    public $conn;

    public function connect() {
        $this->conn = null;

        try {
            // 1. SET PHP TIMEZONE TO RWANDA
            date_default_timezone_set('Africa/Kigali');

            // 2. LOAD CREDENTIALS (Using your Aiven details)
            $host = 'mysql-10bf9ec7-abber.i.aivencloud.com';
            $port = '26711'; // NEW: Aiven's custom port
            $db_name = 'defaultdb';
            $username = 'avnadmin';
            $password = 'AVNS_lUKNSkAfLU-mIpqljgd'; // Paste your copied password here

            // 3. SET UP SSL AND ERROR OPTIONS
            // Note: You must download ca.pem from Aiven and put it in this folder!
            $options = [
                PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/ca.pem', 
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ];

            // 4. CONNECT (Updated to include port and SSL options)
            $dsn = "mysql:host=" . $host . ";port=" . $port . ";dbname=" . $db_name;
            $this->conn = new PDO($dsn, $username, $password, $options);
            
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
