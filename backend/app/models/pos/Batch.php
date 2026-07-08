<?php
class Batch {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAllBatches($companyId, $status = 'all') {
        $statusFilter = "";
        if ($status === 'active') {
            $statusFilter = " AND b.quantity_remaining > 0 ";
        } else if ($status === 'depleted') {
            $statusFilter = " AND b.quantity_remaining = 0 ";
        }

        $sql = "SELECT b.*, p.name as product_name, p.sku, p.image 
                FROM product_batches b 
                JOIN products p ON b.product_id = p.id 
                WHERE b.company_id = ? $statusFilter 
                ORDER BY b.quantity_remaining DESC, b.id ASC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adjustQuantity($companyId, $batchId, $newQty, $userId) {
        $this->pdo->beginTransaction();
        try {
            // Get current batch info
            $stmt = $this->pdo->prepare("SELECT quantity_remaining, product_id FROM product_batches WHERE id = ? AND company_id = ?");
            $stmt->execute([$batchId, $companyId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new Exception("Batch not found.");
            }

            $oldQty = $batch['quantity_remaining'];
            $diff = $newQty - $oldQty; // If 10 -> 8, diff is -2. If 10 -> 12, diff is +2.

            if ($diff != 0) {
                // Update the batch
                $stmtUpd = $this->pdo->prepare("UPDATE product_batches SET quantity_remaining = ? WHERE id = ? AND company_id = ?");
                $stmtUpd->execute([$newQty, $batchId, $companyId]);

                // Sync the main product stock
                $op = ($diff > 0) ? '+' : '-';
                $absDiff = abs($diff);
                $this->pdo->query("UPDATE products SET stock_quantity = stock_quantity $op $absDiff WHERE id = {$batch['product_id']} AND company_id = $companyId");

                // Log the audit
                $stmtAudit = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, 'Batch Adjustment', ?)");
                $desc = "Adjusted Batch #$batchId for Product ID {$batch['product_id']}. Qty changed from $oldQty to $newQty.";
                $stmtAudit->execute([$companyId, $userId, $desc]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
}