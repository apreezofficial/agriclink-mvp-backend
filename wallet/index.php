<?php
/**
 * AgriMarket API - Wallet Router
 * 
 * Routes: GET /api/wallet, GET /api/wallet/transactions, POST /api/wallet/topup, etc.
 */

// Include CORS headers FIRST
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Parse the URI
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/wallet/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$id = is_numeric($uriParts[1] ?? '') ? (int)$uriParts[1] : null;

switch ($method) {
    case 'GET':
        if ($action === 'transactions') {
            getTransactions();
        } elseif ($id && $action === 'accounts') {
            getBankAccount($id);
        } elseif ($action === 'accounts') {
            getBankAccounts();
        } else {
            getWallet();
        }
        break;
    case 'POST':
        if ($action === 'topup') {
            topUp();
        } elseif ($action === 'withdraw') {
            withdraw();
        } elseif ($action === 'accounts') {
            addBankAccount();
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'PUT':
        if ($action === 'accounts' && $id) {
            updateBankAccount($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'DELETE':
        if ($action === 'accounts' && $id) {
            deleteBankAccount($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/wallet
 */
function getWallet() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    $wallet = $stmt->fetch();
    
    if (!$wallet) {
        $stmt = $db->prepare('INSERT INTO wallets (user_id) VALUES (?)');
        $stmt->execute([$payload['user_id']]);
        
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
    }
    
    if ($payload['role'] === 'farmer') {
        $stmt = $db->prepare("SELECT SUM(total_amount) as pending FROM orders WHERE farmer_id = ? AND escrow_status = 'held'");
        $stmt->execute([$payload['user_id']]);
        $pending = $stmt->fetch();
        $wallet['pending_in_escrow'] = (float)($pending['pending'] ?? 0);
    }
    
    $stmt = $db->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$payload['user_id']]);
    $wallet['recent_transactions'] = $stmt->fetchAll();
    
    foreach ($wallet['recent_transactions'] as &$tx) {
        $tx['created_at'] = date('c', strtotime($tx['created_at']));
    }
    
    $wallet['created_at'] = date('c', strtotime($wallet['created_at']));
    $wallet['updated_at'] = date('c', strtotime($wallet['updated_at']));
    
    successResponse('Wallet retrieved', $wallet);
}

/**
 * GET /api/wallet/transactions
 */
function getTransactions() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $type = $_GET['type'] ?? null;
    $status = $_GET['status'] ?? null;
    
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
    
    $countSql = "SELECT COUNT(*) as total FROM transactions t WHERE $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT t.*, o.crop_name FROM transactions t LEFT JOIN orders o ON t.order_id = o.id WHERE $where ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
    
    foreach ($transactions as &$tx) {
        $tx['created_at'] = date('c', strtotime($tx['created_at']));
        $tx['updated_at'] = date('c', strtotime($tx['updated_at']));
    }
    
    $stmt = $db->prepare("SELECT SUM(CASE WHEN type = 'escrow_release' AND status = 'completed' THEN amount ELSE 0 END) as total_earned, SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawn FROM transactions WHERE user_id = ?");
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
 */
function getBankAccounts() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE user_id = ? ORDER BY is_default DESC, created_at DESC');
    $stmt->execute([$payload['user_id']]);
    $accounts = $stmt->fetchAll();
    
    foreach ($accounts as &$account) {
        $account['account_number'] = substr($account['account_number'], 0, 4) . '****' . substr($account['account_number'], -4);
        $account['created_at'] = date('c', strtotime($account['created_at']));
    }
    
    successResponse('Bank accounts retrieved', $accounts);
}

/**
 * POST /api/wallet/bank-accounts
 */
function addBankAccount() {
    $payload = authenticateRequest();
    global $data;
    
    validateRequired(['bank_name', 'account_number', 'account_name'], $data);
    
    $bankName = sanitizeInput($data['bank_name']);
    $accountNumber = sanitizeInput($data['account_number']);
    $accountName = sanitizeInput($data['account_name']);
    $isDefault = $data['is_default'] ?? false;
    
    if (!preg_match('/^\d{10}$/', $accountNumber)) {
        errorResponse('Account number must be 10 digits');
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        if ($isDefault) {
            $stmt = $db->prepare('UPDATE bank_accounts SET is_default = FALSE WHERE user_id = ?');
            $stmt->execute([$payload['user_id']]);
        }
        
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM bank_accounts WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $count = $stmt->fetch()['count'];
        
        $stmt = $db->prepare('INSERT INTO bank_accounts (user_id, bank_name, account_number, account_name, is_default) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$payload['user_id'], $bankName, $accountNumber, $accountName, $isDefault || $count === 0]);
        
        $accountId = $db->lastInsertId();
        
        $stmt = $db->prepare('UPDATE bank_accounts SET is_verified = TRUE WHERE id = ?');
        $stmt->execute([$accountId]);
        
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
 */
function updateBankAccount($id) {
    $payload = authenticateRequest();
    global $data;
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $account = $stmt->fetch();
    
    if (!$account) {
        errorResponse('Bank account not found', 404);
    }
    
    $allowedFields = ['bank_name', 'account_name', 'is_default'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }
    
    if (isset($data['is_default']) && $data['is_default']) {
        $stmt = $db->prepare('UPDATE bank_accounts SET is_default = FALSE WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
    }
    
    if (!empty($updates)) {
        $params[] = $id;
        $stmt = $db->prepare('UPDATE bank_accounts SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
    }
    
    logActivity($payload['user_id'], 'bank_account_update', 'bank_account', $id);
    successResponse('Bank account updated successfully');
}

/**
 * DELETE /api/wallet/bank-accounts/{id}
 */
function deleteBankAccount($id) {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $account = $stmt->fetch();
    
    if (!$account) {
        errorResponse('Bank account not found', 404);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('DELETE FROM bank_accounts WHERE id = ?');
        $stmt->execute([$id]);
        
        if ($account['is_default']) {
            $stmt = $db->prepare('UPDATE bank_accounts SET is_default = TRUE WHERE user_id = ? AND id = (SELECT id FROM bank_accounts WHERE user_id = ? LIMIT 1)');
            $stmt->execute([$payload['user_id'], $payload['user_id']]);
        }
        
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
        
        $reference = 'TOP-' . strtoupper(bin2hex(random_bytes(8)));
        
        $stmt = $db->prepare('UPDATE wallets SET balance = balance + ?, total_spent = total_spent + ? WHERE user_id = ?');
        $stmt->execute([$amount, $amount, $payload['user_id']]);
        
        $stmt = $db->prepare('INSERT INTO transactions (user_id, amount, type, status, reference, description) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$payload['user_id'], $amount, 'payment', 'completed', $reference, "Wallet top-up via {$paymentMethod} (****{$cardLast4})"]);
        
        logActivity($payload['user_id'], 'wallet_topup', 'wallet', $payload['user_id'], ['amount' => $amount, 'reference' => $reference]);
        
        $db->commit();
        
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
        
        $stmt = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$bankAccountId, $payload['user_id']]);
        $account = $stmt->fetch();
        
        if (!$account) {
            errorResponse('Bank account not found', 404);
        }
        
        $stmt = $db->prepare('SELECT * FROM wallets WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        $wallet = $stmt->fetch();
        
        if ($wallet['balance'] < $amount) {
            errorResponse('Insufficient balance');
        }
        
        $reference = 'WTH-' . strtoupper(bin2hex(random_bytes(8)));
        
        $stmt = $db->prepare('UPDATE wallets SET balance = balance - ?, total_withdrawn = total_withdrawn + ? WHERE user_id = ?');
        $stmt->execute([$amount, $amount, $payload['user_id']]);
        
        $stmt = $db->prepare('INSERT INTO transactions (user_id, amount, type, status, reference, description) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$payload['user_id'], $amount, 'withdrawal', 'pending', $reference, "Withdrawal to {$account['bank_name']} (****{$account['account_number']})"]);
        
        logActivity($payload['user_id'], 'wallet_withdraw', 'wallet', $payload['user_id'], ['amount' => $amount, 'reference' => $reference]);
        
        $db->commit();
        
        successResponse('Withdrawal initiated successfully', [
            'reference' => $reference,
            'amount' => $amount,
            'status' => 'pending'
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Withdrawal failed: ' . $e->getMessage(), 500);
    }
}
