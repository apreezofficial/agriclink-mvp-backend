<?php
/**
 * AgriMarket API - Admin Router
 */

// Include CORS headers FIRST
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/admin/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$subAction = $uriParts[1] ?? '';

switch ($method) {
    case 'GET':
        if ($action === 'dashboard') getDashboard();
        elseif ($action === 'users' && isset($uriParts[1]) && is_numeric($uriParts[1])) getUser($uriParts[1]);
        elseif ($action === 'users') getUsers();
        elseif ($action === 'listings') getAllListings();
        elseif ($action === 'transactions') getAllTransactions();
        else getDashboard();
        break;
    case 'POST':
        if ($action === 'users') {
            if ($subAction === 'suspend') suspendUser();
            elseif ($subAction === 'activate') activateUser();
            elseif ($subAction === 'verify') verifyUser();
        } elseif ($action === 'listings') {
            if ($subAction === 'approve') approveListing();
            elseif ($subAction === 'suspend') suspendListing();
        } elseif ($action === 'transactions') {
            if ($subAction === 'release-escrow') releaseEscrow();
            elseif ($subAction === 'refund') refundTransaction();
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function getDashboard() {
    requireRole(['admin']);
    $db = Database::getInstance();

    $stmt = $db->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN role = 'farmer' THEN 1 ELSE 0 END) as total_farmers, SUM(CASE WHEN role = 'buyer' THEN 1 ELSE 0 END) as total_buyers, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as suspended_users FROM users");
    $users = $stmt->fetch();

    $stmt = $db->query("SELECT COUNT(*) as total_listings, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_listings, SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_listings, SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_listings FROM listings");
    $listings = $stmt->fetch();

    $stmt = $db->query("SELECT COUNT(*) as total_orders, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_orders, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders, SUM(total_amount) as total_revenue, SUM(service_fee) as total_fees FROM orders");
    $orders = $stmt->fetch();

    $stmt = $db->query("SELECT SUM(CASE WHEN escrow_status = 'held' THEN total_amount ELSE 0 END) as held_in_escrow, SUM(CASE WHEN escrow_status = 'released' THEN total_amount ELSE 0 END) as released, SUM(CASE WHEN escrow_status = 'refunded' THEN total_amount ELSE 0 END) as refunded FROM orders");
    $escrow = $stmt->fetch();

    $stmt = $db->query("SELECT id, name, email, role, location, created_at FROM users ORDER BY created_at DESC LIMIT 5");
    $recentUsers = $stmt->fetchAll();
    foreach ($recentUsers as &$u) $u['created_at'] = date('c', strtotime($u['created_at']));

    $stmt = $db->query("SELECT o.*, l.crop_name, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id ORDER BY o.created_at DESC LIMIT 5");
    $recentOrders = $stmt->fetchAll();
    foreach ($recentOrders as &$o) $o['created_at'] = date('c', strtotime($o['created_at']));

    $stmt = $db->query("SELECT crop_name, COUNT(*) as count, SUM(quantity) as total_quantity FROM listings WHERE status = 'active' GROUP BY crop_name ORDER BY count DESC LIMIT 5");
    $topCrops = $stmt->fetchAll();

    $stmt = $db->query("SELECT u.id, u.name, u.location, COUNT(l.id) as listings_count, COALESCE(SUM(l.quantity * l.price), 0) as total_sales FROM users u LEFT JOIN listings l ON u.id = l.farmer_id AND l.status = 'sold' WHERE u.role = 'farmer' GROUP BY u.id ORDER BY total_sales DESC LIMIT 5");
    $topFarmers = $stmt->fetchAll();

    successResponse('Dashboard data retrieved', [
        'users' => ['total' => (int)$users['total_users'], 'farmers' => (int)$users['total_farmers'], 'buyers' => (int)$users['total_buyers'], 'suspended' => (int)$users['suspended_users']],
        'listings' => ['total' => (int)$listings['total_listings'], 'active' => (int)$listings['active_listings'], 'suspended' => (int)$listings['suspended_listings'], 'sold' => (int)$listings['sold_listings']],
        'orders' => ['total' => (int)$orders['total_orders'], 'pending' => (int)$orders['pending_orders'], 'accepted' => (int)$orders['accepted_orders'], 'completed' => (int)$orders['completed_orders'], 'cancelled' => (int)$orders['cancelled_orders'], 'total_revenue' => (float)$orders['total_revenue'], 'total_fees' => (float)$orders['total_fees']],
        'escrow' => ['held' => (float)$escrow['held_in_escrow'], 'released' => (float)$escrow['released'], 'refunded' => (float)$escrow['refunded']],
        'recent_users' => $recentUsers,
        'recent_orders' => $recentOrders,
        'top_crops' => $topCrops,
        'top_farmers' => $topFarmers
    ]);
}

function getUsers() {
    requireRole(['admin']);
    $db = Database::getInstance();

    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $search = $_GET['search'] ?? null;
    $role = $_GET['role'] ?? null;
    $status = $_GET['status'] ?? null;

    $where = ['1=1'];
    $params = [];

    if ($search) { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $searchTerm = "%$search%"; $params[] = $searchTerm; $params[] = $searchTerm; }
    if ($role) { $where[] = 'u.role = ?'; $params[] = $role; }
    if ($status !== null) { $where[] = 'u.is_active = ?'; $params[] = $status === 'active' ? 1 : 0; }

    $whereClause = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as total FROM users u WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT u.*, (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count, (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) as orders_as_buyer, (SELECT COUNT(*) FROM orders WHERE farmer_id = u.id) as orders_as_farmer, (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_earnings FROM users u WHERE $whereClause ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    foreach ($users as &$u) { $u['created_at'] = date('c', strtotime($u['created_at'])); $u['updated_at'] = date('c', strtotime($u['updated_at'])); }

    successResponse('Users retrieved', ['users' => $users, 'pagination' => ['page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'total' => (int)$total, 'pages' => ceil($total / $pagination['per_page'])]]);
}

function getUser($id) {
    requireRole(['admin']);
    $db = Database::getInstance();

    $stmt = $db->prepare("SELECT u.*, (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count, (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) as orders_as_buyer, (SELECT COUNT(*) FROM orders WHERE farmer_id = u.id) as orders_as_farmer, (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_earnings, (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE buyer_id = u.id AND status = 'completed') as total_spent FROM users u WHERE u.id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) errorResponse('User not found', 404);
    $user['created_at'] = date('c', strtotime($user['created_at']));
    $user['updated_at'] = date('c', strtotime($user['updated_at']));

    $stmt = $db->prepare("SELECT o.*, l.crop_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id WHERE o.buyer_id = ? OR o.farmer_id = ? ORDER BY o.created_at DESC LIMIT 10");
    $stmt->execute([$id, $id]);
    $user['recent_orders'] = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM listings WHERE farmer_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$id]);
    $user['recent_listings'] = $stmt->fetchAll();

    successResponse('User retrieved', $user);
}

function getAllListings() {
    requireRole(['admin']);
    $db = Database::getInstance();

    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $status = $_GET['status'] ?? null;

    $where = '1=1';
    $params = [];
    if ($status) { $where .= ' AND l.status = ?'; $params[] = $status; }

    $countSql = "SELECT COUNT(*) as total FROM listings l WHERE $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT l.*, u.name as farmer_name FROM listings l JOIN users u ON l.farmer_id = u.id WHERE $where ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();

    foreach ($listings as &$l) { $l['created_at'] = date('c', strtotime($l['created_at'])); $l['updated_at'] = date('c', strtotime($l['updated_at'])); }

    successResponse('Listings retrieved', ['listings' => $listings, 'pagination' => ['page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'total' => (int)$total, 'pages' => ceil($total / $pagination['per_page'])]]);
}

function getAllTransactions() {
    requireRole(['admin']);
    $db = Database::getInstance();

    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);

    $countSql = "SELECT COUNT(*) as total FROM transactions";
    $stmt = $db->query($countSql);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT t.*, u.name as user_name, o.crop_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN orders o ON t.order_id = o.id ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$pagination['per_page'], $pagination['offset']]);
    $transactions = $stmt->fetchAll();

    foreach ($transactions as &$t) { $t['created_at'] = date('c', strtotime($t['created_at'])); $t['updated_at'] = date('c', strtotime($t['updated_at'])); }

    successResponse('Transactions retrieved', ['transactions' => $transactions, 'pagination' => ['page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'total' => (int)$total, 'pages' => ceil($total / $pagination['per_page'])]]);
}

function suspendUser() {
    requireRole(['admin']);
    global $data;
    validateRequired(['user_id'], $data);
    $userId = (int)$data['user_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Account suspended by admin');

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT id, name, email, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) errorResponse('User not found', 404);
        if ($user['role'] === 'admin') errorResponse('Cannot suspend admin users');

        $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $stmt->execute([$userId]);
        logActivity(null, 'user_suspend', 'user', $userId, ['reason' => $reason]);
        $db->commit();
        successResponse('User suspended successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to suspend user: ' . $e->getMessage(), 500); }
}

function activateUser() {
    requireRole(['admin']);
    global $data;
    validateRequired(['user_id'], $data);
    $userId = (int)$data['user_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
        $stmt->execute([$userId]);
        logActivity(null, 'user_activate', 'user', $userId);
        $db->commit();
        successResponse('User activated successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to activate user: ' . $e->getMessage(), 500); }
}

function verifyUser() {
    requireRole(['admin']);
    global $data;
    validateRequired(['user_id'], $data);
    $userId = (int)$data['user_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE users SET is_verified = 1 WHERE id = ?');
        $stmt->execute([$userId]);
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, 'Account Verified', 'Your account has been verified by the admin.', 'system']);
        logActivity(null, 'user_verify', 'user', $userId);
        $db->commit();
        successResponse('User verified successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to verify user: ' . $e->getMessage(), 500); }
}

function approveListing() {
    requireRole(['admin']);
    global $data;
    validateRequired(['listing_id'], $data);
    $listingId = (int)$data['listing_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE listings SET status = ? WHERE id = ?');
        $stmt->execute(['active', $listingId]);
        logActivity(null, 'listing_approve', 'listing', $listingId);
        $db->commit();
        successResponse('Listing approved successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to approve listing: ' . $e->getMessage(), 500); }
}

function suspendListing() {
    requireRole(['admin']);
    global $data;
    validateRequired(['listing_id'], $data);
    $listingId = (int)$data['listing_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE listings SET status = ? WHERE id = ?');
        $stmt->execute(['suspended', $listingId]);
        logActivity(null, 'listing_suspend', 'listing', $listingId);
        $db->commit();
        successResponse('Listing suspended successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to suspend listing: ' . $e->getMessage(), 500); }
}

function releaseEscrow() {
    requireRole(['admin']);
    global $data;
    validateRequired(['order_id'], $data);
    $orderId = (int)$data['order_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND escrow_status = ?');
        $stmt->execute([$orderId, 'held']);
        $order = $stmt->fetch();
        if (!$order) errorResponse('Order not found or escrow already released');

        $stmt = $db->prepare('UPDATE orders SET escrow_status = ? WHERE id = ?');
        $stmt->execute(['released', $orderId]);
        $stmt = $db->prepare('UPDATE wallets SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?');
        $stmt->execute([$order['total_amount'], $order['total_amount'], $order['farmer_id']]);
        logActivity(null, 'escrow_release', 'order', $orderId);
        $db->commit();
        successResponse('Escrow released successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to release escrow: ' . $e->getMessage(), 500); }
}

function refundTransaction() {
    requireRole(['admin']);
    global $data;
    validateRequired(['order_id'], $data);
    $orderId = (int)$data['order_id'];

    $db = Database::getInstance();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) errorResponse('Order not found', 404);

        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['cancelled', 'refunded', $orderId]);
        logActivity(null, 'order_refund', 'order', $orderId);
        $db->commit();
        successResponse('Order refunded successfully');
    } catch (Exception $e) { $db->rollBack(); errorResponse('Failed to refund: ' . $e->getMessage(), 500); }
}
