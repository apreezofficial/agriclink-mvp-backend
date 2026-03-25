<?php
/**
 * AgriMarket API - Wallet Endpoints
 * 
 * Handles all wallet-related operations:
 * - Get wallet balance and details
 * - Transaction history
 * - Add bank account
 * - Withdraw funds
 * - Top up wallet (simulated payment)
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/wallet/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'transactions') {
                getTransactions();
            } elseif ($uriParts[0] === 'bank-accounts') {
                getBankAccounts();
            } else {
                getWallet();
            }
        } else {
            getWallet();
        }
        break;
    case 'POST':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'topup') {
                topUp();
            } elseif ($uriParts[0] === 'withdraw') {
                withdraw();
            } elseif ($uriParts[0] === 'bank-accounts') {
                addBankAccount();
            } else {
                errorResponse('Endpoint not found', 404);
            }
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'PUT':
        if (isset($uriParts[0]) && $uriParts[0] === 'bank-accounts' && isset($uriParts[1])) {
            updateBankAccount($uriParts[1]);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'DELETE':
        if (isset($uriParts[0]) && $uriParts[0] === 'bank-accounts' && isset($uriParts[1])) {
            deleteBankAccount($uriParts[1]);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/wallet
 * Get current user's wallet details
 */
function getWallet() {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    // Get wallet
    $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    $wallet = $stmt->fetch();
    
    if (!$wallet) {
        // Create wallet if doesn't exist
        $stmt = $db->prepare('INSERT INTO wallets (user_id) VALUES (?)');
        $stmt->execute([$payload['user_id']]);
        
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
    }
    
    // Get pending orders for this farmer
    if ($payload['role'] === 'farmer') {
        $stmt = $db->prepare("
            SELECT SUM(total_amount) as pending 
            FROM orders 
            WHERE farmer_id = ? AND escrow_status = 'held'
        ");
        $stmt->execute([$payload['user_id']]);
        $pending = $stmt->fetch();
        $wallet['pending_in_escrow'] = (float)($pending['pending'] ?? 0);
    }
    
    // Get recent transactions
    $stmt = $db->prepare('
        SELECT * FROM transactions 
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ');
    $stmt->execute([$payload['user_id']]);
    $wallet['recent_transactions'] = $stmt->fetchAll();
    
    // Format dates
    foreach ($wallet['recent_transactions'] as &$tx) {
        $tx['created_at'] = date('c', strtotime($tx['created_at']));
    }
    
    $wallet['created_at'] = date('c', strtotime($wallet['created_at']));
    $wallet['updated_at'] = date('c', strtotime($wallet['updated_at']));
    
    successResponse('Wallet retrieved', $wallet);
}

/**
 * GET /api/wallet/transactions
 * Get wallet transaction history
 */
function getTransactions() {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $type = $_GET['type'] ?? null;
    $status = $_GET['status'] ?? null;
    
    // Build query
    $where = 't.user_id = ?';
    $params = [$payload['user_id']];
    
    if ($type) {
        $where .= ' AND t.type = ?';
        $params[] = $type;
    }
    
    if ($status) {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM transactions t WHERE $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get transactions
    $sql = "
        SELECT t.*, o.crop_name
        FROM transactions t
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE $where
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
    
    // Format dates
    foreach ($transactions as &$tx) {
        $tx['created_at'] = date('c', strtotime($tx['created_at']));
        $tx['updated_at'] = date('c', strtotime($tx['updated_at']));
    }
    
    // Get summary stats
    $stmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN type = 'escrow_release' AND status = 'completed' THEN amount ELSE 0 END) as total_earned,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawn
        FROM transactions 
        WHERE user_id = ?
    ");
    $stmt->execute([$payload['user_id']]);
    $summary = $stmt->fetch();
    
    successResponse('Transactions retrieved', [
        'transactions' => $transactions,
        'summary' => [
            'total_earned' => (float)($summary['total_earned'] ?? 0),
            'total_withdrawn' => (float)($summary['total_withdrawn'] ?? 0)
        ],
        'pagination' => [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => (int)$total,
            'pages' => ceil($total / $pagination['per_page'])
        ]
    ]);
}

/**
 * GET /api/wallet/bank-accounts
 * Get user's bank accounts
 */
function getBankAccounts() {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    $stmt = $db->prepare('
        SELECT * FROM bank_accounts 
        WHERE user_id = ?
        ORDER BY is_default DESC, created_at DESC
    ');
    $stmt->execute([$payload['user_id']]);
    $accounts = $stmt->fetchAll();
    
    // Mask account numbers
    foreach ($accounts as &$account) {
        $account['account_number'] = substr($account['account_number'], 0, 4) . '****' . substr($account['account_number'], -4);
        $account['created_at'] = date('c', strtotime($account['created_at']));
    }
    
    successResponse('Bank accounts retrieved', $accounts);
}

/**
 * POST /api/wallet/bank-accounts
 * Add a new bank account
 */
function addBankAccount() {
    $payload = authenticateRequest();
    
    global $data;
    
    validateRequired(['bank_name', 'account_number', 'account_name'], $data);
    
    $bankName = sanitizeInput($data['bank_name']);
    $accountNumber = sanitizeInput($data['account_number']);
    $accountName = sanitizeInput($data['account_name']);
    $isDefault = $data['is_default'] ?? false;
    
    // Validate account number (should be 10 digits for Nigerian banks)
    if (!preg_match('/^\d{10}$/', $accountNumber)) {
        errorResponse('Account number must be 10 digits');
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // If this is set as default, unset other defaults
        if ($isDefault) {
            $stmt = $db->prepare('UPDATE bank_accounts SET is_default = FALSE WHERE user_id = ?');
            $stmt->execute([$payload['user_id']]);
        }
        
        // Check if this is the first account
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM bank_accounts WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $count = $stmt->fetch()['count'];
        
        // Insert bank account
        $stmt = $db->prepare('
            INSERT INTO bank_accounts (user_id, bank_name, account_number, account_name, is_default)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $payload['user_id'],
            $bankName,
            $accountNumber,
            $accountName,
            $isDefault || $count === 0
        ]);
        
        $accountId = $db->lastInsertId();
        
        // In production, verify bank account via API
        // This is a mock verification
        $stmt = $db->prepare('UPDATE bank_accounts SET is_verified = TRUE WHERE id = ?');
        $stmt->execute([$accountId]);
        
        // Log activity
        logActivity($payload['user_id'], 'bank_account_add', 'bank_account', $accountId);
        
        $db->commit();
        
        successResponse('Bank account added successfully', [
            'id' => $accountId,
            'bank_name' => $bankName,
            'account_name' => $accountName,
            'account_number' => substr($accountNumber, 0, 4) . '****' . substr($accountNumber, -4),
            'is_default' => $isDefault || $count === 0,
            'is_verified' => true
        ], 201);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to add bank account: ' . $e->getMessage(), 500);
    }
}

/**
 * PUT /api/wallet/bank-accounts/{id}
 * Update a bank account
 */
function updateBankAccount($id) {
    $payload = authenticateRequest();
    
    global $data;
    
    $db = Database::getInstance();
    
    // Check ownership
    $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $account = $stmt->fetch();
    
    if (!$account) {
        errorResponse('Bank account not found', 404);
    }
    
    // Build update query
    $allowedFields = ['bank_name', 'account_name', 'is_default'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }
    
    // Handle default setting
    if (isset($data['is_default']) && $data['is_default']) {
        $stmt = $db->prepare('UPDATE bank_accounts SET is_default = FALSE WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
    }
    
    if (!empty($updates)) {
        $params[] = $id;
        
        $stmt = $db->prepare('
            UPDATE bank_accounts SET ' . implode(', ', $updates) . '
            WHERE id = ?
        ');
        $stmt->execute($params);
    }
    
    // Log activity
    logActivity($payload['user_id'], 'bank_account_update', 'bank_account', $id);
    
    successResponse('Bank account updated successfully');
}

/**
 * DELETE /api/wallet/bank-accounts/{id}
 * Delete a bank account
 */
function deleteBankAccount($id) {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    // Check ownership
    $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $account = $stmt->fetch();
    
    if (!$account) {
        errorResponse('Bank account not found', 404);
    }
    
    try {
        $db->beginTransaction();
        
        // Delete account
        $stmt = $db->prepare('DELETE FROM bank_accounts WHERE id = ?');
        $stmt->execute([$id]);
        
        // If deleted account was default, set another as default
        if ($account['is_default']) {
            $stmt = $db->prepare('
                UPDATE bank_accounts SET is_default = TRUE 
                WHERE user_id = ? AND id = (
                    SELECT id FROM bank_accounts WHERE user_id = ? LIMIT 1
                )
            ');
            $stmt->execute([$payload['user_id'], $payload['user_id']]);
        }
        
        // Log activity
        logActivity($payload['user_id'], 'bank_account_delete', 'bank_account', $id);
        
        $db->commit();
        
        successResponse('Bank account deleted successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to delete bank account: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/wallet/topup
 * Top up wallet (simulated payment)
 */
function topUp() {
    $payload = requireRole(['buyer']);
    
    global $data;
    
    validateRequired(['amount'], $data);
    
    $amount = (float)$data['amount'];
    $paymentMethod = sanitizeInput($data['payment_method'] ?? 'card');
    $cardLast4 = sanitizeInput($data['card_last4'] ?? '****');
    
    if ($amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Generate reference
        $reference = 'TOP-' . strtoupper(bin2hex(random_bytes(8)));
        
        // Process payment via database
        // Simulates successful payment with reference
        
        // Update wallet balance
        $stmt = $db->prepare('
            UPDATE wallets 
            SET balance = balance + ?, total_spent = total_spent + ?
            WHERE user_id = ?
        ');
        $stmt->execute([$amount, $amount, $payload['user_id']]);
        
        // Create transaction record
        $stmt = $db->prepare('
            INSERT INTO transactions (user_id, amount, type, status, reference, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $payload['user_id'],
            $amount,
            'payment',
            'completed',
            $reference,
            "Wallet top-up via {$paymentMethod} (****{$cardLast4})"
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'wallet_topup', 'wallet', $payload['user_id'], [
            'amount' => $amount,
            'reference' => $reference
        ]);
        
        $db->commit();
        
        // Get updated wallet
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
        
        successResponse('Wallet topped up successfully', [
            'reference' => $reference,
            'amount' => $amount,
            'new_balance' => $wallet['balance']
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Top up failed: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/wallet/withdraw
 * Withdraw funds to bank account
 */
function withdraw() {
    $payload = requireRole(['farmer']);
    
    global $data;
    
    validateRequired(['amount', 'bank_account_id'], $data);
    
    $amount = (float)$data['amount'];
    $bankAccountId = (int)$data['bank_account_id'];
    
    if ($amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get wallet
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
        
        if (!$wallet || $wallet['balance'] < $amount) {
            errorResponse('Insufficient balance');
        }
        
        // Verify bank account
        $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$bankAccountId, $payload['user_id']]);
        $bankAccount = $stmt->fetch();
        
        if (!$bankAccount) {
            errorResponse('Bank account not found', 404);
        }
        
        if (!$bankAccount['is_verified']) {
            errorResponse('Bank account is not verified');
        }
        
        // Generate reference
        $reference = 'WD-' . strtoupper(bin2hex(random_bytes(8)));
        
        // Update wallet balance
        $stmt = $db->prepare('
            UPDATE wallets 
            SET balance = balance - ?
            WHERE user_id = ?
        ');
        $stmt->execute([$amount, $payload['user_id']]);
        
        // Create withdrawal record
        $stmt = $db->prepare('
            INSERT INTO withdrawals (user_id, wallet_id, amount, bank_account_id, status, reference)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $payload['user_id'],
            $wallet['id'],
            $amount,
            $bankAccountId,
            'pending',
            $reference
        ]);
        
        // Create transaction record
        $stmt = $db->prepare('
            INSERT INTO transactions (user_id, amount, type, status, reference, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $payload['user_id'],
            $amount,
            'withdrawal',
            'pending',
            $reference,
            "Withdrawal to {$bankAccount['bank_name']} ({$bankAccount['account_name']})"
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'wallet_withdraw', 'wallet', $wallet['id'], [
            'amount' => $amount,
            'reference' => $reference,
            'bank_account_id' => $bankAccountId
        ]);
        
        $db->commit();
        
        // Get updated wallet
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
        
        successResponse('Withdrawal initiated successfully', [
            'reference' => $reference,
            'amount' => $amount,
            'new_balance' => $wallet['balance'],
            'bank_account' => [
                'bank_name' => $bankAccount['bank_name'],
                'account_name' => $bankAccount['account_name'],
                'account_number' => substr($bankAccount['account_number'], 0, 4) . '****' . substr($bankAccount['account_number'], -4)
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Withdrawal failed: ' . $e->getMessage(), 500);
    }
}

/**
 * Log user activity
 */
function logActivity($userId, $action, $entityType = null, $entityId = null, $details = null) {
    $db = Database::getInstance();
    
    $stmt = $db->prepare('
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}
