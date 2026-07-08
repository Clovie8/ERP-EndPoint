<?php
class TransactionController {
    private $pdo;
    private $companyId;
    private $userId;
    private $userRole;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        $this->companyId = (int)$_SESSION['company_id'];
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
        $this->userRole = $_SESSION['role'] ?? 'Cashier';
    }

    // ---> SERIAL NUMBER LOOKUP ENGINE <---
    public function getProductBySerial() {
        $serial = $_GET['serial'] ?? '';
        
        $stmt = $this->pdo->prepare("SELECT product_id FROM product_serials WHERE company_id = ? AND serial_number = ? AND status = 'In Stock' LIMIT 1");
        $stmt->execute([$this->companyId, $serial]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode(['status' => 'success', 'data' => ['product_id' => $row['product_id']]]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Serial not found or already sold']);
        }
    }
    // ------------------------------------------

    public function index() {
        $type = $_GET['type'] ?? 'sale';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = $_GET['search'] ?? '';
        $start = $_GET['start_date'] ?? '';
        $end = $_GET['end_date'] ?? '';
        $paymentStatus = $_GET['payment_status'] ?? '';
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // ==============================================================
        // 1. BUILD THE SECURE QUERY
        // ==============================================================
        $where = "WHERE i.company_id = ? AND i.type = ?";
        $params = [$this->companyId, $type];

        if (!empty($search)) {
            $where .= " AND (
                i.client_name LIKE ? 
                OR i.receipt_number LIKE ? 
                OR i.ebm_number LIKE ?
                OR c.contact_code LIKE ? -- <-- 1. NEW: Added code to search
                OR EXISTS (
                    SELECT 1 FROM transactions t 
                    JOIN products p ON t.product_id = p.id 
                    WHERE t.invoice_id = i.id 
                    AND (p.name LIKE ? OR p.sku LIKE ?)
                )
            )";
            $searchTerm = "%$search%";
            // <-- 2. NEW: Pushed 6 search terms instead of 5 to match the new query
            array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm); 
        }

        if (!empty($start)) {
            $where .= " AND DATE(i.created_at) >= ?";
            $params[] = $start;
        }

        if (!empty($end)) {
            $where .= " AND DATE(i.created_at) <= ?";
            $params[] = $end;
        }

        if (!empty($paymentStatus)) {
            $where .= " AND i.payment_status = ?";
            $params[] = $paymentStatus;
        }

        // ==============================================================
        // 2. FETCH THE PAGINATED TABLE DATA
        // ==============================================================
        $sql = "SELECT i.*, i.created_at as date, u.name as user_name, 
                       c.contact_code, -- <-- 3. NEW: Fetch the code for React
                       c.tin_number,
                       c.email as client_email,
                       (SELECT GROUP_CONCAT(pr.name SEPARATOR ', ') 
                        FROM transactions t 
                        JOIN products pr ON t.product_id = pr.id 
                        WHERE t.invoice_id = i.id) as product_name
                FROM invoices i 
                LEFT JOIN users u ON i.user_id = u.id
                LEFT JOIN contacts c ON i.contact_id = c.id -- <-- 4. NEW: Join table
                $where 
                ORDER BY i.created_at DESC 
                LIMIT $limit OFFSET $offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ==============================================================
        // 3. COUNT TOTAL ROWS (For React Pagination)
        // ==============================================================
        // <-- 5. NEW: Added the JOIN here because $where now looks for c.contact_code
        $countSql = "SELECT COUNT(*) FROM invoices i 
                     LEFT JOIN contacts c ON i.contact_id = c.id 
                     $where";
        $stmtCount = $this->pdo->prepare($countSql);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        // ==============================================================
        // 4. CALCULATE DYNAMIC SUMS (Total Amount & Profit)
        // ==============================================================
        $sqlSums = "SELECT 
                        SUM(filtered_invoices.total_amount) as total_amount,
                        SUM(
                            (SELECT SUM((t.price_at_time - p.buy_price) * t.quantity) 
                             FROM transactions t 
                             JOIN products p ON t.product_id = p.id 
                             WHERE t.invoice_id = filtered_invoices.id)
                        ) as total_profit
                    FROM (
                        SELECT i.id, i.total_amount FROM invoices i 
                        LEFT JOIN contacts c ON i.contact_id = c.id -- <-- 6. NEW: Added JOIN here too
                        $where
                    ) as filtered_invoices";

        $stmtSums = $this->pdo->prepare($sqlSums);
        $stmtSums->execute($params);
        $sums = $stmtSums->fetch(PDO::FETCH_ASSOC);

        // Send perfectly synchronized data back to React
        echo json_encode([
            'status' => 'success', 
            'data' => $data, 
            'total' => $total, 
            'page' => $page, 
            'limit' => $limit, 
            'sums' => $sums
        ]);
    }

    public function handle() {
        $type = $_POST['type'] ?? 'sale';
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);

        $contactId = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
        
        $clientName = $_POST['client_name'] ?? '';
        $clientPhone = $_POST['client_phone'] ?? '';
        $paymentStatus = $_POST['payment_status'] ?? 'paid';
        $amountPaid = (float)($_POST['amount_paid'] ?? 0);
        $receiptNumber = $_POST['receipt_number'] ?? null;
        
        $paymentMethodUsed = $_POST['payment_method_used'] ?? null;
        if ($paymentMethodUsed === '') $paymentMethodUsed = null;

        $deadlineDate = $_POST['deadline_date'] ?? null;
        if ($deadlineDate === '') $deadlineDate = null;

        $ebmNumber = $_POST['ebm_number'] ?? null;
        if ($ebmNumber === '') $ebmNumber = null;

        $shiftId = null;
        $stmtShift = $this->pdo->prepare("SELECT id FROM shifts WHERE user_id = ? AND company_id = ? AND status = 'open' LIMIT 1");
        $stmtShift->execute([$this->userId, $this->companyId]);
        $shift = $stmtShift->fetch(PDO::FETCH_ASSOC);

        if ($shift) {
            $shiftId = $shift['id'];
        } else if ($this->userRole === 'Cashier' && $type === 'sale') {
            echo json_encode(['status' => 'error', 'message' => 'You must open a register shift before making a sale!']);
            return;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = ? AND company_id = ? AND status = 'Active'");
        $stmt->execute([$pid, $this->companyId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) { 
            echo json_encode(['status' => 'error', 'message' => 'Transaction failed: Product is either not found or has been Archived.']); 
            return; 
        }

        $isService = (isset($product['item_type']) && $product['item_type'] === 'service');

        if (!$isService && $type === 'sale' && $qty > $product['stock_quantity']) {
            echo json_encode(['status' => 'error', 'message' => "Insufficient stock! Only {$product['stock_quantity']} left."]);
            return;
        }

        $frontendPrice = isset($_POST['price_at_time']) ? (float)$_POST['price_at_time'] : null;
        $price = ($type == 'sale') ? ($frontendPrice !== null ? $frontendPrice : $product['sell_price']) : ($frontendPrice !== null ? $frontendPrice : $product['buy_price']);
        
        $currentDate = date('Y-m-d H:i:s'); 

        // ==============================================================
            

        if (isset($product['is_serialized']) && $product['is_serialized'] == 1) {
            $serials = json_decode($_POST['serials'] ?? '[]', true);
            
            if (is_array($serials) && count($serials) > 0) {
                $placeholders = str_repeat('?,', count($serials) - 1) . '?';
                
                if ($type === 'purchase') {
                    $stmtCheck = $this->pdo->prepare("SELECT serial_number FROM product_serials WHERE company_id = ? AND serial_number IN ($placeholders)");
                    $checkParams = array_merge([$this->companyId], $serials);
                    $stmtCheck->execute($checkParams);
                    $dups = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

                    if (count($dups) > 0) {
                        echo json_encode(['status' => 'error', 'message' => 'The following serial numbers already exist in your system: ' . implode(', ', $dups)]);
                        return;
                    }
                } else if ($type === 'sale') {
                    $stmtCheck = $this->pdo->prepare("SELECT serial_number FROM product_serials WHERE company_id = ? AND product_id = ? AND status = 'In Stock' AND serial_number IN ($placeholders)");
                    $checkParams = array_merge([$this->companyId, $pid], $serials);
                    $stmtCheck->execute($checkParams);
                    $validSerials = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

                    if (count($validSerials) !== count($serials)) {
                        $invalid = array_diff($serials, $validSerials);
                        echo json_encode(['status' => 'error', 'message' => "The following serial numbers are invalid or already sold: " . implode(', ', $invalid)]);
                        return;
                    }
                }
            }
        }

        $this->pdo->beginTransaction();

        try {
            // ==============================================================
            // --- 1. PROCESS INVOICE FIRST (TO GET THE ID) ---
            // ==============================================================
            $invoiceId = null;
            if ($receiptNumber) {
                $lineTotal = $qty * $price; 
                
                $stmtInvoice = $this->pdo->prepare("SELECT id, total_amount, amount_paid FROM invoices WHERE receipt_number = ? AND company_id = ?");
                $stmtInvoice->execute([$receiptNumber, $this->companyId]);
                $invoice = $stmtInvoice->fetch(PDO::FETCH_ASSOC);

                if ($invoice) {
                    $invoiceId = $invoice['id'];
                    $newTotal = $invoice['total_amount'] + $lineTotal;
                    
                    // --- THE FOOLPROOF FIX: AUTO-GROWING PAYMENTS ---
                    // If the cart is fully paid, the amount_paid grows automatically to match the total!
                    // If it is partial/credit, it strictly locks in the exact deposit the customer made.
                    if ($paymentStatus === 'paid') {
                        $newAmountPaid = $newTotal;
                    } else if ($paymentStatus === 'credit') {
                        $newAmountPaid = 0;
                    } else {
                        $newAmountPaid = (float)$invoice['amount_paid'] + $amountPaid;
                    }
                    
                    $newBalance = $newTotal - $newAmountPaid;
                    
                    // Recalculate the invoice status based on the new math
                    $newStatus = ($newBalance <= 0) ? 'paid' : (($newAmountPaid > 0) ? 'partial' : 'credit');

                    // Notice we added `amount_paid = ?` to the UPDATE query here!
                    $updateInv = $this->pdo->prepare("UPDATE invoices SET total_amount = ?, amount_paid = ?, balance_due = ?, payment_status = ? WHERE id = ?");
                    $updateInv->execute([$newTotal, $newAmountPaid, $newBalance, $newStatus, $invoiceId]);
                } else {
                    // First Item in the cart
                    if ($paymentStatus === 'paid') {
                        $firstItemPaid = $lineTotal; // Temporarily match Item 1, will grow automatically
                    } else if ($paymentStatus === 'credit') {
                        $firstItemPaid = 0;
                    } else {
                        $firstItemPaid = $amountPaid; // Trust the deposit sent by the frontend
                    }

                    $balance = $lineTotal - $firstItemPaid;
                    $invStatus = ($balance <= 0) ? 'paid' : (($firstItemPaid > 0) ? 'partial' : 'credit');

                    $insertInv = $this->pdo->prepare("INSERT INTO invoices (company_id, user_id, shift_id, receipt_number, ebm_number, contact_id, client_name, client_phone, total_amount, amount_paid, balance_due, payment_status, deadline_date, payment_method_used, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertInv->execute([$this->companyId, $this->userId, $shiftId, $receiptNumber, $ebmNumber, $contactId, $clientName, $clientPhone, $lineTotal, $firstItemPaid, $balance, $invStatus, $deadlineDate, $paymentMethodUsed, $type]);

                    $invoiceId = $this->pdo->lastInsertId();
                }
            }
            // ==============================================================

            // ==============================================================
            // --- 2. INSERT TRANSACTION (WITH FOREIGN KEY ID, DROPPING GHOSTS) ---
            // ==============================================================
            $sql = "INSERT INTO transactions (invoice_id, company_id, product_id, type, quantity, price_at_time, cogs) VALUES (?, ?, ?, ?, ?, ?, 0)";
            $stmt = $this->pdo->prepare($sql);
            
            if (!$stmt->execute([$invoiceId, $this->companyId, $pid, $type, $qty, $price])) {
                throw new Exception("Failed to insert initial transaction record.");
            }
            
            $transId = $this->pdo->lastInsertId();
            $totalCogs = 0;
            
            if (!$isService) {
                if ($type === 'purchase') {
                    $totalCogs = $qty * $price;
                    if (isset($product['is_serialized']) && $product['is_serialized'] == 1) {
                        $serials = json_decode($_POST['serials'] ?? '[]', true);
                        if (is_array($serials)) {
                            $stmtSerial = $this->pdo->prepare("INSERT INTO product_serials (company_id, product_id, serial_number, buy_price, status, purchase_transaction_id) VALUES (?, ?, ?, ?, 'In Stock', ?)");
                            foreach ($serials as $serial) {
                                $stmtSerial->execute([$this->companyId, $pid, $serial, $price, $transId]);
                            }
                        }
                    } else {
                        $stmtBatch = $this->pdo->prepare("INSERT INTO product_batches (company_id, product_id, quantity_initial, quantity_remaining, buy_price, purchase_transaction_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmtBatch->execute([$this->companyId, $pid, $qty, $qty, $price, $transId]);
                    }
                } else if ($type === 'sale') {
                    if (isset($product['is_serialized']) && $product['is_serialized'] == 1) {
                        $serials = json_decode($_POST['serials'] ?? '[]', true);
                        if (is_array($serials) && count($serials) > 0) {
                            $placeholders = str_repeat('?,', count($serials) - 1) . '?';
                            $stmtCost = $this->pdo->prepare("SELECT SUM(buy_price) FROM product_serials WHERE company_id = ? AND product_id = ? AND serial_number IN ($placeholders)");
                            $stmtCost->execute(array_merge([$this->companyId, $pid], $serials));
                            $totalCogs = (float)$stmtCost->fetchColumn();

                            $stmtSerialUpdate = $this->pdo->prepare("UPDATE product_serials SET status = 'Sold', sale_transaction_id = ? WHERE company_id = ? AND product_id = ? AND serial_number IN ($placeholders)");
                            $params = array_merge([$transId, $this->companyId, $pid], $serials);
                            $stmtSerialUpdate->execute($params);
                        }
                    } else {
                        $qtyToDeduct = $qty;
                        $stmtBatches = $this->pdo->prepare("SELECT id, quantity_remaining, buy_price FROM product_batches WHERE company_id = ? AND product_id = ? AND quantity_remaining > 0 ORDER BY id ASC");
                        $stmtBatches->execute([$this->companyId, $pid]);
                        $batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($batches as $batch) {
                            if ($qtyToDeduct <= 0) break;
                            if ($batch['quantity_remaining'] >= $qtyToDeduct) {
                                $totalCogs += ($qtyToDeduct * $batch['buy_price']);
                                $updateBatch = $this->pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?");
                                $updateBatch->execute([$qtyToDeduct, $batch['id']]);

                                $this->pdo->prepare("INSERT INTO transaction_batch_history (transaction_id, batch_id, quantity_deducted) VALUES (?, ?, ?)")
                                          ->execute([$transId, $batch['id'], $qtyToDeduct]);
                                $qtyToDeduct = 0;
                            } else {
                                $totalCogs += ($batch['quantity_remaining'] * $batch['buy_price']);
                                $this->pdo->prepare("INSERT INTO transaction_batch_history (transaction_id, batch_id, quantity_deducted) VALUES (?, ?, ?)")
                                          ->execute([$transId, $batch['id'], $batch['quantity_remaining']]);
                                
                                $qtyToDeduct -= $batch['quantity_remaining'];
                                $updateBatch = $this->pdo->prepare("UPDATE product_batches SET quantity_remaining = 0 WHERE id = ?");
                                $updateBatch->execute([$batch['id']]);
                            }
                        }
                    }
                }
            }

            $this->pdo->prepare("UPDATE transactions SET cogs = ? WHERE id = ?")->execute([$totalCogs, $transId]);

            if (!$isService) {
                if ($type === 'purchase') {
                    $updateProdStmt = $this->pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, buy_price = ? WHERE id = ? AND company_id = ?");
                    $updateProdStmt->execute([$qty, $price, $pid, $this->companyId]);

                    $newTotal = (int)$product['stock_quantity'] + (int)$qty;
                    if ($newTotal > 10) {
                        $unrequestStmt = $this->pdo->prepare("UPDATE products SET is_requested = '0' WHERE id = ?");
                        $unrequestStmt->execute([$pid]);
                    }
                } else {
                    $updateProdStmt = $this->pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND company_id = ?");
                    $updateProdStmt->execute([$qty, $pid, $this->companyId]);

                    $stmtCheckStock = $this->pdo->prepare("SELECT stock_quantity, name FROM products WHERE id = ?");
                    $stmtCheckStock->execute([$pid]);
                    $prodCheck = $stmtCheckStock->fetch(PDO::FETCH_ASSOC);
            
                    if ($prodCheck && $prodCheck['stock_quantity'] <= 5) {
                        $stockQty = (int)$prodCheck['stock_quantity'];
                        $notifType = ($stockQty === 0) ? 'out_of_stock' : 'low_stock';
                        $msg = ($stockQty === 0) ? "Out of Stock Alert: {$prodCheck['name']} has completely run out!" : "Low Stock Alert: Only {$stockQty} left of {$prodCheck['name']}!";
                        
                        $stmtCheckNotif = $this->pdo->prepare("SELECT id FROM notifications WHERE company_id = ? AND reference_id = ? AND is_read = 0 AND type = ?");
                        $stmtCheckNotif->execute([$this->companyId, $pid, $notifType]);
                        
                        if (!$stmtCheckNotif->fetch()) {
                            $notifStmt = $this->pdo->prepare("INSERT INTO notifications (company_id, type, message, action_type, reference_id, target_role) VALUES (?, ?, ?, 'open_restock', ?, 'Admin')");
                            $notifStmt->execute([$this->companyId, $notifType, $msg, $pid]);
                        }
                    }
                }
            }
            
            $this->pdo->commit();
            
            $actionTitle = ($type === 'sale') ? "New Sale" : "New Purchase";
            $this->logAudit($actionTitle, "Recorded $type: {$product['name']} (Qty: $qty). Status: $paymentStatus");
            echo json_encode(['status' => 'success']);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Database error: Transaction safely aborted. ' . $e->getMessage()]);
        }
    }


    public function getInvoiceProducts() {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                t.id, 
                t.quantity, 
                t.price_at_time, 
                p.name as product_name,
                (SELECT GROUP_CONCAT(serial_number SEPARATOR ', ') 
                 FROM product_serials 
                 WHERE sale_transaction_id = t.id) as serials
            FROM transactions t 
            JOIN products p ON t.product_id = p.id 
            WHERE t.company_id = ? AND t.invoice_id = ?
        ");
        
        $stmt->execute([$this->companyId, $invoiceId]);
        
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function getPaymentHistory() {
        $transactionId = (int)$_GET['transaction_id']; 
        
        $sql = "SELECT p.*, u.name as user_name, c.contact_code -- <-- NEW: Fetch the code
                FROM payment_installments p 
                LEFT JOIN users u ON p.user_id = u.id 
                LEFT JOIN invoices i ON p.transaction_id = i.id -- <-- NEW: Connect to invoice
                LEFT JOIN contacts c ON i.contact_id = c.id -- <-- NEW: Connect to contacts
                WHERE p.transaction_id = ? AND p.company_id = ? 
                ORDER BY p.payment_date DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$transactionId, $this->companyId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $history]);
    }

    public function getAllPaymentHistory() {
        $type = $_GET['type'] ?? 'sale'; 
        
        $sql = "SELECT p.*, i.client_name, i.receipt_number, u.name as user_name, 
                       c.contact_code, -- <-- NEW: Fetch the code
                       (SELECT GROUP_CONCAT(pr.name SEPARATOR ', ') 
                        FROM transactions t 
                        JOIN products pr ON t.product_id = pr.id 
                        WHERE t.invoice_id = i.id) as product_name
                FROM payment_installments p 
                JOIN invoices i ON p.transaction_id = i.id 
                LEFT JOIN users u ON p.user_id = u.id 
                LEFT JOIN contacts c ON i.contact_id = c.id -- <-- NEW: Join contacts table
                WHERE p.company_id = ? AND i.type = ? 
                ORDER BY p.payment_date DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->companyId, $type]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $history]);
    }

    public function update() {
        if ($this->userRole === 'Cashier') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Cashiers cannot modify history.']);
            return;
        }

        $id = (int)$_POST['id']; // Now sending the INVOICE ID
        
        $contactId = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;

        $clientName = $_POST['client_name'] ?? '';
        $clientPhone = $_POST['client_phone'] ?? '';
        $paymentMethodUsed = $_POST['payment_method_used'] ?? null;
        if ($paymentMethodUsed === '') $paymentMethodUsed = null;
        $deadlineDate = $_POST['deadline_date'] ?? null;
        if ($deadlineDate === '') $deadlineDate = null;
        $ebmNumber = $_POST['ebm_number'] ?? null;
        if ($ebmNumber === '') $ebmNumber = null;

        // REFINED: Targets the 'invoices' table since parent data moved there
        $sql = "SELECT * FROM invoices WHERE id = ? AND company_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id, $this->companyId]);
        $oldTrans = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$oldTrans) { 
            echo json_encode(['status'=>'error', 'message'=>'Invoice not found']); 
            return; 
        }

        $this->pdo->beginTransaction();

        try {
            $sql = "UPDATE invoices 
                    SET contact_id = ?, client_name = ?, client_phone = ?, payment_method_used = ?, deadline_date = ?, ebm_number = ? 
                    WHERE id = ? AND company_id = ?";
            
            $updateStmt = $this->pdo->prepare($sql);
            $updateStmt->execute([
                $contactId,
                $clientName, 
                $clientPhone, 
                $paymentMethodUsed,
                $deadlineDate, 
                $ebmNumber, 
                $id, 
                $this->companyId
            ]);

            $this->logAudit("Update Invoice", "Updated details for Invoice #" . $oldTrans['receipt_number']);
            
            $this->pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Invoice details updated successfully.']);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function destroy() {
        if ($this->userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admins can delete history.']);
            return;
        }

        $invoiceId = (int)$_POST['id']; // This is now the INVOICE ID!
        
        // 1. Fetch the parent invoice first
        $stmtInv = $this->pdo->prepare("SELECT receipt_number, type FROM invoices WHERE id = ? AND company_id = ?");
        $stmtInv->execute([$invoiceId, $this->companyId]);
        $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            echo json_encode(['status' => 'error', 'message' => 'Invoice not found.']);
            return;
        }

        // 2. Fetch ALL child transactions inside this invoice
        $sql = "SELECT t.*, p.name as product_name, p.is_serialized, p.buy_price, p.item_type 
                FROM transactions t JOIN products p ON t.product_id = p.id WHERE t.invoice_id = ? AND t.company_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$invoiceId, $this->companyId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // =========================================================================
        // --- 3. ENTERPRISE SAFETY CHECK: Check ALL items BEFORE touching the DB --
        // =========================================================================
        foreach ($transactions as $t) {
            $isService = (isset($t['item_type']) && $t['item_type'] === 'service');
            
            if (!$isService && $t['type'] === 'purchase') {
                if ($t['is_serialized'] == 1) {
                    $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE purchase_transaction_id = ? AND company_id = ? AND status != 'In Stock'");
                    $stmtCheck->execute([$t['id'], $this->companyId]);
                    if ($stmtCheck->fetchColumn() > 0) {
                        echo json_encode(['status' => 'error', 'message' => "Cannot delete invoice. Serialized items from '{$t['product_name']}' have already been sold."]);
                        return;
                    }
                } else {
                    $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM product_batches WHERE purchase_transaction_id = ? AND company_id = ? AND quantity_remaining < quantity_initial");
                    $stmtCheck->execute([$t['id'], $this->companyId]);
                    if ($stmtCheck->fetchColumn() > 0) {
                        echo json_encode(['status' => 'error', 'message' => "Cannot delete invoice. Bulk items from '{$t['product_name']}' have already been sold or used."]);
                        return;
                    }
                }
            }
        }

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            // Wipe installments mapped to this invoice
            $this->pdo->prepare("DELETE FROM payment_installments WHERE transaction_id = ? AND company_id = ?")->execute([$invoiceId, $this->companyId]);

            // ==============================================================
            // --- 4. REVERT EVERY INDIVIDUAL ITEM ---
            // ==============================================================
            foreach ($transactions as $t) {
                $isService = (isset($t['item_type']) && $t['item_type'] === 'service');

                if (!$isService) {
                    // --- HANDLE SERIAL NUMBERS & BATCHES ---
                    if ($t['is_serialized'] == 1) {
                        if ($t['type'] === 'sale') {
                            // Put serials back on the shelf
                            $this->pdo->prepare("UPDATE product_serials SET status = 'In Stock', sale_transaction_id = NULL WHERE sale_transaction_id = ? AND company_id = ?")->execute([$t['id'], $this->companyId]);
                        } else if ($t['type'] === 'purchase') {
                            // Wipe the serials out of the system
                            $this->pdo->prepare("DELETE FROM product_serials WHERE purchase_transaction_id = ? AND company_id = ?")->execute([$t['id'], $this->companyId]);
                        }
                    } else {
                        // Bulk Items
                        if ($t['type'] === 'purchase') {
                            // Wipe the batch
                            $this->pdo->prepare("DELETE FROM product_batches WHERE purchase_transaction_id = ? AND company_id = ?")->execute([$t['id'], $this->companyId]);
                        } else if ($t['type'] === 'sale') {
                            
                            // 1. Look up exactly which batches were touched
                            $stmtHistory = $this->pdo->prepare("SELECT batch_id, quantity_deducted FROM transaction_batch_history WHERE transaction_id = ?");
                            $stmtHistory->execute([$t['id']]);
                            $historyRecords = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($historyRecords) > 0) {
                                // 2. Put the exact amounts back into their exact batches.
                                foreach ($historyRecords as $record) {
                                    $this->pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ? AND company_id = ?")
                                              ->execute([$record['quantity_deducted'], $record['batch_id'], $this->companyId]);
                                }
                                // 3. Clear the ledger receipt
                                $this->pdo->prepare("DELETE FROM transaction_batch_history WHERE transaction_id = ?")->execute([$t['id']]);
                                
                            } else {
                                // Fallback logic
                                $stmtLastBatch = $this->pdo->prepare("SELECT id FROM product_batches WHERE company_id = ? AND product_id = ? ORDER BY id DESC LIMIT 1");
                                $stmtLastBatch->execute([$this->companyId, $t['product_id']]);
                                $lastBatch = $stmtLastBatch->fetch(PDO::FETCH_ASSOC);
                                
                                if ($lastBatch) {
                                    $this->pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining + ? WHERE id = ?")->execute([$t['quantity'], $lastBatch['id']]);
                                } else {
                                    $this->pdo->prepare("INSERT INTO product_batches (company_id, product_id, quantity_initial, quantity_remaining, buy_price) VALUES (?, ?, ?, ?, ?)")->execute([$this->companyId, $t['product_id'], $t['quantity'], $t['quantity'], $t['price_at_time']]);
                                }
                            }
                        }
                    }

                    // --- UPDATE GENERAL INVENTORY ---
                    $op = ($t['type'] == 'sale') ? '+' : '-';
                    $this->pdo->query("UPDATE products SET stock_quantity = stock_quantity $op {$t['quantity']} WHERE id = {$t['product_id']} AND company_id = {$this->companyId}");
                }
            }

            // ==============================================================
            // --- 5. DELETE CHILDREN, THEN THE PARENT INVOICE ---
            // ==============================================================
            $this->pdo->prepare("DELETE FROM transactions WHERE invoice_id = ? AND company_id = ?")->execute([$invoiceId, $this->companyId]);
            $this->pdo->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?")->execute([$invoiceId, $this->companyId]);
            
            $this->logAudit("Delete " . ucfirst($invoice['type']), "Deleted Invoice #{$invoice['receipt_number']} and successfully reverted all associated items and stock.");
            
            // --- COMMIT ---
            $this->pdo->commit();
            echo json_encode(['status' => 'success']);

        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Database error: Deletion safely aborted to protect inventory integrity.']);
        }
    }

    public function payDebt() {
        if ($this->userRole === 'Cashier') {
            echo json_encode(['status' => 'error', 'message' => 'Cashiers cannot clear debts. Ask an Admin.']);
            return;
        }

        $id = (int)$_POST['id']; // INVOICE ID
        $amountPaying = (float)($_POST['amount_paying'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? 'Cash';
        
        if ($amountPaying <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
            return;
        }
        
        $stmt = $this->pdo->prepare("SELECT total_amount, amount_paid, balance_due, client_name, receipt_number, payment_method_used FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $this->companyId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if($inv) {
            $totalAmount = (float)$inv['total_amount'];
            $previouslyPaid = (float)$inv['amount_paid'];
            $remainingBalance = (float)$inv['balance_due'];
            
            // --- REFINEMENT 1: Safe floating-point rounding to 2 decimals ---
            $amountPaying = round($amountPaying, 2);
            $remainingBalance = round($remainingBalance, 2);
            
            if ($amountPaying > $remainingBalance) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => "Payment exceeds the remaining balance! The customer only owes Rwf " . number_format($remainingBalance)
                ]);
                return;
            }
            
            $newTotalPaid = round($previouslyPaid + $amountPaying, 2);
            $newBalance = round($remainingBalance - $amountPaying, 2);
            
            // --- REFINEMENT 2: Smart Payment Method Tracking ---
            // If they already paid some money using a different method, label the invoice as 'Multiple'
            $newMethod = $inv['payment_method_used'];
            if ($previouslyPaid > 0 && $newMethod !== $paymentMethod) {
                $newMethod = 'Multiple';
            } else if ($previouslyPaid == 0) {
                $newMethod = $paymentMethod;
            }
            
            if ($newBalance <= 0) { 
                $newStatus = 'paid';
                $sql = "UPDATE invoices SET payment_status = ?, amount_paid = ?, balance_due = ?, deadline_date = NULL, payment_method_used = ? WHERE id = ? AND company_id = ?";
            } else {
                $newStatus = 'partial';
                $sql = "UPDATE invoices SET payment_status = ?, amount_paid = ?, balance_due = ?, payment_method_used = ? WHERE id = ? AND company_id = ?";
            }
            
            $this->pdo->beginTransaction();

            try {
                $update = $this->pdo->prepare($sql);
                // Use $newMethod instead of $paymentMethod for the invoice master record
                if(!$update->execute([$newStatus, $newTotalPaid, $newBalance, $newMethod, $id, $this->companyId])) {
                    throw new Exception("Failed to update invoice status.");
                }
                    
                // The installments table still accurately tracks the EXACT method used for this specific fraction of the payment
                $stmtInstallment = $this->pdo->prepare("INSERT INTO payment_installments (company_id, transaction_id, user_id, amount_paid, payment_method, payment_date) VALUES (?, ?, ?, ?, ?, NOW())");
                if (!$stmtInstallment->execute([$this->companyId, $id, $this->userId, $amountPaying, $paymentMethod])) {
                    throw new Exception("Failed to log payment installment.");
                }

                $this->logAudit("Debt Payment", "Recorded Rwf " . number_format($amountPaying) . " towards Invoice #{$inv['receipt_number']} ({$inv['client_name']}). Status is now: $newStatus");
                
                $this->pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully!']);
                return;

            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                echo json_encode(['status' => 'error', 'message' => 'Database error: Payment processing safely aborted.']);
                return;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Failed to locate invoice.']);
    }

    private function logAudit($action, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->companyId, $this->userId, $action, $description]);
    }
}