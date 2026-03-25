<?php
/**
 * AgriMarket API - Orders Endpoints
 * 
 * Handles all order-related operations:
 * - Create orders from listings
 * - Order status management (accept, reject, complete)
 * - Escrow payment handling
 * - Order history for buyers and farmers
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/orders/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            getOrder($uriParts[0]);
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'my') {
            getMyOrders();
        } else {
            getOrders();
        }
        break;
    case 'POST':
        if (isset($uriParts[0]) && $uriParts[0] === 'create') {
            createOrder();
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'accept') {
            acceptOrder();
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'reject') {
            rejectOrder();
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'confirm-delivery') {
            confirmDelivery();
        } else {
            createOrder();
        }
        break;
    case 'PUT':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            updateOrder($uriParts[0]);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/orders
 * Get all orders with filters
 */
function getOrders() {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $status = $_GET['status'] ?? null;
    $escrowStatus = $_GET['escrow_status'] ?? null;
    $buyerId = $_GET['buyer_id'] ?? null;
    $farmerId = $_GET['farmer_id'] ?? null;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    // Role-based filtering
    if ($payload['role'] === 'buyer') {
        $where[] = 'o.buyer_id = ?';
        $params[] = $payload['user_id'];
    } elseif ($payload['role'] === 'farmer') {
        $where[] = 'o.farmer_id = ?';
        $params[] = $payload['user_id'];
    } else {
        // Admin can filter by user
        if ($buyerId) {
            $where[] = 'o.buyer_id = ?';
            $params[] = $buyerId;
        }
        if ($farmerId) {
            $where[] = 'o.farmer_id = ?';
            $params[] = $farmerId;
        }
    }
    
    if ($status) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    
    if ($escrowStatus) {
        $where[] = 'o.escrow_status = ?';
        $params[] = $escrowStatus;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM orders o WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get orders
    $sql = "
        SELECT o.*, 
               l.crop_name, l.image_url,
               buyer.name as buyer_name,
               farmer.name as farmer_name
        FROM orders o
        LEFT JOIN listings l ON o.listing_id = l.id
        LEFT JOIN users buyer ON o.buyer_id = buyer.id
        LEFT JOIN users farmer ON o.farmer_id = farmer.id
        WHERE $whereClause
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    // Format dates
    foreach ($orders as &$order) {
        $order['created_at'] = date('c', strtotime($order['created_at']));
        $order['updated_at'] = date('c', strtotime($order['updated_at']));
    }
    
    successResponse('Orders retrieved', [
        'orders' => $orders,
        'pagination' => [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => (int)$total,
            'pages' => ceil($total / $pagination['per_page'])
        ]
    ]);
}

/**
 * GET /api/orders/my
 * Get current user's orders (buyer or farmer)
 */
function getMyOrders() {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Get role-specific orders
    if ($payload['role'] === 'farmer') {
        $sql = "
            SELECT o.*, 
                   l.crop_name, l.image_url,
                   buyer.name as buyer_name,
                   farmer.name as farmer_name
            FROM orders o
            LEFT JOIN listings l ON o.listing_id = l.id
            LEFT JOIN users buyer ON o.buyer_id = buyer.id
            LEFT JOIN users farmer ON o.farmer_id = farmer.id
            WHERE o.farmer_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ";
    } else {
        $sql = "
            SELECT o.*, 
                   l.crop_name, l.image_url,
                   buyer.name as buyer_name,
                   farmer.name as farmer_name
            FROM orders o
            LEFT JOIN listings l ON o.listing_id = l.id
            LEFT JOIN users buyer ON o.buyer_id = buyer.id
            LEFT JOIN users farmer ON o.farmer_id = farmer.id
            WHERE o.buyer_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ";
    }
    
    $params = [$payload['user_id'], $pagination['per_page'], $pagination['offset']];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    // Get total count
    if ($payload['role'] === 'farmer') {
        $countSql = "SELECT COUNT(*) as total FROM orders WHERE farmer_id = ?";
    } else {
        $countSql = "SELECT COUNT(*) as total FROM orders WHERE buyer_id = ?";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute([$payload['user_id']]);
    $total = $stmt->fetch()['total'];
    
    // Format dates
    foreach ($orders as &$order) {
        $order['created_at'] = date('c', strtotime($order['created_at']));
        $order['updated_at'] = date('c', strtotime($order['updated_at']));
    }
    
    successResponse('My orders retrieved', [
        'orders' => $orders,
        'pagination' => [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => (int)$total,
            'pages' => ceil($total / $pagination['per_page'])
        ]
    ]);
}

/**
 * GET /api/orders/{id}
 * Get single order by ID
 */
function getOrder($id) {
    $payload = authenticateRequest();
    
    $db = Database::getInstance();
    
    $stmt = $db->prepare("
        SELECT o.*, 
               l.crop_name, l.image_url, l.location as listing_location,
               buyer.name as buyer_name, buyer.email as buyer_email,
               farmer.name as farmer_name, farmer.email as farmer_email
        FROM orders o
        LEFT JOIN listings l ON o.listing_id = l.id
        LEFT JOIN users buyer ON o.buyer_id = buyer.id
        LEFT JOIN users farmer ON o.farmer_id = farmer.id
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        errorResponse('Order not found', 404);
    }
    
    // Check authorization (buyer, farmer, or admin)
    if ($payload['role'] !== 'admin' && 
        $order['buyer_id'] !== $payload['user_id'] && 
        $order['farmer_id'] !== $payload['user_id']) {
        errorResponse('Unauthorized', 403);
    }
    
    $order['created_at'] = date('c', strtotime($order['created_at']));
    $order['updated_at'] = date('c', strtotime($order['updated_at']));
    
    // Get order transactions
    $stmt = $db->prepare('SELECT * FROM transactions WHERE order_id = ? ORDER BY created_at DESC');
    $stmt->execute([$id]);
    $order['transactions'] = $stmt->fetchAll();
    
    successResponse('Order retrieved', $order);
}

/**
 * POST /api/orders/create
 * Create a new order from a listing
 */
function createOrder() {
    $payload = requireRole(['buyer']);
    
    global $data;
    
    validateRequired(['listing_id', 'quantity'], $data);
    
    $listingId = (int)$data['listing_id'];
    $quantity = (float)$data['quantity'];
    $notes = sanitizeInput($data['notes'] ?? '');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get listing
        $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND status = ?');
        $stmt->execute([$listingId, 'active']);
        $listing = $stmt->fetch();
        
        if (!$listing) {
            errorResponse('Listing not found or not active', 404);
        }
        
        // Check if buyer is also the farmer
        if ($listing['farmer_id'] == $payload['user_id']) {
            errorResponse('You cannot buy your own listing');
        }
        
        // Validate quantity
        if ($quantity <= 0) {
            errorResponse('Quantity must be greater than 0');
        }
        
        if ($quantity > $listing['quantity']) {
            errorResponse('Requested quantity exceeds available quantity');
        }
        
        // Calculate amounts
        $unitPrice = $listing['price'];
        $subtotal = $unitPrice * $quantity;
        $serviceFee = round($subtotal * 0.02, 2); // 2% service fee
        $totalAmount = $subtotal + $serviceFee;
        
        // Generate payment reference
        $paymentReference = 'AGR-' . strtoupper(bin2hex(random_bytes(8)));
        
        // Insert order
        $stmt = $db->prepare('
            INSERT INTO orders (
                listing_id, buyer_id, farmer_id, quantity, unit, 
                unit_price, total_amount, service_fee, status, escrow_status,
                payment_reference, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $listingId,
            $payload['user_id'],
            $listing['farmer_id'],
            $quantity,
            $listing['unit'],
            $unitPrice,
            $subtotal,
            $serviceFee,
            'pending',
            'held',
            $paymentReference,
            $notes
        ]);
        
        $orderId = $db->lastInsertId();
        
        // Create payment transaction
        $stmt = $db->prepare('
            INSERT INTO transactions (order_id, user_id, amount, type, status, reference, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $orderId,
            $payload['user_id'],
            $totalAmount,
            'payment',
            'completed',
            $paymentReference,
            "Payment for {$listing['crop_name']} ({$quantity} {$listing['unit']})"
        ]);
        
        // Update listing quantity
        $newQuantity = $listing['quantity'] - $quantity;
        if ($newQuantity <= 0) {
            $stmt = $db->prepare('UPDATE listings SET status = ?, quantity = 0 WHERE id = ?');
            $stmt->execute(['sold', $listingId]);
        } else {
            $stmt = $db->prepare('UPDATE listings SET quantity = ? WHERE id = ?');
            $stmt->execute([$newQuantity, $listingId]);
        }
        
        // Create notification for farmer
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $listing['farmer_id'],
            'New Order Received',
            "You have a new order for {$listing['crop_name']}",
            'order'
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'order_create', 'order', $orderId);
        
        $db->commit();
        
        // Get created order
        $stmt = $db->prepare("
            SELECT o.*, 
                   l.crop_name,
                   buyer.name as buyer_name,
                   farmer.name as farmer_name
            FROM orders o
            LEFT JOIN listings l ON o.listing_id = l.id
            LEFT JOIN users buyer ON o.buyer_id = buyer.id
            LEFT JOIN users farmer ON o.farmer_id = farmer.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $order['created_at'] = date('c', strtotime($order['created_at']));
        
        successResponse('Order created successfully. Payment is being held in escrow.', $order, 201);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/orders/accept
 * Farmer accepts an order
 */
function acceptOrder() {
    $payload = requireRole(['farmer']);
    
    global $data;
    
    validateRequired(['order_id'], $data);
    
    $orderId = (int)$data['order_id'];
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get order
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND farmer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'pending') {
            errorResponse('Order cannot be accepted. Current status: ' . $order['status']);
        }
        
        // Update order status
        $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(['accepted', $orderId]);
        
        // Create notification for buyer
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $order['buyer_id'],
            'Order Accepted',
            'Your order has been accepted by the farmer. They are preparing your produce.',
            'order'
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'order_accept', 'order', $orderId);
        
        $db->commit();
        
        // Get updated order
        $stmt = $db->prepare("
            SELECT o.*, 
                   l.crop_name,
                   buyer.name as buyer_name,
                   farmer.name as farmer_name
            FROM orders o
            LEFT JOIN listings l ON o.listing_id = l.id
            LEFT JOIN users buyer ON o.buyer_id = buyer.id
            LEFT JOIN users farmer ON o.farmer_id = farmer.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $order['created_at'] = date('c', strtotime($order['created_at']));
        $order['updated_at'] = date('c', strtotime($order['updated_at']));
        
        successResponse('Order accepted successfully', $order);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to accept order: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/orders/reject
 * Farmer rejects an order
 */
function rejectOrder() {
    $payload = requireRole(['farmer']);
    
    global $data;
    
    validateRequired(['order_id'], $data);
    
    $orderId = (int)$data['order_id'];
    $reason = sanitizeInput($data['reason'] ?? 'Order rejected by farmer');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get order
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND farmer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'pending') {
            errorResponse('Order cannot be rejected. Current status: ' . $order['status']);
        }
        
        // Update order status
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ?, notes = ? WHERE id = ?');
        $stmt->execute(['cancelled', 'refunded', $reason, $orderId]);
        
        // Restore listing quantity
        $stmt = $db->prepare('UPDATE listings SET quantity = quantity + ? WHERE id = ?');
        $stmt->execute([$order['quantity'], $order['listing_id']]);
        
        // Create refund transaction
        $refundRef = 'REF-' . strtoupper(bin2hex(random_bytes(8)));
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
            $refundRef,
            'Refund for rejected order'
        ]);
        
        // Create notification for buyer
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $order['buyer_id'],
            'Order Rejected',
            'Your order has been rejected. A refund has been processed.',
            'order'
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'order_reject', 'order', $orderId);
        
        $db->commit();
        
        successResponse('Order rejected and refunded', ['order_id' => $orderId]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to reject order: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/orders/confirm-delivery
 * Buyer confirms delivery and releases escrow
 */
function confirmDelivery() {
    $payload = requireRole(['buyer']);
    
    global $data;
    
    validateRequired(['order_id'], $data);
    
    $orderId = (int)$data['order_id'];
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get order
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'accepted') {
            errorResponse('Order cannot be confirmed. It must be accepted first.');
        }
        
        // Update order status
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['completed', 'released', $orderId]);
        
        // Calculate farmer earnings (minus service fee)
        $farmerEarnings = $order['total_amount'] - $order['service_fee'];
        
        // Create escrow release transaction
        $releaseRef = 'REL-' . strtoupper(bin2hex(random_bytes(8)));
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
            $releaseRef,
            'Payment released for completed order'
        ]);
        
        // Update farmer's wallet
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
        
        // Create notification for farmer
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $order['farmer_id'],
            'Payment Released',
            "Your payment of ₦" . number_format($farmerEarnings) . " has been released to your wallet.",
            'payment'
        ]);
        
        // Log activity
        logActivity($payload['user_id'], 'order_confirm_delivery', 'order', $orderId);
        
        $db->commit();
        
        successResponse('Delivery confirmed. Payment has been released to the farmer.', [
            'order_id' => $orderId,
            'released_amount' => $farmerEarnings
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to confirm delivery: ' . $e->getMessage(), 500);
    }
}

/**
 * PUT /api/orders/{id}
 * Update order (admin only)
 */
function updateOrder($id) {
    $payload = requireRole(['admin']);
    
    global $data;
    
    $db = Database::getInstance();
    
    // Build update query
    $allowedFields = ['status', 'escrow_status', 'notes'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }
    
    if (empty($updates)) {
        errorResponse('No fields to update');
    }
    
    $params[] = $id;
    
    try {
        $stmt = $db->prepare('
            UPDATE orders SET ' . implode(', ', $updates) . '
            WHERE id = ?
        ');
        $stmt->execute($params);
        
        // Log activity
        logActivity($payload['user_id'], 'order_update', 'order', $id);
        
        successResponse('Order updated successfully');
        
    } catch (Exception $e) {
        errorResponse('Failed to update order: ' . $e->getMessage(), 500);
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
