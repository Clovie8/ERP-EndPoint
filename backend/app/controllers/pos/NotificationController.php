<?php

class NotificationController {
    private $pdo;
    private $companyId;
    private $userRole;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->companyId = $_SESSION['company_id'] ?? 1;
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
    }

    public function fetch() {
        // 1. Ensure the user is logged in and has a company_id
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
            return;
        }

        $companyId = $_SESSION['company_id'];
        $userRole = $_SESSION['role'];

        try {
            // 2. STRICT MULTI-TENANT QUERY: Always include WHERE company_id = ?
            $sql = "SELECT * FROM notifications 
                    WHERE company_id = ? 
                    AND (target_role = 'All' OR target_role = ?) 
                    ORDER BY created_at DESC 
                    LIMIT 50";
            
            $stmt = $this->pdo->prepare($sql);
            // Pass the companyId to mathematically lock the query to their business
            $stmt->execute([$companyId, $userRole]); 
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $notifications]);
            
        } catch (PDOException $e) {
            error_log("Notification Fetch Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch notifications']);
        }
    }

    public function markRead() {
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
            return;
        }

        $companyId = $_SESSION['company_id'];

        try {
            // STRICT MULTI-TENANT UPDATE: Only mark THIS company's alerts as read!
            $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE company_id = ? AND is_read = 0");
            $stmt->execute([$companyId]);

            echo json_encode(['status' => 'success', 'message' => 'Notifications marked as read']);
            
        } catch (PDOException $e) {
            error_log("Notification Update Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to update notifications']);
        }
    }

    // Helper function to make timestamps look like SaaS notifications
    private function timeElapsedString($datetime) {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $weeks = floor($diff->d / 7);
        $days = $diff->d - ($weeks * 7);

        if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        if ($weeks > 0) return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        if ($days > 0) return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        if ($diff->s > 0) return $diff->s . ' second' . ($diff->s > 1 ? 's' : '') . ' ago';
        
        return 'just now';
    }

    public function runDailyChecks() {
        if ($this->userRole !== 'Admin') return; // Only Admins trigger the auditor

        // 1. DEBTS DUE TODAY
        $stmtDue = $this->pdo->prepare("
            SELECT id, receipt_number, client_name, balance_due 
            FROM invoices 
            WHERE company_id = ? 
            AND type = 'sale' 
            AND payment_status IN ('partial', 'credit') 
            AND deadline_date = CURDATE()
        ");
        $stmtDue->execute([$this->companyId]);
        
        while ($debt = $stmtDue->fetch(PDO::FETCH_ASSOC)) {
            $owed = (float)$debt['balance_due'];

            if ($owed <= 0) continue;

            $client = !empty($debt['client_name']) ? $debt['client_name'] : 'Customer';
            $receipt = !empty($debt['receipt_number']) ? $debt['receipt_number'] : "#" . $debt['id'];
            
            $msg = "Payment Due Today: {$client} owes Rwf " . number_format($owed) . " for Invoice {$receipt}.";
            $this->insertUniqueNotification('debt_due', $msg, 'view_debt', $debt['id'], 'Admin');
        }

        // 2. OVERDUE DEBTS
        $stmtOverdue = $this->pdo->prepare("
            SELECT id, receipt_number, client_name, balance_due 
            FROM invoices 
            WHERE company_id = ? 
            AND type = 'sale' 
            AND payment_status IN ('partial', 'credit') 
            AND deadline_date < CURDATE()
        ");
        $stmtOverdue->execute([$this->companyId]);
        
        while ($debt = $stmtOverdue->fetch(PDO::FETCH_ASSOC)) {
            $owed = (float)$debt['balance_due'];
            
            if ($owed <= 0) continue;

            $client = !empty($debt['client_name']) ? $debt['client_name'] : 'Customer';
            $receipt = !empty($debt['receipt_number']) ? $debt['receipt_number'] : "#" . $debt['id'];
            
            $msg = "Overdue Debt: {$client} missed their payment deadline for Invoice {$receipt}!";
            $this->insertUniqueNotification('debt_overdue', $msg, 'view_debt', $debt['id'], 'Admin');
        }

        // 3. FORGOTTEN OPEN SHIFTS (> 24 HOURS)
        $stmtShifts = $this->pdo->query("SELECT shifts.id, u.name FROM shifts JOIN users u ON shifts.user_id = u.id WHERE shifts.company_id = {$this->companyId} AND shifts.status = 'open' AND shifts.start_time < NOW() - INTERVAL 24 HOUR");
        while ($shift = $stmtShifts->fetch(PDO::FETCH_ASSOC)) {
            $msg = "Shift Warning: {$shift['name']}'s register has been open for over 24 hours without closing.";
            $this->insertUniqueNotification('shift_warning', $msg, 'view_shift', $shift['id'], 'Admin');
        }

        // 4. BILLING & SUBSCRIPTION CHECKS ---
        $stmtBilling = $this->pdo->query("
            SELECT id, plan_tier, subscription_ends_at, DATEDIFF(subscription_ends_at, CURDATE()) as days_left 
            FROM companies 
            WHERE id = {$this->companyId}
        ");
        if ($billing = $stmtBilling->fetch(PDO::FETCH_ASSOC)) {
            // Only run the check if they are not on a lifetime/null plan
            if ($billing['subscription_ends_at'] !== null) {
                $daysLeft = (int)$billing['days_left'];
                
                if ($daysLeft < 0) {
                    // Already Expired
                    $msg = "Action Required: Your {$billing['plan_tier']} plan has expired! Please contact support to renew your subscription.";
                    $this->insertUniqueNotification('billing_expired', $msg, 'view_billing', $billing['id'], 'Admin');
                } elseif ($daysLeft >= 0 && $daysLeft <= 7) {
                    // Expiring in 7 days or less
                    $msg = "Billing Reminder: Your {$billing['plan_tier']} plan expires in {$daysLeft} day(s). Renew soon to avoid interruption.";
                    $this->insertUniqueNotification('billing_warning', $msg, 'view_billing', $billing['id'], 'Admin');
                }
            }
        }
        
        echo json_encode(['status' => 'success']);
    }

    // Helper to prevent duplicate alerts (Limits to ONE reminder per day)
    private function insertUniqueNotification($type, $message, $actionType, $refId, $targetRole) {
        // NEW LOGIC: Check if this exact notification was already created TODAY
        $stmtCheck = $this->pdo->prepare("SELECT id FROM notifications WHERE company_id = ? AND type = ? AND reference_id = ? AND DATE(created_at) = CURDATE()");
        $stmtCheck->execute([$this->companyId, $type, $refId]);
        
        if (!$stmtCheck->fetch()) {
            $stmt = $this->pdo->prepare("INSERT INTO notifications (company_id, type, message, action_type, reference_id, target_role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$this->companyId, $type, $message, $actionType, $refId, $targetRole]);
        }
    }
}