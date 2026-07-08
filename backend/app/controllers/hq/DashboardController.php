<?php
class DashboardController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getGlobalStats() {
        try {
            // 1. Company Status Breakdown (Active, Trial, Suspended/Churn)
            $stmt1 = $this->pdo->query("SELECT status, COUNT(*) as count FROM companies GROUP BY status");
            $statuses = $stmt1->fetchAll(PDO::FETCH_ASSOC);
            $active = 0; $trial = 0; $suspended = 0;
            foreach($statuses as $s) {
                if($s['status'] === 'active') $active = $s['count'];
                if($s['status'] === 'trial') $trial = $s['count'];
                if($s['status'] === 'suspended') $suspended = $s['count'];
            }

            // 2. Calculate MRR (Monthly Recurring Revenue)
            $stmt2 = $this->pdo->query("SELECT plan_tier, COUNT(*) as count FROM companies WHERE status = 'active' GROUP BY plan_tier");
            $plans = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $mrr = 0;
            foreach ($plans as $plan) {
                if ($plan['plan_tier'] === 'basic') $mrr += ($plan['count'] * 15000);
                if ($plan['plan_tier'] === 'pro') $mrr += ($plan['count'] * 30000);
                if ($plan['plan_tier'] === 'enterprise') $mrr += ($plan['count'] * 50000);
            }

            // 3. System Health (Total Transactions Today across all servers)
            $stmt3 = $this->pdo->query("SELECT COUNT(*) as total FROM transactions WHERE DATE(date) = CURDATE()");
            $systemHealth = $stmt3->fetch(PDO::FETCH_ASSOC)['total'];

            // 4. LEADERBOARD: Top 5 Most Active Stores Today (Based on transaction volume)
            // This proves which clients are using the system the most heavily right now
            $stmt4 = $this->pdo->query("
                SELECT c.name, COUNT(t.id) as txn_count 
                FROM transactions t 
                JOIN companies c ON t.company_id = c.id 
                WHERE DATE(t.date) = CURDATE()
                GROUP BY c.id 
                ORDER BY txn_count DESC LIMIT 5
            ");
            $topStores = $stmt4->fetchAll(PDO::FETCH_ASSOC);

            // 5. LEADERBOARD: Heaviest Data Users (Based on who has the most products)
            // Helps you monitor server storage loads
            $stmt5 = $this->pdo->query("
                SELECT c.name, COUNT(p.id) as product_count 
                FROM products p 
                JOIN companies c ON p.company_id = c.id 
                GROUP BY c.id 
                ORDER BY product_count DESC LIMIT 5
            ");
            $heaviestUsers = $stmt5->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'activeCompanies' => (int)$active,
                    'trialCompanies' => (int)$trial,
                    'suspendedCompanies' => (int)$suspended,
                    'mrr' => $mrr,
                    'transactionsToday' => (int)$systemHealth,
                    'topStores' => $topStores,
                    'heaviestUsers' => $heaviestUsers
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to load advanced HQ stats.']);
        }
    }
}