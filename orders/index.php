<?php
/**
 * AgriMarket API - Orders Router
 * 
 * Routes: GET /api/orders, GET /api/orders/{id}, POST /api/orders, etc.
 */

// Include CORS headers FIRST
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Parse the URI
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/orders/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$id = is_numeric($action) ? (int)$action : null;

switch ($method) {
    case 'GET':
        if ($id) {
            getOrder($id);
        } elseif ($action === 'my') {
            getMyOrders();
        } else {
            getOrders();
        }
        break;
    case 'POST':
        if ($action === 'create') {
            createOrder();
        } elseif ($action === 'accept') {
            acceptOrder();
        } elseif ($action === 'reject') {
            rejectOrder();
        } elseif ($action === 'confirm-delivery') {
            confirmDelivery();
        } else {
            createOrder();
        }
        break;
    case 'PUT':
        if ($id) {
            updateOrder($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/orders
 */
function getOrders() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $status = $_GET['status'] ?? null;
    $escrowStatus = $_GET['escrow_status'] ?? null;
    
    $where = ['1=1'];
    $params = [];
    
    if ($payload['role'] === 'buyer') {
        $where[] = 'o.buyer_id = ?';
        $params[] = $payload['user_id'];
    } elseif ($payload['role'] === 'farmer') {
        $where[] = 'o.farmer_id = ?';
        $params[] = $payload['user_id'];
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
    
    $countSql = "SELECT COUNT(*) as total FROM orders o WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT o.*, l.crop_name, l.image_url, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE $whereClause ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
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
 */
function getMyOrders() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    if ($payload['role'] === 'farmer') {
        $sql = "SELECT o.*, l.crop_name, l.image_url, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.farmer_id = ? ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    } else {
        $sql = "SELECT o.*, l.crop_name, l.image_url, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.buyer_id = ? ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    }
    
    $params = [$payload['user_id'], $pagination['per_page'], $pagination['offset']];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    if ($payload['role'] === 'farmer') {
        $countSql = "SELECT COUNT(*) as total FROM orders WHERE farmer_id = ?";
    } else {
        $countSql = "SELECT COUNT(*) as total FROM orders WHERE buyer_id = ?";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute([$payload['user_id']]);
    $total = $stmt->fetch()['total'];
    
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
 */
function getOrder($id) {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    
    $stmt = $db->prepare("SELECT o.*, l.crop_name, l.image_url, l.location as listing_location, buyer.name as buyer_name, buyer.email as buyer_email, farmer.name as farmer_name, farmer.email as farmer_email FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        errorResponse('Order not found', 404);
    }
    
    if ($payload['role'] !== 'admin' && $order['buyer_id'] !== $payload['user_id'] && $order['farmer_id'] !== $payload['user_id']) {
        errorResponse('Unauthorized', 403);
    }
    
    $order['created_at'] = date('c', strtotime($order['created_at']));
    $order['updated_at'] = date('c', strtotime($order['updated_at']));
    
    $stmt = $db->prepare('SELECT * FROM transactions WHERE order_id = ? ORDER BY created_at DESC');
    $stmt->execute([$id]);
    $order['transactions'] = $stmt->fetchAll();
    
    successResponse('Order retrieved', $order);
}

/**
 * POST /api/orders/create
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
        
        $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND status = ?');
        $stmt->execute([$listingId, 'active']);
        $listing = $stmt->fetch();
        
        if (!$listing) {
            errorResponse('Listing not found or not active', 404);
        }
        
        if ($listing['farmer_id'] == $payload['user_id']) {
            errorResponse('You cannot buy your own listing');
        }
        
        if ($quantity <= 0) {
            errorResponse('Quantity must be greater than 0');
        }
        
        if ($quantity > $listing['quantity']) {
            errorResponse('Requested quantity exceeds available quantity');
        }
        
        $unitPrice = $listing['price'];
        $subtotal = $unitPrice * $quantity;
        $serviceFee = round($subtotal * 0.02, 2);
        $totalAmount = $subtotal + $serviceFee;
        
        $paymentReference = 'AGR-' . strtoupper(bin2hex(random_bytes(8)));
        
        $stmt = $db->prepare('INSERT INTO orders (listing_id, buyer_id, farmer_id, quantity, unit, unit_price, total_amount, service_fee, status, escrow_status, payment_reference, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$listingId, $payload['user_id'], $listing['farmer_id'], $quantity, $listing['unit'], $unitPrice, $subtotal, $serviceFee, 'pending', 'held', $paymentReference, $notes]);
        
        $orderId = $db->lastInsertId();
        
        $stmt = $db->prepare('INSERT INTO transactions (order_id, user_id, amount, type, status, reference, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $payload['user_id'], $totalAmount, 'payment', 'completed', $paymentReference, "Payment for {$listing['crop_name']} ({$quantity} {$listing['unit']})"]);
        
        $newQuantity = $listing['quantity'] - $quantity;
        if ($newQuantity <= 0) {
            $stmt = $db->prepare('UPDATE listings SET status = ?, quantity = 0 WHERE id = ?');
            $stmt->execute(['sold', $listingId]);
        } else {
            $stmt = $db->prepare('UPDATE listings SET quantity = ? WHERE id = ?');
            $stmt->execute([$newQuantity, $listingId]);
        }
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$listing['farmer_id'], 'New Order Received', "You have a new order for {$listing['crop_name']}", 'order']);
        
        logActivity($payload['user_id'], 'order_create', 'order', $orderId);
        
        $db->commit();
        
        $stmt = $db->prepare("SELECT o.*, l.crop_name, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.id = ?");
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
 * POST /api/orders/{id}/accept
 */
function acceptOrder($orderId = null) {
    $payload = requireRole(['farmer']);
    global $data;
    
    // Support both URL param and body param
    $orderId = $orderId ?? ($data['order_id'] ?? null);
    if (!$orderId) {
        errorResponse('Order ID is required');
    }
    $orderId = (int)$orderId;
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND farmer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'pending') {
            errorResponse('Order cannot be accepted. Current status: ' . $order['status']);
        }
        
        $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(['accepted', $orderId]);
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$order['buyer_id'], 'Order Accepted', 'Your order has been accepted by the farmer. They are preparing your produce.', 'order']);
        
        logActivity($payload['user_id'], 'order_accept', 'order', $orderId);
        
        $db->commit();
        
        $stmt = $db->prepare("SELECT o.*, l.crop_name, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.id = ?");
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
 * POST /api/orders/{id}/reject
 */
function rejectOrder($orderId = null) {
    $payload = requireRole(['farmer']);
    global $data;
    
    // Support both URL param and body param
    $orderId = $orderId ?? ($data['order_id'] ?? null);
    if (!$orderId) {
        errorResponse('Order ID is required');
    }
    $orderId = (int)$orderId;
    $reason = sanitizeInput($data['reason'] ?? 'Order rejected by farmer');
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND farmer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'pending') {
            errorResponse('Order cannot be rejected. Current status: ' . $order['status']);
        }
        
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['cancelled', 'refunded', $orderId]);
        
        $stmt = $db->prepare('UPDATE listings SET quantity = quantity + ?, status = ? WHERE id = ?');
        $stmt->execute([$order['quantity'], 'active', $order['listing_id']]);
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$order['buyer_id'], 'Order Rejected', $reason, 'order']);
        
        logActivity($payload['user_id'], 'order_reject', 'order', $orderId, ['reason' => $reason]);
        
        $db->commit();
        
        successResponse('Order rejected successfully');
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to reject order: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/orders/{id}/deliver
 */
function confirmDelivery($orderId = null) {
    $payload = requireRole(['buyer']);
    global $data;
    
    // Support both URL param and body param
    $orderId = $orderId ?? ($data['order_id'] ?? null);
    if (!$orderId) {
        errorResponse('Order ID is required');
    }
    $orderId = (int)$orderId;
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'accepted') {
            errorResponse('Order cannot be confirmed. Current status: ' . $order['status']);
        }
        
        $stmt = $db->prepare('UPDATE orders SET status = ?, escrow_status = ? WHERE id = ?');
        $stmt->execute(['completed', 'released', $orderId]);
        
        $stmt = $db->prepare('UPDATE wallets SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?');
        $stmt->execute([$order['total_amount'], $order['total_amount'], $order['farmer_id']]);
        
        $stmt = $db->prepare('INSERT INTO transactions (order_id, user_id, amount, type, status, reference, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $order['farmer_id'], $order['total_amount'], 'escrow_release', 'completed', $order['payment_reference'], 'Funds released after delivery confirmation']);
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$order['farmer_id'], 'Payment Released', 'Your payment has been released to your wallet.', 'payment']);
        
        logActivity($payload['user_id'], 'order_confirm_delivery', 'order', $orderId);
        
        $db->commit();
        
        $stmt = $db->prepare("SELECT o.*, l.crop_name, buyer.name as buyer_name, farmer.name as farmer_name FROM orders o LEFT JOIN listings l ON o.listing_id = l.id LEFT JOIN users buyer ON o.buyer_id = buyer.id LEFT JOIN users farmer ON o.farmer_id = farmer.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $order['created_at'] = date('c', strtotime($order['created_at']));
        $order['updated_at'] = date('c', strtotime($order['updated_at']));
        
        successResponse('Delivery confirmed. Payment released to farmer.', $order);
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to confirm delivery: ' . $e->getMessage(), 500);
    }
}

/**
 * PUT /api/orders/{id}
 */
function updateOrder($id) {
    $payload = authenticateRequest();
    global $data;
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND (buyer_id = ? OR farmer_id = ?)');
    $stmt->execute([$id, $payload['user_id'], $payload['user_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        errorResponse('Order not found or unauthorized', 404);
    }
    
    $allowedFields = ['notes'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }
    
    if (!empty($updates)) {
        $params[] = $id;
        $stmt = $db->prepare('UPDATE orders SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
    }
    
    successResponse('Order updated successfully');
}

/**
 * POST /api/orders/{id}/cancel
 * Cancel an order (buyer can cancel pending orders)
 */
function cancelOrder($orderId = null) {
    $payload = authenticateRequest();
    global $data;
    
    $orderId = $orderId ?? ($data['order_id'] ?? null);
    if (!$orderId) {
        errorResponse('Order ID is required');
    }
    $orderId = (int)$orderId;
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'pending' && $order['status'] !== 'accepted') {
            errorResponse('Order cannot be cancelled. Current status: ' . $order['status']);
        }
        
        $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(['cancelled', $orderId]);
        
        // Refund if payment was made
        if ($order['escrow_status'] === 'held') {
            $stmt = $db->prepare('UPDATE orders SET escrow_status = ? WHERE id = ?');
            $stmt->execute(['refunded', $orderId]);
            
            // Restore listing quantity
            $stmt = $db->prepare('UPDATE listings SET quantity = quantity + ? WHERE id = ?');
            $stmt->execute([$order['quantity'], $order['listing_id']]);
        }
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$order['farmer_id'], 'Order Cancelled', 'The buyer has cancelled the order.', 'order']);
        
        logActivity($payload['user_id'], 'order_cancel', 'order', $orderId);
        
        $db->commit();
        
        successResponse('Order cancelled successfully');
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/orders/{id}/complete
 * Mark order as completed (after delivery confirmation)
 */
function completeOrder($orderId = null) {
    $payload = requireRole(['farmer']);
    global $data;
    
    $orderId = $orderId ?? ($data['order_id'] ?? null);
    if (!$orderId) {
        errorResponse('Order ID is required');
    }
    $orderId = (int)$orderId;
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND farmer_id = ?');
        $stmt->execute([$orderId, $payload['user_id']]);
        $order = $stmt->fetch();
        
        if (!$order) {
            errorResponse('Order not found or unauthorized', 404);
        }
        
        if ($order['status'] !== 'accepted') {
            errorResponse('Order cannot be completed. Current status: ' . $order['status']);
        }
        
        $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute(['completed', $orderId]);
        
        // Release escrow to farmer
        if ($order['escrow_status'] === 'held') {
            $stmt = $db->prepare('UPDATE orders SET escrow_status = ? WHERE id = ?');
            $stmt->execute(['released', $orderId]);
            
            // Calculate farmer earnings (after service fee)
            $farmerEarnings = $order['total_amount'] - $order['service_fee'];
            
            // Update farmer wallet
            $stmt = $db->prepare('UPDATE wallets SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?');
            $stmt->execute([$farmerEarnings, $farmerEarnings, $payload['user_id']]);
            
            // Create transaction record
            $reference = 'ESCROW_' . $orderId . '_' . time();
            $stmt = $db->prepare('INSERT INTO transactions (user_id, order_id, amount, type, status, reference, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$payload['user_id'], $orderId, $farmerEarnings, 'escrow_release', 'completed', $reference, 'Escrow released for order']);
        }
        
        $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$order['buyer_id'], 'Order Completed', 'Your order has been completed. Thank you for using AgriMarket!', 'order']);
        
        logActivity($payload['user_id'], 'order_complete', 'order', $orderId);
        
        $db->commit();
        
        successResponse('Order completed successfully');
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to complete order: ' . $e->getMessage(), 500);
    }
}

