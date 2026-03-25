<?php
/**
 * AgriMarket API - Users Router
 */

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/users/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$id = is_numeric($action) ? (int)$action : null;

switch ($method) {
    case 'GET':
        if ($id) getUser($id);
        else searchUsers();
        break;
    case 'PUT':
        if ($id) updateUser($id);
        else errorResponse('Invalid request', 400);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function searchUsers() {
    $db = Database::getInstance();
    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $search = $_GET['search'] ?? null;
    $role = $_GET['role'] ?? null;
    $location = $_GET['location'] ?? null;

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

    $whereClause = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) as total FROM users u WHERE $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT u.id, u.name, u.email, u.role, u.location, u.is_verified, u.created_at, (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count, (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_sales FROM users u WHERE $whereClause ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

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

function getUser($id) {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT u.id, u.name, u.email, u.role, u.location, u.is_verified, u.created_at, (SELECT COUNT(*) FROM listings WHERE farmer_id = u.id) as listings_count, (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE farmer_id = u.id AND status = 'completed') as total_sales FROM users u WHERE u.id = ? AND u.is_active = 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) errorResponse('User not found', 404);
    $user['created_at'] = date('c', strtotime($user['created_at']));

    if ($user['role'] === 'farmer') {
        $stmt = $db->prepare("SELECT id, crop_name, quantity, unit, price, status, created_at FROM listings WHERE farmer_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$id]);
        $user['active_listings'] = $stmt->fetchAll();
        foreach ($user['active_listings'] as &$l) $l['created_at'] = date('c', strtotime($l['created_at']));
    }

    successResponse('User retrieved', $user);
}

function updateUser($id) {
    $payload = authenticateRequest();
    if ($payload['role'] !== 'admin' && $payload['user_id'] != $id) errorResponse('Unauthorized', 403);

    global $data;
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) errorResponse('User not found', 404);

    $allowedFields = ['name', 'location', 'phone'];
    $adminFields = ['role', 'is_verified', 'is_active'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = sanitizeInput($data[$field]);
        }
    }

    if ($payload['role'] === 'admin') {
        foreach ($adminFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $field === 'role' ? sanitizeInput($data[$field]) : ($data[$field] ? 1 : 0);
            }
        }
    }

    if (empty($updates)) errorResponse('No fields to update');
    $params[] = $id;

    try {
        $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        logActivity($payload['user_id'], 'user_update', 'user', $id);

        $stmt = $db->prepare('SELECT id, name, email, role, location, phone, is_verified, is_active, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        $user['created_at'] = date('c', strtotime($user['created_at']));

        successResponse('User updated successfully', $user);
    } catch (Exception $e) {
        errorResponse('Failed to update user: ' . $e->getMessage(), 500);
    }
}
