<?php
/**
 * AgriMarket API - Notifications Router
 */

// Include CORS headers FIRST
require_once __DIR__ . '/../cors.php';

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/notifications/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';
$id = is_numeric($action) ? (int)$action : null;

switch ($method) {
    case 'GET':
        if ($action === 'unread-count') getUnreadCount();
        else getNotifications();
        break;
    case 'POST':
        if ($action === 'mark-read') markAsRead();
        elseif ($action === 'mark-all-read') markAllAsRead();
        else errorResponse('Invalid request', 400);
        break;
    case 'PUT':
        if ($id) updateNotification($id);
        else errorResponse('Invalid request', 400);
        break;
    case 'DELETE':
        if ($id) deleteNotification($id);
        elseif ($action === 'clear') clearAllNotifications();
        else errorResponse('Invalid request', 400);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function getNotifications() {
    $payload = authenticateRequest();
    $db = Database::getInstance();

    $page = $_GET['page'] ?? 1;
    $perPage = $_GET['per_page'] ?? 20;
    $pagination = paginate($page, $perPage);
    $type = $_GET['type'] ?? null;
    $isRead = $_GET['is_read'] ?? null;

    $where = 'n.user_id = ?';
    $params = [$payload['user_id']];

    if ($type) { $where .= ' AND n.type = ?'; $params[] = $type; }
    if ($isRead !== null) { $where .= ' AND n.is_read = ?'; $params[] = $isRead === 'true' ? 1 : 0; }

    $countSql = "SELECT COUNT(*) as total FROM notifications n WHERE $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $unreadSql = "SELECT COUNT(*) as unread FROM notifications n WHERE n.user_id = ? AND n.is_read = 0";
    $stmt = $db->prepare($unreadSql);
    $stmt->execute([$payload['user_id']]);
    $unreadCount = $stmt->fetch()['unread'];

    $sql = "SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pagination['per_page'];
    $params[] = $pagination['offset'];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    foreach ($notifications as &$n) $n['created_at'] = date('c', strtotime($n['created_at']));

    successResponse('Notifications retrieved', [
        'notifications' => $notifications,
        'unread_count' => (int)$unreadCount,
        'pagination' => ['page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'total' => (int)$total, 'pages' => ceil($total / $pagination['per_page'])]
    ]);
}

function getUnreadCount() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$payload['user_id']]);
    $count = $stmt->fetch()['count'];
    successResponse('Unread count retrieved', ['count' => (int)$count]);
}

function markAsRead() {
    $payload = authenticateRequest();
    global $data;
    validateRequired(['notification_ids'], $data);
    $notificationIds = $data['notification_ids'];

    if (!is_array($notificationIds) || empty($notificationIds)) errorResponse('notification_ids must be a non-empty array');

    $db = Database::getInstance();
    try {
        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders) AND user_id = ?");
        $params = array_merge($notificationIds, [$payload['user_id']]);
        $stmt->execute($params);
        successResponse('Notifications marked as read');
    } catch (Exception $e) { errorResponse('Failed to mark as read: ' . $e->getMessage(), 500); }
}

function markAllAsRead() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    try {
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        successResponse('All notifications marked as read');
    } catch (Exception $e) { errorResponse('Failed to mark as read: ' . $e->getMessage(), 500); }
}

function updateNotification($id) {
    $payload = authenticateRequest();
    global $data;
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $notification = $stmt->fetch();
    if (!$notification) errorResponse('Notification not found', 404);

    $allowedFields = ['is_read', 'title', 'message'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $field === 'is_read' ? ($data[$field] ? 1 : 0) : sanitizeInput($data[$field]);
        }
    }

    if (!empty($updates)) {
        $params[] = $id;
        $stmt = $db->prepare('UPDATE notifications SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
    }
    successResponse('Notification updated successfully');
}

function deleteNotification($id) {
    $payload = authenticateRequest();
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $payload['user_id']]);
    $notification = $stmt->fetch();
    if (!$notification) errorResponse('Notification not found', 404);

    try {
        $stmt = $db->prepare('DELETE FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        successResponse('Notification deleted successfully');
    } catch (Exception $e) { errorResponse('Failed to delete notification: ' . $e->getMessage(), 500); }
}

function clearAllNotifications() {
    $payload = authenticateRequest();
    $db = Database::getInstance();
    try {
        $stmt = $db->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$payload['user_id']]);
        successResponse('All notifications cleared');
    } catch (Exception $e) { errorResponse('Failed to clear notifications: ' . $e->getMessage(), 500); }
}
