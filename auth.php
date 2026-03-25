<?php
/**
 * AgriMarket API - Authentication Endpoints
 * 
 * Handles user registration, login, logout, profile management,
 * password reset, and token refresh.
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/auth/', '', $uri);
$uri = explode('/', $uri);

switch ($method) {
    case 'POST':
        switch ($uri[0]) {
            case 'register':
                register();
                break;
            case 'login':
                login();
                break;
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
            default:
                errorResponse('Endpoint not found', 404);
        }
        break;
    case 'GET':
        if (isset($uri[0]) && $uri[0] === 'me') {
            getProfile();
        }
        else {
            errorResponse('Endpoint not found', 404);
        }
        break;
    case 'PUT':
        if (isset($uri[0]) && $uri[0] === 'me') {
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
 * Register a new user
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

    // Validate role
    if (!in_array($role, ['farmer', 'buyer'])) {
        errorResponse('Invalid role. Must be farmer or buyer');
    }

    // Validate password strength
    if (strlen($password) < 6) {
        errorResponse('Password must be at least 6 characters');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email format');
    }

    $db = Database::getInstance();

    // Check if email already exists
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        errorResponse('Email already registered');
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    try {
        $db->beginTransaction();

        // Insert user
        $stmt = $db->prepare('
            INSERT INTO users (name, email, password, role, location)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$name, $email, $hashedPassword, $role, $location]);
        $userId = $db->lastInsertId();

        // Create wallet
        $stmt = $db->prepare('INSERT INTO wallets (user_id) VALUES (?)');
        $stmt->execute([$userId]);

        $db->commit();

        // Generate token
        $token = JWTToken::generate($userId, $role);

        // Get user data
        $stmt = $db->prepare('SELECT id, name, email, role, location, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $user['created_at'] = date('c', strtotime($user['created_at']));

        // Log activity
        logActivity($userId, 'user_register', 'user', $userId);

        successResponse('Registration successful', [
            'user' => $user,
            'token' => $token
        ], 201);

    }
    catch (Exception $e) {
        $db->rollBack();
        errorResponse('Registration failed: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/auth/login
 * Login user and return JWT token
 */
function login()
{
    global $data;

    validateRequired(['email', 'password'], $data);

    $email = strtolower(sanitizeInput($data['email']));
    $password = $data['password'];

    $db = Database::getInstance();

    // Get user
    $stmt = $db->prepare('
        SELECT id, name, email, password, role, location, is_active, created_at 
        FROM users WHERE email = ?
    ');
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

    // Generate token
    $token = JWTToken::generate($user['id'], $user['role']);

    // Remove password from response
    unset($user['password']);
    $user['created_at'] = date('c', strtotime($user['created_at']));

    // Log activity
    logActivity($user['id'], 'user_login', 'user', $user['id']);

    successResponse('Login successful', [
        'user' => $user,
        'token' => $token
    ]);
}

/**
 * GET /api/auth/me
 * Get current user profile
 */
function getProfile()
{
    $payload = authenticateRequest();

    $db = Database::getInstance();

    $stmt = $db->prepare('
        SELECT id, name, email, role, location, phone, is_verified, created_at
        FROM users WHERE id = ?
    ');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('User not found', 404);
    }

    $user['created_at'] = date('c', strtotime($user['created_at']));

    // Get wallet info
    $stmt = $db->prepare('SELECT balance, pending_balance FROM wallets WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    $wallet = $stmt->fetch();

    $user['wallet'] = $wallet;

    successResponse('Profile retrieved', $user);
}

/**
 * PUT /api/auth/me
 * Update current user profile
 */
function updateProfile()
{
    global $data;
    $payload = authenticateRequest();

    $db = Database::getInstance();

    // Build update query
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
        $stmt = $db->prepare('
            UPDATE users SET ' . implode(', ', $updates) . '
            WHERE id = ?
        ');
        $stmt->execute($params);

        // Get updated user
        $stmt = $db->prepare('
            SELECT id, name, email, role, location, phone, is_verified, created_at
            FROM users WHERE id = ?
        ');
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();

        $user['created_at'] = date('c', strtotime($user['created_at']));

        // Log activity
        logActivity($payload['user_id'], 'user_update', 'user', $payload['user_id']);

        successResponse('Profile updated', $user);

    }
    catch (Exception $e) {
        errorResponse('Update failed: ' . $e->getMessage(), 500);
    }
}

/**
 * POST /api/auth/refresh
 * Refresh JWT token
 */
function refreshToken()
{
    $payload = authenticateRequest();

    $db = Database::getInstance();

    // Check user still exists and is active
    $stmt = $db->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        errorResponse('User not found or inactive', 401);
    }

    // Generate new token
    $token = JWTToken::generate($payload['user_id'], $user['role']);

    successResponse('Token refreshed', ['token' => $token]);
}

/**
 * POST /api/auth/logout
 * Logout user (invalidate token - for logging purposes)
 */
function logout()
{
    $payload = authenticateRequest();

    // Log activity
    logActivity($payload['user_id'], 'user_logout', 'user', $payload['user_id']);

    successResponse('Logged out successfully');
}

/**
 * POST /api/auth/password-reset
 * Request password reset
 */
function requestPasswordReset()
{
    global $data;

    validateRequired(['email'], $data);

    $email = strtolower(sanitizeInput($data['email']));

    $db = Database::getInstance();

    // Check if user exists
    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success to prevent email enumeration
    if (!$user) {
        successResponse('If the email exists, a reset link will be sent');
        return;
    }

    // Generate reset token (store in database for verification)
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $stmt = $db->prepare('
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['id'],
        'password_reset_request',
        'user',
        $user['id'],
        json_encode(['token_expires' => $expiresAt])
    ]);

    // In production, send email with reset link
    // For now, return success
    successResponse('If the email exists, a reset link will be sent');
}

/**
 * POST /api/auth/password-reset-confirm
 * Confirm password reset with token
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

    // In production, validate token from database
    // For now, return error
    errorResponse('Invalid or expired reset token');
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
