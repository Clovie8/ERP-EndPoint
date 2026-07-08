<?php
class ProductController {
    private $pdo;
    private $companyId;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        if (!isset($_SESSION['company_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $this->companyId = (int)$_SESSION['company_id'];
    }

    public function index() {
        $search = $_GET['search'] ?? '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        $statusFilter = $_GET['status'] ?? null;

        $sql = "SELECT * FROM products WHERE company_id = ?";
        if ($statusFilter == 'Active') {
            $sql .= " AND status = 'Active'";
        }
        $sql .= " AND (name LIKE ? OR sku LIKE ?) ORDER BY id DESC LIMIT ? OFFSET ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(2, "%$search%", PDO::PARAM_STR);
        $stmt->bindValue(3, "%$search%", PDO::PARAM_STR);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->bindValue(5, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSql = "SELECT COUNT(*) FROM products WHERE company_id = ?";
        if ($statusFilter == 'Active') {
            $totalSql .= " AND status = 'Active'";
        }
        $totalSql .= " AND (name LIKE ? OR sku LIKE ?)";
        $stmtTot = $this->pdo->prepare($totalSql);
        $stmtTot->execute([$this->companyId, "%$search%", "%$search%"]);
        $total = $stmtTot->fetchColumn();

        echo json_encode(['status' => 'success', 'data' => $products, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function store() {
        // --- NEW: THE INVENTORY BYPASS CHECK ---
        $item_type = $_POST['item_type'] ?? 'product';
        
        if ($item_type === 'service') {
            // Force inventory rules to 0 for services to protect the ledger
            $_POST['buy_price'] = 0;
            $_POST['stock_quantity'] = 0;
            $_POST['is_serialized'] = 0;
        }
        // ---------------------------------------

        $name = $_POST['name'];
        $buy = $_POST['buy_price'];
        $sell = $_POST['sell_price'];
        $qty = $_POST['stock_quantity'];
        
        $is_serialized = isset($_POST['is_serialized']) ? (int)$_POST['is_serialized'] : 0;
        $sku = 'PROD-' . strtoupper(substr(md5(time()), 0, 6));
        $serials = json_decode($_POST['serials'] ?? '[]', true);
        
        // --- PHASE 3: DATABASE DUPLICATE CHECK BEFORE INSERTION ---
        if ($qty > 0 && $is_serialized == 1 && is_array($serials) && count($serials) > 0) {
            $placeholders = str_repeat('?,', count($serials) - 1) . '?';
            $stmtCheck = $this->pdo->prepare("SELECT serial_number FROM product_serials WHERE company_id = ? AND serial_number IN ($placeholders)");
            $checkParams = array_merge([$this->companyId], $serials);
            $stmtCheck->execute($checkParams);
            $dups = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($dups) > 0) {
                echo json_encode(['status' => 'error', 'message' => 'The following serial numbers are already registered in the system: ' . implode(', ', $dups)]);
                return;
            }
        }
        // ---------------------------------------------------------
        
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $imagePath = $this->uploadImage($_FILES['image']);
        }

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            // NEW: Added item_type to the INSERT statement
            $stmt = $this->pdo->prepare("INSERT INTO products (company_id, name, sku, buy_price, sell_price, stock_quantity, image, is_serialized, item_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!$stmt->execute([$this->companyId, $name, $sku, $buy, $sell, $qty, $imagePath, $is_serialized, $item_type])) {
                throw new Exception("Failed to insert product.");
            }
            
            $newProductId = $this->pdo->lastInsertId();
            
            if ($qty > 0) {
                $userId = $_SESSION['user_id'] ?? 1;
                $currentDate = date('Y-m-d H:i:s');
                $lineTotal = $qty * $buy;
                
                // 1. Create a System Invoice for the Initial Stock
                $receiptNo = 'INIT-' . strtoupper(substr(md5(time() . $name), 0, 6));
                
                $insertInv = $this->pdo->prepare("INSERT INTO invoices (company_id, user_id, receipt_number, client_name, total_amount, amount_paid, balance_due, payment_status, type, created_at) VALUES (?, ?, ?, 'Initial Stock', ?, ?, 0, 'paid', 'purchase', ?)");
                $insertInv->execute([$this->companyId, $userId, $receiptNo, $lineTotal, $lineTotal, $currentDate]);
                
                $invoiceId = $this->pdo->lastInsertId();
                
                // 2. Insert into the cleaned-up transactions table using the new invoice_id
                $sqlTrans = "INSERT INTO transactions (invoice_id, company_id, product_id, type, quantity, price_at_time, date, cogs) VALUES (?, ?, ?, 'purchase', ?, ?, ?, 0)";
                $stmtTrans = $this->pdo->prepare($sqlTrans);
                $stmtTrans->execute([$invoiceId, $this->companyId, $newProductId, $qty, $buy, $currentDate]);
                $transId = $this->pdo->lastInsertId();

                if ($is_serialized == 1) {
                    if (is_array($serials)) {
                        $stmtSerial = $this->pdo->prepare("INSERT INTO product_serials (company_id, product_id, serial_number, buy_price, status, purchase_transaction_id) VALUES (?, ?, ?, ?, 'In Stock', ?)");
                        foreach ($serials as $serial) {
                            $stmtSerial->execute([$this->companyId, $newProductId, $serial, $buy, $transId]);
                        }
                    }
                } else {
                    $stmtBatch = $this->pdo->prepare("INSERT INTO product_batches (company_id, product_id, quantity_initial, quantity_remaining, buy_price, purchase_transaction_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtBatch->execute([$this->companyId, $newProductId, $qty, $qty, $buy, $transId]);
                }
            }

            $this->logAudit('Create Product', "Added new $item_type: $name");
            
            // --- COMMIT ---
            $this->pdo->commit();
            echo json_encode(['status' => 'success']);

        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Optional: Delete the uploaded image if DB fails
            if ($imagePath && file_exists('../public/' . $imagePath)) unlink('../public/' . $imagePath);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add product safely.']);
        }
    }

    public function update() {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $buy = $_POST['buy_price'] ?? 0;
        $sell = $_POST['sell_price'];
        
        $status = $_POST['status'] ?? 'Active'; 
        $is_serialized = isset($_POST['is_serialized']) ? (int)$_POST['is_serialized'] : 0;

        // FIXED: Removed "AND status = 'Active'" so you can fetch and edit Archived products
        $stmtOld = $this->pdo->prepare("SELECT * FROM products WHERE id = ? AND company_id = ?");
        $stmtOld->execute([$id, $this->companyId]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$old) { echo json_encode(['status' => 'error', 'message' => 'Product not found or unauthorized']); return; }

        // ==========================================
        // --- NEW: SERVICE BYPASS ENFORCEMENT ---
        // ==========================================
        if (isset($old['item_type']) && $old['item_type'] === 'service') {
            $buy = 0;
            $is_serialized = 0;
        }
        // ==========================================

        // --- THE SMART BLOCK FOR DISABLING SERIALIZATION ---
        if ($old['is_serialized'] == 1 && $is_serialized == 0) {
            $stmtSoldSerials = $this->pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE product_id = ? AND company_id = ? AND status = 'Sold'");
            $stmtSoldSerials->execute([$id, $this->companyId]);
            
            if ($stmtSoldSerials->fetchColumn() > 0) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'PROTECTION ALERT: You cannot disable Serial/IMEI tracking for this product because some of its serial numbers have already been sold. Disabling it would corrupt warranty and receipt records.'
                ]);
                return;
            }
        }

        // --- CHECK DUPLICATES IF MIGRATING TO SERIALIZED ---
        if ($old['is_serialized'] == 0 && $is_serialized == 1 && $old['stock_quantity'] > 0) {
            $serials = json_decode($_POST['serials'] ?? '[]', true);
            if (is_array($serials) && count($serials) > 0) {
                $placeholders = str_repeat('?,', count($serials) - 1) . '?';
                $stmtCheck = $this->pdo->prepare("SELECT serial_number FROM product_serials WHERE company_id = ? AND serial_number IN ($placeholders)");
                $checkParams = array_merge([$this->companyId], $serials);
                $stmtCheck->execute($checkParams);
                $dups = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
                
                if (count($dups) > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'The following serial numbers are already registered in the system: ' . implode(', ', $dups)]);
                    return;
                }
            }
        }

        $imagePath = $old['image']; 
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            if ($old['image'] && file_exists('../public/' . $old['image'])) unlink('../public/' . $old['image']);
            $imagePath = $this->uploadImage($_FILES['image']);
        }

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("UPDATE products SET name=?, buy_price=?, sell_price=?, image=?, is_serialized=?, status=? WHERE id=? AND company_id=?");
            if (!$stmt->execute([$name, $buy, $sell, $imagePath, $is_serialized, $status, $id, $this->companyId])) {
                throw new Exception("Update failed.");
            }
            
            // IF MIGRATING TO SERIALIZED (Turned ON)
            if ($old['is_serialized'] == 0 && $is_serialized == 1 && $old['stock_quantity'] > 0) {
                $serials = json_decode($_POST['serials'] ?? '[]', true);
                if (is_array($serials) && count($serials) > 0) {
                    $stmtSerial = $this->pdo->prepare("INSERT INTO product_serials (company_id, product_id, serial_number, buy_price, status) VALUES (?, ?, ?, ?, 'In Stock')");
                    foreach ($serials as $serial) {
                        $stmtSerial->execute([$this->companyId, $id, $serial, $old['buy_price']]);
                    }
                }
            }
            // --- DELETE SERIALS IF TURNING OFF ---
            else if ($old['is_serialized'] == 1 && $is_serialized == 0) {
                $stmtDel = $this->pdo->prepare("DELETE FROM product_serials WHERE product_id = ? AND company_id = ?");
                $stmtDel->execute([$id, $this->companyId]);
                $this->logAudit('Serialization Disabled', "Deleted unused tracked serials for product: $name");
            }

            $this->logAudit('Update Product', "Updated details for $name (Status: $status)");
            
            // --- COMMIT ---
            $this->pdo->commit();
            echo json_encode(['status' => 'success']);

        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error']);
        }
    }


    public function destroy() {
        $userRole = $_SESSION['role'] ?? 'Cashier';
        if ($userRole !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admins can delete records.']);
            return;
        }

        $id = $_POST['id'];

        // 1. Check if the product has ever been in a 'sale' transaction
        $stmtTrans = $this->pdo->prepare("SELECT COUNT(*) FROM transactions WHERE product_id = ? AND company_id = ? AND type = 'sale'");
        $stmtTrans->execute([$id, $this->companyId]);
        $hasSales = $stmtTrans->fetchColumn() > 0;

        // 2. Check if any of its serial numbers have a 'Sold' status
        $stmtSerials = $this->pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE product_id = ? AND company_id = ? AND status = 'Sold'");
        $stmtSerials->execute([$id, $this->companyId]);
        $hasSoldSerials = $stmtSerials->fetchColumn() > 0;

        // --- START VAULT ---
        $this->pdo->beginTransaction();

        try {
            // 3. AUTO-ARCHIVE (If sales exist, we hide it instead of deleting to protect the DB)
            if ($hasSales || $hasSoldSerials) {
                $this->pdo->prepare("UPDATE products SET status = 'Archived' WHERE id = ? AND company_id = ?")->execute([$id, $this->companyId]);
                $this->logAudit('Archive Product', "Archived product #$id instead of deleting to protect sales history.");
                
                $this->pdo->commit();
                echo json_encode([
                    'status' => 'success', 
                    'action' => 'archived', 
                    'message' => 'Because this product has already been sold, it was safely ARCHIVED instead of deleted. It is now hidden from the POS to protect your historical receipts and warranty records.'
                ]);
                return;
            }

            // --- SAFE DELETION LOGIC (Only runs if the item was never sold) ---
            $stmtName = $this->pdo->prepare("SELECT name, image FROM products WHERE id = ? AND company_id = ?");
            $stmtName->execute([$id, $this->companyId]);
            $prod = $stmtName->fetch();
            
            if ($prod) {
                // CLEANUP: Delete any unused "In Stock" serials and initial "Purchase" transactions first!
                $this->pdo->prepare("DELETE FROM product_serials WHERE product_id = ? AND company_id = ?")->execute([$id, $this->companyId]);
                $this->pdo->prepare("DELETE FROM transactions WHERE product_id = ? AND company_id = ?")->execute([$id, $this->companyId]);

                // Finally, delete the product itself
                $stmt = $this->pdo->prepare("DELETE FROM products WHERE id=? AND company_id=?");
                if (!$stmt->execute([$id, $this->companyId])) {
                    throw new Exception("Deletion failed.");
                }

                $this->logAudit('Delete Product', "Deleted product: {$prod['name']} (including initial purchase records).");
                
                if ($prod['image'] && file_exists('../public/' . $prod['image'])) {
                    unlink('../public/' . $prod['image']);
                }

                $this->pdo->commit();
                echo json_encode(['status' => 'success', 'action' => 'deleted', 'message' => 'Product permanently deleted.']);
            } else {
                throw new Exception("Product not found.");
            }

        } catch (Exception $e) {
            // --- ROLLBACK ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete safely.']);
        }
    }

    public function bulkImport() {
        $userRole = $_SESSION['role'] ?? 'Cashier';
        if ($userRole === 'Cashier') {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied: Only Admins can perform bulk imports.']);
            return;
        }

        $payload = file_get_contents("php://input");
        $data = json_decode($payload, true);

        if (!$data || !isset($data['products']) || !is_array($data['products'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data format received.']);
            return;
        }

        $successCount = 0;
        $skuChangedCount = 0;
        $companyId = (int)$_SESSION['company_id'];
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $currentDate = date('Y-m-d H:i:s'); 

        // --- START VAULT FOR THE ENTIRE LOOP ---
        $this->pdo->beginTransaction();

        try {
            foreach ($data['products'] as $row) {
                $name = trim($row['name'] ?? '');
                $sku = trim($row['sku'] ?? '');
                $buyPrice = (float)($row['buy_price'] ?? 0);
                $sellPrice = (float)($row['sell_price'] ?? 0);
                $stockQty = (int)($row['stock_quantity'] ?? 0);
                
                $isSerialized = (isset($row['is_serialized']) && ($row['is_serialized'] == 1 || strtolower($row['is_serialized']) === 'yes')) ? 1 : 0;

                if ($isSerialized == 1) {
                    $stockQty = 0;
                }

                if (empty($name) || empty($sku)) continue; 

                $stmt = $this->pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $stmt->execute([$sku]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $sku = 'PROD-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
                    $skuChangedCount++;
                }

                $insertStmt = $this->pdo->prepare("INSERT INTO products (company_id, name, sku, buy_price, sell_price, stock_quantity, is_serialized) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$insertStmt->execute([$companyId, $name, $sku, $buyPrice, $sellPrice, $stockQty, $isSerialized])) {
                    throw new Exception("Bulk import failed on product: $name");
                }
                
                $newProductId = $this->pdo->lastInsertId();
                $successCount++;
                
                if ($stockQty > 0) {
                    $lineTotal = $stockQty * $buyPrice;
                    
                    // 1. Create the System Invoice for the Bulk Uploaded Stock
                    $receiptNo = 'BULK-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                    
                    $insertInv = $this->pdo->prepare("INSERT INTO invoices (company_id, user_id, receipt_number, client_name, total_amount, amount_paid, balance_due, payment_status, type, created_at) VALUES (?, ?, ?, 'Bulk Import System', ?, ?, 0, 'paid', 'purchase', ?)");
                    $insertInv->execute([$companyId, $userId, $receiptNo, $lineTotal, $lineTotal, $currentDate]);
                    $invoiceId = $this->pdo->lastInsertId();

                    // 2. Insert into the cleaned-up transactions table using the new invoice_id
                    $sqlTrans = "INSERT INTO transactions (invoice_id, company_id, product_id, type, quantity, price_at_time, date, cogs) VALUES (?, ?, ?, 'purchase', ?, ?, ?, 0)";
                    $stmtTrans = $this->pdo->prepare($sqlTrans);
                    $stmtTrans->execute([$invoiceId, $companyId, $newProductId, $stockQty, $buyPrice, $currentDate]);
                    $transId = $this->pdo->lastInsertId();
                    
                    // 3. Keep the batch tracking exactly the same
                    $stmtBatch = $this->pdo->prepare("INSERT INTO product_batches (company_id, product_id, quantity_initial, quantity_remaining, buy_price, purchase_transaction_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtBatch->execute([$companyId, $newProductId, $stockQty, $stockQty, $buyPrice, $transId]);
                }
            }

            $message = "Successfully imported $successCount products.";
            if ($skuChangedCount > 0) {
                $message .= " Note: $skuChangedCount SKUs were automatically regenerated to prevent duplication.";
            }

            $logStmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$companyId, $userId, "Bulk Import", $message]);

            // --- COMMIT ---
            $this->pdo->commit();

            echo json_encode([
                'status' => 'success', 
                'message' => $message
            ]);

        } catch (Exception $e) {
            // --- ROLLBACK: Erases the entire loop if any single item fails! ---
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => 'Bulk Import aborted due to a database error. No products were imported.']);
        }
    }

    private function uploadImage($file) {
        $targetDir = "../public/uploads/products/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $fileName = time() . '_' . basename($file["name"]);
        $targetFilePath = $targetDir . $fileName;
        $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
        if(in_array($fileType, ['jpg','png','jpeg','gif', 'webp'])) {
            if(move_uploaded_file($file["tmp_name"], $targetFilePath)) return "uploads/products/" . $fileName; 
        }
        return null;
    }

    public function toggleRequest() {
        $id = $_POST['id'];
        $status = (int)$_POST['status'];
        $stmt = $this->pdo->prepare("UPDATE products SET is_requested = ? WHERE id = ? AND company_id = ?");
        if ($stmt->execute([$status, $id, $this->companyId])) {
            echo json_encode(['status' => 'success', 'message' => $status ? "Product requested successfully" : "Product un-requested"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    }

    private function logAudit($action, $description) {
        if(isset($_SESSION['user_id'])) {
            $stmt = $this->pdo->prepare("INSERT INTO audit_logs (company_id, user_id, action, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$this->companyId, $_SESSION['user_id'], $action, $description]);
        }
    }

    
    public function getSerialLifecycle() {
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? ''; 
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // 1. SECURE PARAMETER BINDING
        $where = "WHERE s.company_id = ?";
        $params = [$this->companyId];
        
        if (!empty($search)) {
            // REFINED: Changed ts.client_name to inv.client_name
            $where .= " AND (s.serial_number LIKE ? OR p.name LIKE ? OR inv.client_name LIKE ?)";
            $searchTerm = "%$search%";
            array_push($params, $searchTerm, $searchTerm, $searchTerm);
        }
        
        if (!empty($status) && in_array($status, ['In_Stock', 'Sold'])) {
            $dbStatus = ($status === 'In_Stock') ? 'In Stock' : 'Sold';
            
            $where .= " AND s.status = ?";
            $params[] = $dbStatus;
        }

        // 2. THE MAIN DATA QUERY
        $sql = "SELECT s.*, p.name as product_name, p.sku,
               pinv.receipt_number as purchase_receipt, tp.date as date_added,
               inv.receipt_number as sale_receipt, ts.date as date_sold, 
               inv.client_name 
        FROM product_serials s
        JOIN products p ON s.product_id = p.id
        LEFT JOIN transactions tp ON s.purchase_transaction_id = tp.id
        LEFT JOIN transactions ts ON s.sale_transaction_id = ts.id
        LEFT JOIN invoices inv ON ts.invoice_id = inv.id
        LEFT JOIN invoices pinv ON tp.invoice_id = pinv.id 
        $where 
        ORDER BY s.id DESC LIMIT $limit OFFSET $offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. REFINED COUNT QUERY (Mirrors the JOINs so the WHERE clause doesn't crash)
        $totalSql = "SELECT COUNT(*) 
                     FROM product_serials s 
                     JOIN products p ON s.product_id = p.id 
                     LEFT JOIN transactions ts ON s.sale_transaction_id = ts.id
                     LEFT JOIN invoices inv ON ts.invoice_id = inv.id 
                     $where";
                     
        $stmtTotal = $this->pdo->prepare($totalSql);
        $stmtTotal->execute($params); // Securely re-use the exact same parameters!
        $total = $stmtTotal->fetchColumn();

        echo json_encode(['status' => 'success', 'data' => $data, 'total' => $total, 'page' => $page]);
    }
}