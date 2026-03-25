<?php
/**
 * AgriMarket API - Listings Endpoints
 * 
 * Handles all listing-related operations:
 * - Create, read, update, delete listings
 * - Search and filter listings
 * - Get farmer's listings
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/listings/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            getListing($uriParts[0]);
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'my') {
            getMyListings();
        } elseif (isset($uriParts[0]) && $uriParts[0] === 'search') {
            searchListings();
        } else {
            getListings();
        }
        break;
    case 'POST':
        createListing();
        break;
    case 'PUT':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            updateListing($uriParts[0]);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'DELETE':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            deleteListing($uriParts[0]);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/listings
 * Get all active listings with pagination and filters
 */
function getListings() {
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Filters
    $status = $_GET['status'] ?? 'active';
    $cropName = $_GET['crop'] ?? null;
    $location = $_GET['location'] ?? null;
    $farmerId = $_GET['farmer_id'] ?? null;
    $minPrice = $_GET['min_price'] ?? null;
    $maxPrice = $_GET['max_price'] ?? null;
    $minQuantity = $_GET['min_quantity'] ?? null;
    $maxQuantity = $_GET['max_quantity'] ?? null;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($status) {
        $where[] = 'l.status = ?';
        $params[] = $status;
    }
    
    if ($cropName) {
        $where[] = 'l.crop_name LIKE ?';
        $params[] = "%$cropName%";
    }
    
    if ($location) {
        $where[] = 'l.location LIKE ?';
        $params[] = "%$location%";
    }
    
    if ($farmerId) {
        $where[] = 'l.farmer_id = ?';
        $params[] = $farmerId;
    }
    
    if ($minPrice) {
        $where[] = 'l.price >= ?';
        $params[] = $minPrice;
    }
    
    if ($maxPrice) {
        $where[] = 'l.price <= ?';
        $params[] = $maxPrice;
    }
    
    if ($minQuantity) {
        $where[] = 'l.quantity >= ?';
        $params[] = $minQuantity;
    }
    
    if ($maxQuantity) {
        $where[] = 'l.quantity <= ?';
        $params[] = $maxQuantity;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM listings l WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get listings
    $sql = "
        SELECT l.*, u.name as farmer_name, u.location as farmer_location
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
 * GET /api/listings/{id}
 * Get single listing by ID
 */
function getListing($id) {
    $db = Database::getInstance();
    
    $stmt = $db->prepare("
        SELECT l.*, u.name as farmer_name, u.location as farmer_location, u.phone as farmer_phone
        FROM listings l
        JOIN users u ON l.farmer_id = u.id
        WHERE l.id = ?
    ");
    $stmt->execute([$id]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found', 404);
    }
    
    $listing['created_at'] = date('c', strtotime($listing['created_at']));
    $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
    
    successResponse('Listing retrieved', $listing);
}

/**
 * GET /api/listings/my
 * Get current user's listings (farmer only)
 */
function getMyListings() {
    $payload = requireRole(['farmer']);
    
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Status filter
    $status = $_GET['status'] ?? null;
    
    // Build query
    $where = 'l.farmer_id = ?';
    $params = [$payload['user_id']];
    
    if ($status) {
        $where .= ' AND l.status = ?';
        $params[] = $status;
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM listings l WHERE $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get listings
    $sql = "
        SELECT l.*, u.name as farmer_name
        FROM listings l
        JOIN users u ON l.farmer_id = u.id
        WHERE $where
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
    
    successResponse('My listings retrieved', [
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
 * GET /api/listings/search
 * Search listings with advanced filters
 */
function searchListings() {
    $db = Database::getInstance();
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    // Search query
    $query = $_GET['q'] ?? '';
    $crop = $_GET['crop'] ?? null;
    $location = $_GET['location'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = $_GET['sort_order'] ?? 'DESC';
    
    // Build query
    $where = ['l.status = ?'];
    $params = ['active'];
    
    if ($query) {
        $where[] = '(l.crop_name LIKE ? OR l.description LIKE ? OR u.name LIKE ?)';
        $searchTerm = "%$query%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($crop) {
        $where[] = 'l.crop_name = ?';
        $params[] = $crop;
    }
    
    if ($location) {
        $where[] = 'l.location LIKE ?';
        $params[] = "%$location%";
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Validate sort field
    $allowedSortFields = ['created_at', 'price', 'quantity', 'crop_name'];
    if (!in_array($sortBy, $allowedSortFields)) {
        $sortBy = 'created_at';
    }
    
    // Validate sort order
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) as total 
        FROM listings l
        JOIN users u ON l.farmer_id = u.id
        WHERE $whereClause
    ";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    // Get listings
    $sql = "
        SELECT l.*, u.name as farmer_name, u.location as farmer_location
        FROM listings l
        JOIN users u ON l.farmer_id = u.id
        WHERE $whereClause
        ORDER BY l.$sortBy $sortOrder
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
    
    // Get unique crops for filters
    $stmt = $db->query("SELECT DISTINCT crop_name FROM listings WHERE status = 'active' ORDER BY crop_name");
    $crops = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique locations for filters
    $stmt = $db->query("SELECT DISTINCT location FROM listings WHERE status = 'active' AND location IS NOT NULL ORDER BY location");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    successResponse('Search results', [
        'listings' => $listings,
        'filters' => [
            'crops' => $crops,
            'locations' => $locations
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
 * POST /api/listings
 * Create a new listing
 */
function createListing() {
    $payload = requireRole(['farmer']);
    
    global $data;
    
    validateRequired(['crop_name', 'quantity', 'unit', 'price', 'location'], $data);
    
    $cropName = sanitizeInput($data['crop_name']);
    $quantity = (float)$data['quantity'];
    $unit = sanitizeInput($data['unit']);
    $price = (float)$data['price'];
    $location = sanitizeInput($data['location']);
    $description = sanitizeInput($data['description'] ?? '');
    $imageUrl = sanitizeInput($data['image_url'] ?? '');
    
    // Validate quantity and price
    if ($quantity <= 0) {
        errorResponse('Quantity must be greater than 0');
    }
    
    if ($price <= 0) {
        errorResponse('Price must be greater than 0');
    }
    
    // Validate unit
    $allowedUnits = ['kg', 'bags', 'tubers', 'baskets', 'crates', 'tons'];
    if (!in_array($unit, $allowedUnits)) {
        errorResponse('Invalid unit. Allowed: ' . implode(', ', $allowedUnits));
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Get farmer name
        $stmt = $db->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$payload['user_id']]);
        $farmer = $stmt->fetch();
        
        // Insert listing
        $stmt = $db->prepare('
            INSERT INTO listings (farmer_id, crop_name, quantity, unit, price, description, location, image_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $payload['user_id'],
            $cropName,
            $quantity,
            $unit,
            $price,
            $description,
            $location,
            $imageUrl
        ]);
        
        $listingId = $db->lastInsertId();
        
        // Log activity
        logActivity($payload['user_id'], 'listing_create', 'listing', $listingId);
        
        $db->commit();
        
        // Get created listing
        $stmt = $db->prepare("
            SELECT l.*, u.name as farmer_name
            FROM listings l
            JOIN users u ON l.farmer_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        $listing['created_at'] = date('c', strtotime($listing['created_at']));
        $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
        
        successResponse('Listing created successfully', $listing, 201);
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to create listing: ' . $e->getMessage(), 500);
    }
}

/**
 * PUT /api/listings/{id}
 * Update a listing
 */
function updateListing($id) {
    $payload = requireRole(['farmer']);
    
    global $data;
    
    $db = Database::getInstance();
    
    // Check if listing exists and belongs to farmer
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND farmer_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found or unauthorized', 404);
    }
    
    // Check if listing can be edited
    if ($listing['status'] === 'sold') {
        errorResponse('Cannot edit a sold listing');
    }
    
    // Build update query
    $allowedFields = ['crop_name', 'quantity', 'unit', 'price', 'description', 'location', 'image_url', 'status'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            
            if ($field === 'quantity' || $field === 'price') {
                $params[] = (float)$data[$field];
            } else {
                $params[] = sanitizeInput($data[$field]);
            }
        }
    }
    
    if (empty($updates)) {
        errorResponse('No fields to update');
    }
    
    $params[] = $id;
    
    try {
        $stmt = $db->prepare('
            UPDATE listings SET ' . implode(', ', $updates) . '
            WHERE id = ?
        ');
        $stmt->execute($params);
        
        // Log activity
        logActivity($payload['user_id'], 'listing_update', 'listing', $id);
        
        // Get updated listing
        $stmt = $db->prepare("
            SELECT l.*, u.name as farmer_name
            FROM listings l
            JOIN users u ON l.farmer_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([$id]);
        $listing = $stmt->fetch();
        $listing['created_at'] = date('c', strtotime($listing['created_at']));
        $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
        
        successResponse('Listing updated successfully', $listing);
        
    } catch (Exception $e) {
        errorResponse('Failed to update listing: ' . $e->getMessage(), 500);
    }
}

/**
 * DELETE /api/listings/{id}
 * Delete a listing
 */
function deleteListing($id) {
    $payload = requireRole(['farmer']);
    
    $db = Database::getInstance();
    
    // Check if listing exists and belongs to farmer
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND farmer_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found or unauthorized', 404);
    }
    
    try {
        $db->beginTransaction();
        
        // Delete listing (will cascade to orders if needed)
        $stmt = $db->prepare('DELETE FROM listings WHERE id = ?');
        $stmt->execute([$id]);
        
        // Log activity
        logActivity($payload['user_id'], 'listing_delete', 'listing', $id);
        
        $db->commit();
        
        successResponse('Listing deleted successfully');
        
    } catch (Exception $e) {
        $db->rollBack();
        errorResponse('Failed to delete listing: ' . $e->getMessage(), 500);
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
