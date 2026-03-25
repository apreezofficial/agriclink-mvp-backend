<?php
/**
 * AgriMarket API - Admin Endpoints
 * 
 * Handles all admin-related operations:
 * - User management (view, suspend, verify)
 * - Listing moderation (approve, suspend)
 * - Transaction management (view, release escrow, refund)
 * - Dashboard statistics
 * - Platform settings
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/admin/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'dashboard') {
                getDashboard();
            } elseif ($uriParts[0] === 'users' && isset($uriParts[1]) && is_numeric($uriParts[1])) {
                getUser($uriParts[1]);
            } elseif ($uriParts[0] === 'users') {
                getUsers();
            } elseif ($uriParts[0] === 'listings') {
                getAllListings();
            } elseif ($uriParts[0] === 'transactions') {
                getAllTransactions();
            } elseif ($uriParts[0] === 'settings') {
                getSettings();
            } else {
                getDashboard();
            }
        } else {
            getDashboard();
        }
        break;
    case 'POST':
        if ($uriParts[0] === 'users' && isset($uriParts[1])) {
            if ($uriParts[1] === 'suspend') {
                suspendUser();
            } elseif ($uriParts[1] === 'activate') {
                activateUser();
            } elseif ($uriParts[1] === 'verify') {
                verifyUser();
            }
        } elseif ($uriParts[0] === 'listings' && isset($uriParts[1])) {
            if ($uriParts[1] === 'approve') {
                approveListing();
            } elseif ($uriParts[1] === 'suspend') {
                suspendListing();
            }
        } elseif ($uriParts[0] === 'transactions' && isset($uriParts[1])) {
            if ($uriParts[1] === 'release-escrow') {
                releaseEscrow();
            } elseif ($uriParts[1] === 'refund') {
                refundTransaction();
            }
        } elseif ($uriParts[0] === 'settings') {
            updateSettings();
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/admin/dashboard
 * Get platform dashboard statistics
 */
function getDashboard() {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    // Get user counts
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN role = 'farmer' THEN 1 ELSE 0 END) as total_farmers,
            SUM(CASE WHEN role = 'buyer' THEN 1 ELSE 0 END) as total_buyers,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as suspended_users
        FROM users
    ");
    $users = $stmt->fetch();
    
    // Get listing counts
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_listings,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_listings,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_listings,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_listings
        FROM listings
    ");
    $listings = $stmt->fetch();
    
    // Get order counts and revenue
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            SUM(total_amount) as total_revenue,
            SUM(service_fee) as total_fees
        FROM orders
    ");
    $orders = $stmt->fetch();
    
    // Get escrow stats
    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN escrow_status = 'held' THEN total_amount ELSE 0 END) as held_in_escrow,
            SUM(CASE WHEN escrow_status = 'released' THEN total_amount ELSE 0 END) as released,
            SUM(CASE WHEN escrow_status = 'refunded' THEN total_amount ELSE 0 END) as refunded
        FROM orders
    ");
    $escrow = $stmt->fetch();
    
    // Get recent users
    $stmt = $db->query("
        SELECT id, name, email, role, location, created_at
        FROM users
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentUsers = $stmt->fetchAll();
    foreach ($recentUsers as &$user) {
        $user['created_at'] = date('c', strtotime($user['created_at']));
    }
    
    // Get recent orders
    $stmt = $db->query("
        SELECT o.*, 
               l.crop_name,
               buyer.name as buyer_name,
               farmer.name as farmer_name
        FROM orders o
        LEFT JOIN listings l ON o.listing_id = l.id
        LEFT JOIN users buyer ON o.buyer_id = buyer.id
        LEFT JOIN users farmer ON o.farmer_id = farmer.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $recentOrders = $stmt->fetchAll();
    foreach ($recentOrders as &$order) {
        $order['created_at'] = date('c', strtotime($order['created_at']));
    }
    
    // Get top crops
    $stmt = $db->query("
        SELECT crop_name, COUNT(*) as count, SUM(quantity) as total_quantity
        FROM listings
        WHERE status = 'active'
        GROUP BY crop_name
        ORDER BY count DESC
        LIMIT 5
    ");
    $topCrops = $stmt->fetchAll();
    
    // Get top farmers
    $stmt = $db->query("
        SELECT u.id, u.name, u.location, COUNT(l.id) as listings_count,
               COALESCE(SUM(l.quantity * l.price), 0) as total_sales
        FROM users u
        LEFT JOIN listings l ON u.id = l.farmer_id AND l.status = 'sold'
        WHERE u.role = 'farmer'
        GROUP BY u.id
        ORDER BY total_sales DESC
        LIMIT 5
    ");
    $topFarmers = $stmt->fetchAll();
    
    successResponse('Dashboard data retrieved', [
        'users' => [
            'total' => (int)$users['total_users'],
            'farmers' => (int)$users['total_farmers'],
            'buyers' => (int)$users['total_buyers'],
            'suspended' => (int)$users['suspended_users']
        ],
        'listings' => [
            'total' => (int)$listings['total_listings'],
            'active' => (int)$listings['active_listings'],
            'suspended' => (int)$listings['suspended_listings'],
            'sold' => (int)$listings['sold_listings']
        ],
        'orders' => [
            'total' => (int)$orders['total_orders'],
            'pending' => (int)$orders['pending_orders'],
            'accepted' => (int)$orders['accepted_orders'],
            'completed' => (int)$orders['completed_orders'],
            'cancelled' => (int)$orders['cancelled_orders'],
            'total_revenue' => (float)$orders['total_revenue'],
            'total_fees' => (float)$orders['total_fees']
        ],
        'escrow' => [
            'held' => (float)$escrow['held_in_escrow'],
            'released' => (float)$escrow['released'],
            'refunded' => (float)$escrow['refunded']
        ],
        'recent_users' => $recentUsers,
        'recent_orders' => $recentOrders,
        'top_crops' => $topCrops,
        'top_farmers' => $topFarmers
    ]);
}

/**
 * GET /api/admin/users
 * Get all users with filters and pagination
 */
function getUsers() {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $search = $_GET['search'] ?? null;
    $role = $_GET['role'] ?? null;
    $status = $_GET['status'] ?? null;
    $isVerified = $_GET['is_verified'] ?? null;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($search) {
        $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($role) {
        $where[] = 'u.role = ?';
        $params[] = $role;
    }
    
    if ($status !== null) {
        $where[] = 'u.is_active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }
    
    if ($isVerified !== null) {
        $where[] = 'u.is_verified = ?';
        $params[] = $isVerified === 'true' ? 1 : 0;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM users u WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get users with stats
    $sql = "
        SELECT u.*,
               (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count,
               (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) as orders_as_buyer,
               (SELECT COUNT(*) FROM orders WHERE farmer_id = u.id) as orders_as_farmer,
               (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_earnings
        FROM users u
        WHERE $whereClause
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    // Format dates
    foreach ($users as &$user) {
        $user['created_at'] = date('c', strtotime($user['created_at']));
        $user['updated_at'] = date('c', strtotime($user['updated_at']));
    }
    
    successResponse('Users retrieved', [
        'users' => $users,
        'pagination' => [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => (int)$total,
            'pages' => ceil($total / $pagination['per_page'])
        ]
    ]);
}

/**
 * GET /api/admin/users/{id}
 * Get single user with detailed info
 */
function getUser($id) {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    $stmt = $db->prepare("
        SELECT u.*,
               (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count,
               (SELECT COUNT(*) FROM orders WHERE buyer_id = u.id) as orders_as_buyer,
               (SELECT COUNT(*) FROM orders WHERE farmer_id = u.id) as orders_as_farmer,
               (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_earnings,
               (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE buyer_id = u.id AND status = 'completed') as total_spent
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        errorResponse('User not found', 404);
    }
    
    $user['created_at'] = date('c', strtotime($user['created_at']));
    $user['updated_at'] = date('c', strtotime($user['updated_at']));
    
    // Get recent orders
    $stmt = $db->prepare("
        SELECT o.*, l.crop_name
        FROM orders o
        LEFT JOIN listings l ON o.listing_id = l.id
        WHERE o.buyer_id = ? OR o.farmer_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$id, $id]);
    $user['recent_orders'] = $stmt->fetchAll();
    
    // Get recent listings
    $stmt = $db->prepare("
        SELECT * FROM listings
        WHERE farmer_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $user['recent_listings'] = $stmt->fetchAll();
    
    // Get activity log
    $stmt = $db->prepare("
        SELECT * FROM activity_logs
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$id]);
    $user['activity_log'] = $stmt->fetchAll();
    
    successResponse('User retrieved', $user);
}

/**
 * POST /api/admin/users/suspend
 * Suspend a user
 */
function suspendUser() {
    requireRole(['admin']);
    
    global $data;
    
    validateRequired(['user_id'], $data);
    
    $userId = (int)$data['user_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Account suspended by admin');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Check if user exists
        $stmt = $db->prepare('SELECT id, name, email, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            errorResponse('User not found', 404);
        }
        
        // Don't allow suspending other admins
        if ($user['role'] === 'admin') {
            errorResponse('Cannot suspend admin users');
        }
        
        // Suspend user
        $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $stmt->execute([$userId]);
        
        // Log activity
        logActivity(null, 'user_suspend', 'user', $userId, [
            'reason' => $reason
        ]);
        
        $db->commit();
        
        successResponse('User suspended successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to suspend user: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/admin/users/activate
 * Reactivate a suspended user
 */
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
        
        // Log activity
        logActivity(null, 'user_activate', 'user', $userId);
        
        $db->commit();
        
        successResponse('User activated successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to activate user: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/admin/users/verify
 * Verify a user's account
 */
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
        
        // Create notification
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            'Account Verified',
            'Your account has been verified by the admin.',
            'system'
        ]);
        
        // Log activity
        logActivity(null, 'user_verify', 'user', $userId);
        
        $db->commit();
        
        successResponse('User verified successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to verify user: ' . $e->getMessage(), 500);
    }
}

/**
 * GET /api/admin/listings
 * Get all listings with moderation controls
 */
function getAllListings() {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $search = $_GET['search'] ?? null;
    $status = $_GET['status'] ?? null;
    $farmerId = $_GET['farmer_id'] ?? null;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($search) {
        $where[] = '(l.crop_name LIKE ? OR u.name LIKE ?)';
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($status) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }
    
    if ($farmerId) {
        $where[] = 'l.farmer_id = ?';
        $params[] = $farmerId;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM listings l JOIN users u ON l.farmer_id = u.id WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get listings
    $sql = "
        SELECT l.*, u.name as farmer_name, u.email as farmer_email
        FROM listings l
        JOIN users u ON l.farmer_id = u.id
        WHERE $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();
    
    // Format dates
    foreach ($listings as &$listing) {
        $listing['created_at'] = date('c', strtotime($listing['created_at']));
        $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
    }
    
    successResponse('Listings retrieved', [
        'listings' => $listings,
        'pagination' => [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => (int)$total,
            'pages' => ceil($total / $pagination['per_page'])
        ]
    ]);
}

/**
 * POST /api/admin/listings/approve
 * Approve a listing
 */
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
        
        // Get farmer ID for notification
        $stmt = $db->prepare('SELECT farmer_id FROM listings WHERE id = ?');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        
        // Create notification
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $listing['farmer_id'],
            'Listing Approved',
            'Your listing has been approved and is now visible in the marketplace.',
            'listing'
        ]);
        
        // Log activity
        logActivity(null, 'listing_approve', 'listing', $listingId);
        
        $db->commit();
        
        successResponse('Listing approved successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to approve listing: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/admin/listings/suspend
 * Suspend a listing
 */
function suspendListing() {
    requireRole(['admin']);
    
    global $data;
    
    validateRequired(['listing_id'], $data);
    
    $listingId = (int)$data['listing_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Listing suspended by admin');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('UPDATE listings SET status = ? WHERE id = ?');
        $stmt->execute(['suspended', $listingId]);
        
        // Get farmer ID for notification
        $stmt = $db->prepare('SELECT farmer_id FROM listings WHERE id = ?');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        
        // Create notification
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $listing['farmer_id'],
            'Listing Suspended',
            "Your listing has been suspended. Reason: $reason",
            'listing'
        ]);
        
        // Log activity
        logActivity(null, 'listing_suspend', 'listing', $listingId, [
            'reason' => $reason
        ]);
        
        $db->commit();
        
        successResponse('Listing suspended successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to suspend listing: ' . $e->getMessage(), 500);
    }
}

/**
 * GET /api/admin/transactions
 * Get all platform transactions
 */
function getAllTransactions() {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $search = $_GET['search'] ?? null;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($status) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    
    if ($type) {
        $where[] = 't.type = ?';
        $params[] = $type;
    }
    
    if ($search) {
        $where[] = '(t.reference LIKE ? OR u.name LIKE ?)';
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM transactions t JOIN users u ON t.user_id = u.id WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get transactions
    $sql = "
        SELECT t.*, u.name as user_name, u.email as user_email, o.crop_name
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN orders o ON t.order_id = o.id
        WHERE $whereClause
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
    }
    
    // Get summary
    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN type = 'payment' AND status = 'completed' THEN amount ELSE 0 END) as total_payments,
            SUM(CASE WHEN type = 'escrow_release' AND status = 'completed' THEN amount ELSE 0 END) as total_released,
            SUM(CASE WHEN type = 'refund' AND status = 'completed' THEN amount ELSE 0 END) as total_refunds,
            SUM(CASE WHEN type = 'service_fee' AND status = 'completed' THEN amount ELSE 0 END) as total_fees
        FROM transactions
    ");
    $summary = $stmt->fetch();
    
    successResponse('Transactions retrieved', [
        'transactions' => $transactions,
        'summary' => [
            'total_payments' => (float)$summary['total_payments'],
            'total_released' => (float)$summary['total_released'],
            'total_refunds' => (float)$summary['total_refunds'],
            'total_fees' => (float)$summary['total_fees']
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
 * POST /api/admin/transactions/release-escrow
 * Manually release escrow (admin override)
 */
function releaseEscrow() {
    requireRole(['admin']);
    
    global $data;
    
    validateRequired(['order_id'], $data);
    
    $orderId = (int)$data['order_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Released by admin');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get order
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found', 404);
        }
        
        if ($order['escrow_status'] !== 'held') {
            errorResponse('Escrow is not currently held for this order');
        }
        
        // Calculate farmer earnings
        $farmerEarnings = $order['total_amount'] - $order['service_fee'];
        
        // Update order
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['completed', 'released', $orderId]);
        
        // Update wallet
        $stmt = $db->prepare('
            UPDATE wallets 
            SET balance = balance + ?,
                pending_balance = pending_balance - ?,
                total_earned = total_earned + ?
            WHERE user_id = ?
        ');
        $stmt->execute([
            $farmerEarnings,
            $order['total_amount'],
            $farmerEarnings,
            $order['farmer_id']
        ]);
        
        // Create transaction
        $ref = 'ADMREL-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $db->prepare('
            INSERT INTO transactions (order_id, user_id, amount, type, status, reference, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderId,
            $order['farmer_id'],
            $farmerEarnings,
            'escrow_release',
            'completed',
            $ref,
            "Admin release: $reason"
        ]);
        
        // Log activity
        logActivity(null, 'admin_escrow_release', 'order', $orderId, [
            'reason' => $reason,
            'amount' => $farmerEarnings
        ]);
        
        $db->commit();
        
        successResponse('Escrow released successfully', [
            'order_id' => $orderId,
            'released_amount' => $farmerEarnings
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to release escrow: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/admin/transactions/refund
 * Manually refund an order (admin override)
 */
function refundTransaction() {
    requireRole(['admin']);
    
    global $data;
    
    validateRequired(['order_id'], $data);
    
    $orderId = (int)$data['order_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Refunded by admin');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get order
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found', 404);
        }
        
        if ($order['escrow_status'] !== 'held') {
            errorResponse('Escrow cannot be refunded. Current status: ' . $order['escrow_status']);
        }
        
        // Update order
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['cancelled', 'refunded', $orderId]);
        
        // Create refund transaction
        $ref = 'ADMREF-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $db->prepare('
            INSERT INTO transactions (order_id, user_id, amount, type, status, reference, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderId,
            $order['buyer_id'],
            $order['total_amount'],
            'refund',
            'completed',
            $ref,
            "Admin refund: $reason"
        ]);
        
        // Log activity
        logActivity(null, 'admin_refund', 'order', $orderId, [
            'reason' => $reason,
            'amount' => $order['total_amount']
        ]);
        
        $db->commit();
        
        successResponse('Refund processed successfully', [
            'order_id' => $orderId,
            'refunded_amount' => $order['total_amount']
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to process refund: ' . $e->getMessage(), 500);
    }
}

/**
 * GET /api/admin/settings
 * Get platform settings
 */
function getSettings() {
    requireRole(['admin']);
    
    $db = Database::getInstance();
    
    // Get settings from database
    $stmt = $db->query("SELECT * FROM settings WHERE id = 1");
    $settingsRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $settings = $settingsRow ? json_decode($settingsRow['settings_json'], true) : [
        'platform_name' => 'AgriMarket',
        'platform_email' => 'support@agrimarket.com',
        'service_fee_percentage' => 2,
        'minimum_withdrawal' => 1000,
        'maximum_withdrawal' => 1000000,
        'allow_farmer_registration' => true,
        'allow_buyer_registration' => true,
        'require_email_verification' => false,
        'maintenance_mode' => false
    ];
    
    successResponse('Settings retrieved', $settings);
}

/**
 * POST /api/admin/settings
 * Update platform settings
 */
function updateSettings() {
    requireRole(['admin']);
    
    global $data;
    
    $db = Database::getInstance();
    
    // Validate and update settings
    $allowedSettings = [
        'platform_name', 'platform_email', 'service_fee_percentage',
        'minimum_withdrawal', 'maximum_withdrawal', 'allow_farmer_registration',
        'allow_buyer_registration', 'require_email_verification', 'maintenance_mode'
    ];
    
    // Get current settings
    $stmt = $db->prepare('SELECT settings_json FROM settings WHERE settings_key = ?');
    $stmt->execute(['platform_settings']);
    $row = $stmt->fetch();
    
    $currentSettings = $row ? json_decode($row['settings_json'], true) : [];
    $newSettings = array_merge($currentSettings, $data);
    
    // Validate settings
    if (isset($newSettings['service_fee_percentage']) && 
        ($newSettings['service_fee_percentage'] < 0 || $newSettings['service_fee_percentage'] > 100)) {
        errorResponse('Service fee must be between 0 and 100');
    }
    
    if (isset($newSettings['minimum_withdrawal']) && 
        isset($newSettings['maximum_withdrawal']) &&
        $newSettings['minimum_withdrawal'] > $newSettings['maximum_withdrawal']) {
        errorResponse('Minimum withdrawal cannot be greater than maximum');
    }
    
    try {
        $db->beginTransaction();
        
        // Upsert settings
        $stmt = $db->prepare('
            INSERT INTO settings (settings_key, settings_json)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE settings_json = ?
        ');
        $settingsJson = json_encode($newSettings);
        $stmt->execute(['platform_settings', $settingsJson, $settingsJson]);
        
        // Log activity
        logActivity(null, 'settings_update', 'settings', 1, $newSettings);
        
        $db->commit();
        
        successResponse('Settings updated successfully', $newSettings);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to update settings: ' . $e->getMessage(), 500);
    }
}

/**
 * Log activity (admin version)
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
