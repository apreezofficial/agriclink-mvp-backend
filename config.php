<?php
/**
 * AgriMarket API - Database Configuration
 * 
 * This file handles database connection and provides utility functions
 * for all API endpoints.
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'agri_market');
define('DB_USER', 'root');
define('DB_PASS', '');

// JWT Configuration
define('JWT_SECRET', 'xxxxefnefineifnneinfnenn34i');
define('JWT_EXPIRY', 86400); // 24 hours

// API Response Helpers
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function successResponse($message, $data = null, $statusCode = 200) {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    jsonResponse($response, $statusCode);
}

function errorResponse($message, $statusCode = 400) {
    jsonResponse(['success' => false, 'error' => $message], $statusCode);
}

// Database Connection
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            errorResponse('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->connection;
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }
}

// JWT Token Management
class JWTToken {
    public static function generate($userId, $role) {
        $payload = [
            'user_id' => $userId,
            'role' => $role,
            'issued_at' => time(),
            'expires_at' => time() + JWT_EXPIRY
        ];
        
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = base64_encode(hash_hmac('sha256', $header . "." . $payloadEncoded, JWT_SECRET, true));
        
        return $header . "." . $payloadEncoded . "." . $signature;
    }

    public static function verify($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $signature = base64_encode(hash_hmac('sha256', $parts[0] . "." . $parts[1], JWT_SECRET, true));
        
        if ($signature !== $parts[2]) {
            return false;
        }

        $payload = json_decode(base64_decode($parts[1]), true);
        
        if ($payload['expires_at'] < time()) {
            return false;
        }

        return $payload;
    }
}

// Authentication Middleware
function authenticateRequest() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        errorResponse('Authorization header required', 401);
    }

    if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        errorResponse('Invalid authorization format', 401);
    }

    $token = $matches[1];
    $payload = JWTToken::verify($token);

    if (!$payload) {
        errorResponse('Invalid or expired token', 401);
    }

    return $payload;
}

// Role-based Authorization
function requireRole($allowedRoles) {
    $payload = authenticateRequest();
    
    if (!in_array($payload['role'], $allowedRoles)) {
        errorResponse('Unauthorized access', 403);
    }

    return $payload;
}

// Input Validation
function validateRequired($fields, $data) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        errorResponse('Missing required fields: ' . implode(', ', $missing), 400);
    }
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Pagination
function paginate($page, $perPage) {
    $page = max(1, (int)$page);
    $perPage = min(100, max(1, (int)$perPage));
    $offset = ($page - 1) * $perPage;
    
    return [
        'page' => $page,
        'per_page' => $perPage,
        'offset' => $offset
    ];
}

// CORS Headers
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

setCorsHeaders();
