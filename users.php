<?php
/**
 * AgriMarket API - Users Endpoints
 * 
 * Handles user-related operations:
 * - Get user profiles
 * - Search users
 * - Update user settings
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/users/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            getUser($uriParts[0]);
        }
        else {
            searchUsers();
        }
        break;
    case 'PUT':
        if (isset($uriParts[0]) && is_numeric($uriParts[0])) {
            updateUser($uriParts[0]);
        }
        else {
            errorResponse('Invalid request', 400);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/users
 * Search users with filters
 */
function searchUsers()
{
    $db = Database::getInstance();

    // Pagination
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);

    // Filters
    $search = $_GET['search'] ?? null;
    $role = $_GET['role'] ?? null;
    $location = $_GET['location'] ?? null;
    $isVerified = $_GET['is_verified'] ?? null;

    // Build query
    $where = ['u.is_active = 1'];
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

    if ($location) {
        $where[] = 'u.location LIKE ?';
        $params[] = "%$location%";
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

    // Get users
    $sql = "
        SELECT u.id, u.name, u.email, u.role, u.location, u.is_verified, u.created_at,
               (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count,
               (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_sales
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
 * GET /api/users/{id}
 * Get single user by ID
 */
function getUser($id)
{
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT u.id, u.name, u.email, u.role, u.location, u.is_verified, u.created_at,
               (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count,
               (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_sales
        FROM users u
        WHERE u.id = ? AND u.is_active = 1
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('User not found', 404);
    }

    $user['created_at'] = date('c', strtotime($user['created_at']));

    // Get active listings for farmers
    if ($user['role'] === 'farmer') {
        $stmt = $db->prepare("
            SELECT id, crop_name, quantity, unit, price, status, created_at
            FROM listings
            WHERE farmer_id = ? AND status = 'active'
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $user['active_listings'] = $stmt->fetchAll();

        foreach ($user['active_listings'] as &$listing) {
            $listing['created_at'] = date('c', strtotime($listing['created_at']));
        }
    }

    successResponse('User retrieved', $user);
}

/**
 * PUT /api/users/{id}
 * Update user profile (owner or admin only)
 */
function updateUser($id)
{
    $payload = authenticateRequest();

    // Check authorization (owner or admin)
    if ($payload['role'] !== 'admin' && $payload['user_id'] != $id) {
        errorResponse('Unauthorized', 403);
    }

    global $data;

    $db = Database::getInstance();

    // Check if user exists
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('User not found', 404);
    }

    // Build update query
    $allowedFields = ['name', 'location', 'phone'];
    $updates = [];
    $params = [];

    // Admin-only fields
    $adminFields = ['role', 'is_verified', 'is_active'];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }

    // Admin-only updates
    if ($payload['role'] === 'admin') {
        foreach ($adminFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $field === 'role' ? sanitizeInput($data[$field]) : ($data[$field] ? 1 : 0);
            }
        }
    }

    if (empty($updates)) {
        errorResponse('No fields to update');
    }

    $params[] = $id;

    try {
        $stmt = $db->prepare('
            UPDATE users SET ' . implode(', ', $updates) . '
            WHERE id = ?
        ');
        $stmt->execute($params);

        // Log activity
        logActivity($payload['user_id'], 'user_update', 'user', $id);

        // Get updated user
        $stmt = $db->prepare('
            SELECT id, name, email, role, location, phone, is_verified, is_active, created_at
            FROM users WHERE id = ?
        ');
        $stmt->execute([$id]);
        $updatedUser = $stmt->fetch();
        $updatedUser['created_at'] = date('c', strtotime($updatedUser['created_at']));

        successResponse('User updated successfully', $updatedUser);

    }
    catch (Exception $e) {
        errorResponse('Failed to update user: ' . $e->getMessage(), 500);
    }
}

/**
 * Log user activity
 */
function logActivity($userId, $action, $entityType = null, $entityId = null, $details = null)
{
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
