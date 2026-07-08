<?php
class BillingController {
    private $pdo;

    public function __construct($pdo) { $this->pdo = $pdo; }

    public function getBillingData() {
        try {
            $stmt = $this->pdo->query("
                SELECT id, name, plan_tier, status, subscription_ends_at, 
                DATEDIFF(subscription_ends_at, NOW()) as days_left 
                FROM companies 
                ORDER BY days_left ASC
            ");
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate Billing KPIs
            $kpis = ['healthy' => 0, 'at_risk' => 0, 'expired' => 0, 'mrr_at_risk' => 0];
            
            foreach($companies as $c) {
                // Approximate value based on tier
                $tierValue = $c['plan_tier'] === 'enterprise' ? 50000 : ($c['plan_tier'] === 'pro' ? 30000 : 15000);
                
                if ($c['days_left'] < 0) {
                    $kpis['expired']++;
                } elseif ($c['days_left'] <= 7) {
                    $kpis['at_risk']++;
                    $kpis['mrr_at_risk'] += $tierValue;
                } else {
                    $kpis['healthy']++;
                }
            }

            echo json_encode([
                'status' => 'success', 
                'data' => [
                    'companies' => $companies, 
                    'kpis' => $kpis
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch billing data.']);
        }
    }

    public function renewSubscription() {
        $data = json_decode(file_get_contents("php://input"));
        $companyId = $data->company_id ?? 0;
        $months = (int)($data->months ?? 1);
        $method = $data->method ?? 'momo'; 

        try {
            // 1. Get their current expiration date from the DB
            $stmt = $this->pdo->prepare("SELECT subscription_ends_at FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $currentExpiry = $stmt->fetchColumn();

            // 2. Safely determine the starting point (Handling NULLs)
            $baseDate = time(); // Default to starting right now
            
            if (!empty($currentExpiry)) {
                $parsedExpiry = strtotime($currentExpiry);
                // If they have a valid date AND it is in the future, add to it.
                // Otherwise, it stays as today.
                if ($parsedExpiry !== false && $parsedExpiry > time()) {
                    $baseDate = $parsedExpiry; 
                }
            }

            // 3. Calculate the exact new date securely in PHP
            $newExpiryDate = date('Y-m-d H:i:s', strtotime("+$months months", $baseDate));

            // 4. Run a simple, bug-free UPDATE query
            $updateStmt = $this->pdo->prepare("UPDATE companies SET subscription_ends_at = ?, status = 'active' WHERE id = ?");
            $updateStmt->execute([$newExpiryDate, $companyId]);

            // ------------------------------------------------------------------
            // 5. --- NEW: FETCH DATA AND SEND RENEWAL EMAIL ---
            // Fetch the company details and the primary admin user's email
            $fetchStmt = $this->pdo->prepare("
                SELECT u.name AS admin_name, u.email, c.name AS company_name 
                FROM users u 
                JOIN companies c ON u.company_id = c.id 
                WHERE c.id = ? AND u.role = 'admin' 
                LIMIT 1
            ");
            $fetchStmt->execute([$companyId]);
            $accountData = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if ($accountData) {
                $name = $accountData['admin_name'];
                $companyName = $accountData['company_name'];
                $status = 'ACTIVE';
                
                // Format the date beautifully (e.g., "August 15, 2026")
                $endDate = date('F j, Y', strtotime($newExpiryDate)); 
                
                // Update this link to match your actual production frontend URL
                $loginLink = 'https://dashboard.vendorapos.app/login'; 

                $emailBody = "
                <div style='font-family: system-ui, -apple-system, sans-serif; background-color: #f8fafc; padding: 40px 20px;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; padding: 40px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);'>
                        
                        <div style='text-align: center; margin-bottom: 32px;'>
                            <span style='color: #2563eb; font-size: 26px; font-weight: 900; letter-spacing: 1px; display: block; margin-bottom: 16px;'>VENDORA</span>
                            <h2 style='color: #0f172a; font-size: 24px; font-weight: 800; margin: 0 0 12px 0;'>Subscription Renewed!</h2>
                            <p style='color: #64748b; font-size: 16px; line-height: 1.6; margin: 0;'>Great news, <b>$name</b>! Your Vendora subscription for <b>$companyName</b> has been successfully extended by $months month(s). Your point-of-sale ecosystem is active and ready to scale.</p>
                        </div>

                        <div style='background-color: #f1f5f9; border-radius: 16px; padding: 24px; margin-bottom: 32px; border: 1px solid #e2e8f0;'>
                            <h3 style='color: #0f172a; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0; margin-bottom: 16px; border-bottom: 1px solid #cbd5e1; padding-bottom: 12px;'>Your Account Overview</h3>
                            
                            <table style='width: 100%; border-collapse: collapse;'>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500;'>Company Name:</td>
                                    <td style='padding: 10px 0; color: #0f172a; font-size: 15px; font-weight: 700; text-align: right;'>$companyName</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>Account Status:</td>
                                    <td style='padding: 10px 0; color: #10b981; font-size: 15px; font-weight: 800; text-align: right; border-top: 1px dashed #cbd5e1;'>$status</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>Months Added:</td>
                                    <td style='padding: 10px 0; color: #2563eb; font-size: 15px; font-weight: 800; text-align: right; border-top: 1px dashed #cbd5e1;'>+ $months Month(s)</td>
                                </tr>
                                <tr>
                                    <td style='padding: 10px 0; color: #64748b; font-size: 14px; font-weight: 500; border-top: 1px dashed #cbd5e1;'>New Expiry Date:</td>
                                    <td style='padding: 10px 0; color: #ef4444; font-size: 15px; font-weight: 700; text-align: right; border-top: 1px dashed #cbd5e1;'>$endDate</td>
                                </tr>
                            </table>
                        </div>

                        <div style='text-align: center;'>
                            <a href='$loginLink' style='background-color: #0f172a; color: #ffffff; font-weight: 600; font-size: 15px; text-decoration: none; padding: 14px 32px; border-radius: 12px; display: inline-block; transition: background-color 0.3s;'>Sign In to Dashboard</a>
                            <p style='margin-top: 24px; color: #94a3b8; font-size: 12px;'>Need help? Reply to this email to reach our support team.</p>
                        </div>

                    </div>
                </div>";

                require_once '../app/config/Mail.php';
                // Use the Mail::send method
                Mail::send($accountData['email'], $name, "Vendora Subscription Renewed!", $emailBody, "");
            }
            // ------------------------------------------------------------------

            echo json_encode(['status' => 'success', 'message' => "Subscription extended securely by $months month(s)."]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
        }
    }

    public function runAutoSuspend() {
        try {
            // Lock out anyone whose date is in the past, but ignore lifetime/admin accounts if any
            $stmt = $this->pdo->query("
                UPDATE companies 
                SET status = 'suspended' 
                WHERE subscription_ends_at < NOW() AND status = 'active'
            ");
            $suspendedCount = $stmt->rowCount();
            echo json_encode(['status' => 'success', 'message' => "$suspendedCount overdue stores have been auto-suspended."]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to run billing engine.']);
        }
    }
}