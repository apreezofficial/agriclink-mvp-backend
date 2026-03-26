<?php
/**
 * AgriMarket API - Listings Router
 * 
 * Routes: GET /api/listings, GET /api/listings/{id}, POST /api/listings, etc.
 */

// Include CORS headers FIRST
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Parse the URI
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/listings/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$id = is_numeric($action) ? (int)$action : null;

switch ($method) {
    case 'GET':
        if ($id) {
            getListing($id);
        } elseif ($action === 'my') {
            getMyListings();
        } elseif ($action === 'search') {
            searchListings();
        } else {
            getListings();
        }
        break;
    case 'POST':
        createListing();
        break;
    case 'PUT':
        if ($id) {
            updateListing($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'PATCH':
        // Handle status update at /api/listings/{id}/status
        if ($id && isset($uriParts[1]) && $uriParts[1] === 'status') {
            updateListingStatus($id);
        } elseif ($id) {
            updateListing($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    case 'DELETE':
        if ($id) {
            deleteListing($id);
        } else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/listings
 */
function getListings() {
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    $status = $_GET['status'] ?? 'active';
    $cropName = $_GET['crop'] ?? null;
    $location = $_GET['location'] ?? null;
    $farmerId = $_GET['farmer_id'] ?? null;
    $minPrice = $_GET['min_price'] ?? null;
    $maxPrice = $_GET['max_price'] ?? null;
    
    $where = ['l.status = ?'];
    $params = [$status];
    
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
    
    $whereClause = implode(' AND ', $where);
    
    $countSql = "SELECT COUNT(*) as total FROM listings l WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT l.*, u.name as farmer_name, u.location as farmer_location FROM listings l JOIN users u ON l.farmer_id = u.id WHERE $whereClause ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();
    
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
 */
function getListing($id) {
    $db = Database::getInstance();
    
    $stmt = $db->prepare("SELECT l.*, u.name as farmer_name, u.location as farmer_location, u.phone as farmer_phone FROM listings l JOIN users u ON l.farmer_id = u.id WHERE l.id = ?");
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
 */
function getMyListings() {
    $payload = requireRole(['farmer']);
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $status = $_GET['status'] ?? null;
    
    $where = 'l.farmer_id = ?';
    $params = [$payload['user_id']];
    
    if ($status) {
        $where .= ' AND l.status = ?';
        $params[] = $status;
    }
    
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
 */
function searchListings() {
    $db = Database::getInstance();
    
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    
    $query = $_GET['q'] ?? '';
    $crop = $_GET['crop'] ?? null;
    $location = $_GET['location'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = $_GET['sort_order'] ?? 'DESC';
    
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
    
    $allowedSortFields = ['created_at', 'price', 'quantity', 'crop_name'];
    if (!in_array($sortBy, $allowedSortFields)) {
        $sortBy = 'created_at';
    }
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    $countSql = "SELECT COUNT(*) as total FROM listings l JOIN users u ON l.farmer_id = u.id WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $sql = "SELECT l.*, u.name as farmer_name, u.location as farmer_location FROM listings l JOIN users u ON l.farmer_id = u.id WHERE $whereClause ORDER BY l.$sortBy $sortOrder LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $listings = $stmt->fetchAll();
    
    foreach ($listings as &$listing) {
        $listing['created_at'] = date('c', strtotime($listing['created_at']));
        $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
    }
    
    $stmt = $db->query("SELECT DISTINCT crop_name FROM listings WHERE status = 'active' ORDER BY crop_name");
    $crops = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $db->query("SELECT DISTINCT location FROM listings WHERE status = 'active' AND location IS NOT NULL ORDER BY location");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    successResponse('Search results', [
        'listings' => $listings,
        'filters' => ['crops' => $crops, 'locations' => $locations],
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
    
    if ($quantity <= 0) {
        errorResponse('Quantity must be greater than 0');
    }
    
    if ($price <= 0) {
        errorResponse('Price must be greater than 0');
    }
    
    $allowedUnits = ['kg', 'bags', 'tubers', 'baskets', 'crates', 'tons'];
    if (!in_array($unit, $allowedUnits)) {
        errorResponse('Invalid unit. Allowed: ' . implode(', ', $allowedUnits));
    }
    
    $db = Database::getInstance();
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$payload['user_id']]);
        $farmer = $stmt->fetch();
        
        $stmt = $db->prepare('INSERT INTO listings (farmer_id, crop_name, quantity, unit, price, description, location, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$payload['user_id'], $cropName, $quantity, $unit, $price, $description, $location, $imageUrl]);
        
        $listingId = $db->lastInsertId();
        logActivity($payload['user_id'], 'listing_create', 'listing', $listingId);
        
        $db->commit();
        
        $stmt = $db->prepare("SELECT l.*, u.name as farmer_name FROM listings l JOIN users u ON l.farmer_id = u.id WHERE l.id = ?");
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
 */
function updateListing($id) {
    $payload = requireRole(['farmer']);
    global $data;
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND farmer_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found or unauthorized', 404);
    }
    
    if ($listing['status'] === 'sold') {
        errorResponse('Cannot edit a sold listing');
    }
    
    $allowedFields = ['crop_name', 'quantity', 'unit', 'price', 'description', 'location', 'image_url', 'status'];
    $updates = [];
    $params = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = ($field === 'quantity' || $field === 'price') ? (float)$data[$field] : sanitizeInput($data[$field]);
        }
    }
    
    if (empty($updates)) {
        errorResponse('No fields to update');
    }
    
    $params[] = $id;
    
    try {
        $stmt = $db->prepare('UPDATE listings SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        
        logActivity($payload['user_id'], 'listing_update', 'listing', $id);
        
        $stmt = $db->prepare("SELECT l.*, u.name as farmer_name FROM listings l JOIN users u ON l.farmer_id = u.id WHERE l.id = ?");
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
 */
function deleteListing($id) {
    $payload = requireRole(['farmer']);
    $db = Database::getInstance();
    
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND farmer_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found or unauthorized', 404);
    }
    
    try {
        $stmt = $db->prepare('DELETE FROM listings WHERE id = ?');
        $stmt->execute([$id]);
        
        logActivity($payload['user_id'], 'listing_delete', 'listing', $id);
        
        successResponse('Listing deleted successfully');
    } catch (Exception $e) {
        errorResponse('Failed to delete listing: ' . $e->getMessage(), 500);
    }
}

/**
 * PATCH /api/listings/{id}/status
 * Update listing status (mark as sold, etc.)
 */
function updateListingStatus($id) {
    global $data;
    $payload = requireRole(['farmer']);
    $db = Database::getInstance();
    
    $status = $data['status'] ?? null;
    if (!$status) {
        errorResponse('Status is required');
    }
    
    $allowedStatuses = ['active', 'sold', 'suspended'];
    if (!in_array($status, $allowedStatuses)) {
        errorResponse('Invalid status. Must be: ' . implode(', ', $allowedStatuses));
    }
    
    $stmt = $db->prepare('SELECT * FROM listings WHERE id = ? AND farmer_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $listing = $stmt->fetch();
    
    if (!$listing) {
        errorResponse('Listing not found or unauthorized', 404);
    }
    
    try {
        $stmt = $db->prepare('UPDATE listings SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $id]);
        
        logActivity($payload['user_id'], 'listing_status_update', 'listing', $id, ['status' => $status]);
        
        $stmt = $db->prepare("SELECT l.*, u.name as farmer_name FROM listings l JOIN users u ON l.farmer_id = u.id WHERE l.id = ?");
        $stmt->execute([$id]);
        $listing = $stmt->fetch();
        $listing['created_at'] = date('c', strtotime($listing['created_at']));
        $listing['updated_at'] = date('c', strtotime($listing['updated_at']));
        
        successResponse('Listing status updated', $listing);
    } catch (Exception $e) {
        errorResponse('Failed to update listing status: ' . $e->getMessage(), 500);
    }
}
