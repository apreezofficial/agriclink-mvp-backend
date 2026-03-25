<?php
/**
 * AgriMarket API - Auth Router
 * 
 * Routes: POST /api/auth/register, POST /api/auth/login, GET /api/auth/me, etc.
 */

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Parse the URI to get the action
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);
$uri = str_replace('/api/auth/', '', $requestUri);
$uriParts = explode('/', $uri);
$action = $uriParts[0] ?? '';

// Route based on method and action
switch ($method) {
    case 'POST':
        // Check if action is in URL path or in request body
        if (!empty($action) && $action !== 'register' && $action !== 'login') {
            switch ($action) {
                case 'refresh':
                    refreshToken();
                    break;
                case 'logout':
                    logout();
                    break;
                case 'password-reset':
                    requestPasswordReset();
                    break;
                case 'password-reset-confirm':
                    confirmPasswordReset();
                    break;
                case 'password':
                    changePassword();
                    break;
                default:
                    errorResponse('Endpoint not found', 404);
            }
        }
        elseif (!empty($data['action'])) {
            // Action in request body
            switch ($data['action']) {
                case 'register':
                    register();
                    break;
                case 'login':
                    login();
                    break;
                default:
                    errorResponse('Invalid action', 400);
            }
        }
        else {
            // Default: treat as login/register based on fields present
            if (isset($data['email']) && isset($data['password'])) {
                login();
            }
            else {
                errorResponse('Invalid request. Provide email and password for login, or full registration details', 400);
            }
        }
        break;
    case 'GET':
        if ($action === 'me') {
            getProfile();
        }
        else {
            errorResponse('Endpoint not found', 404);
        }
        break;
    case 'PUT':
        if ($action === 'me') {
            updateProfile();
        }
        else {
            errorResponse('Endpoint not found', 404);
        }
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * POST /api/auth/register
 */
function register()
{
    global $data;
    validateRequired(['name', 'email', 'password', 'role', 'location'], $data);

    $name = sanitizeInput($data['name']);
    $email = strtolower(sanitizeInput($data['email']));
    $password = $data['password'];
    $role = sanitizeInput($data['role']);
    $location = sanitizeInput($data['location']);

    if (!in_array($role, ['farmer', 'buyer'])) {
        errorResponse('Invalid role. Must be farmer or buyer');
    }

    if (strlen($password) < 6) {
        errorResponse('Password must be at least 6 characters');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email format');
    }

    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        errorResponse('Email already registered');
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare('INSERT INTO users (name, email, password, role, location) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $hashedPassword, $role, $location]);
        $userId = $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO wallets (user_id) VALUES (?)');
        $stmt->execute([$userId]);

        $db->commit();

        $token = JWTToken::generate($userId, $role);

        $stmt = $db->prepare('SELECT id, name, email, role, location, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $user['created_at'] = date('c', strtotime($user['created_at']));

        logActivity($userId, 'user_register', 'user', $userId);

        successResponse('Registration successful', ['user' => $user, 'token' => $token], 201);
    }
    catch (Exception $e) {
        $db->rollBack();
        errorResponse('Registration failed: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/auth/login
 */
function login()
{
    global $data;
    validateRequired(['email', 'password'], $data);

    $email = strtolower(sanitizeInput($data['email']));
    $password = $data['password'];

    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT id, name, email, password, role, location, is_active, created_at FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('Invalid email or password');
    }

    if (!$user['is_active']) {
        errorResponse('Account is suspended. Please contact support.');
    }

    if (!password_verify($password, $user['password'])) {
        errorResponse('Invalid email or password');
    }

    $token = JWTToken::generate($user['id'], $user['role']);
    unset($user['password']);
    $user['created_at'] = date('c', strtotime($user['created_at']));

    logActivity($user['id'], 'user_login', 'user', $user['id']);

    successResponse('Login successful', ['user' => $user, 'token' => $token]);
}

/**
 * GET /api/auth/me
 */
function getProfile()
{
    $payload = authenticateRequest();
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT id, name, email, role, location, phone, is_verified, created_at FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('User not found', 404);
    }

    $user['created_at'] = date('c', strtotime($user['created_at']));

    $stmt = $db->prepare('SELECT balance, pending_balance FROM wallets WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    $wallet = $stmt->fetch();
    $user['wallet'] = $wallet;

    successResponse('Profile retrieved', $user);
}

/**
 * PUT /api/auth/me
 */
function updateProfile()
{
    global $data;
    $payload = authenticateRequest();
    $db = Database::getInstance();

    $allowedFields = ['name', 'location', 'phone'];
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

    $params[] = $payload['user_id'];

    try {
        $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);

        $stmt = $db->prepare('SELECT id, name, email, role, location, phone, is_verified, created_at FROM users WHERE id = ?');
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();
        $user['created_at'] = date('c', strtotime($user['created_at']));

        logActivity($payload['user_id'], 'user_update', 'user', $payload['user_id']);
        successResponse('Profile updated', $user);
    }
    catch (Exception $e) {
        errorResponse('Update failed: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/auth/refresh
 */
function refreshToken()
{
    $payload = authenticateRequest();
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        errorResponse('User not found or inactive', 401);
    }

    $token = JWTToken::generate($payload['user_id'], $user['role']);
    successResponse('Token refreshed', ['token' => $token]);
}

/**
 * POST /api/auth/logout
 */
function logout()
{
    $payload = authenticateRequest();
    logActivity($payload['user_id'], 'user_logout', 'user', $payload['user_id']);
    successResponse('Logged out successfully');
}

/**
 * POST /api/auth/password-reset
 */
function requestPasswordReset()
{
    global $data;
    validateRequired(['email'], $data);

    $email = strtolower(sanitizeInput($data['email']));
    $db = Database::getInstance();

    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        successResponse('If the email exists, a reset link will be sent');
        return;
    }

    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $stmt = $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user['id'], 'password_reset_request', 'user', $user['id'], json_encode(['token_expires' => $expiresAt])]);

    successResponse('If the email exists, a reset link will be sent');
}

/**
 * POST /api/auth/password-reset-confirm
 */
function confirmPasswordReset()
{
    global $data;
    validateRequired(['token', 'new_password'], $data);

    $token = sanitizeInput($data['token']);
    $newPassword = $data['new_password'];

    if (strlen($newPassword) < 6) {
        errorResponse('Password must be at least 6 characters');
    }

    errorResponse('Invalid or expired reset token');
}

/**
 * POST /api/auth/password
 * Change password for authenticated user
 */
function changePassword()
{
    global $data;
    $payload = authenticateRequest();

    validateRequired(['current_password', 'new_password'], $data);

    $currentPassword = $data['current_password'];
    $newPassword = $data['new_password'];

    if (strlen($newPassword) < 6) {
        errorResponse('New password must be at least 6 characters');
    }

    $db = Database::getInstance();

    // Verify current password
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!password_verify($currentPassword, $user['password'])) {
        errorResponse('Current password is incorrect');
    }

    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$hashedPassword, $payload['user_id']]);

    logActivity($payload['user_id'], 'password_change', 'user', $payload['user_id']);
    successResponse('Password changed successfully');
}
