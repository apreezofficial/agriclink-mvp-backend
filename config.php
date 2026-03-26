<?php
/**
 * AgriMarket API - Database Configuration
 * 
 * This file handles database connection and provides utility functions
 * for all API endpoints.
 */

// Include CORS headers FIRST
require_once __DIR__ . '/cors.php';

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'agri_market');
define('DB_USER', 'root');
define('DB_PASS', '');

// JWT Configuration
define('JWT_SECRET', 'xxxxefnefineifnneinfnenn34i');
define('JWT_EXPIRY', 86400); // 24 hours

// Interswitch Sandbox Configuration
define('INTERSWITCH_ENV', 'sandbox');
define('INTERSWITCH_CLIENT_ID', 'IKIA261543BC9D633175EF09604872112B7063B5D1DE');
define('INTERSWITCH_CLIENT_SECRET', 'E5CF51ED5D92FD75F5ECAEFF14D9537372FC3FAB');
define('INTERSWITCH_MERCHANT_CODE', 'MX6072');
define('INTERSWITCH_TERMINAL_ID', '3TLP0001');
define('INTERSWITCH_REDIRECT_URL', 'https://agrilink.preciousadedokun.com.ng/api/integrations.php/payments/callback');
define('INTERSWITCH_PAYMENT_BASE_URL', 'https://sandbox.interswitchng.com');
define('INTERSWITCH_PASSPORT_BASE_URL', 'https://sandbox.interswitchng.com/passport');
define('INTERSWITCH_API_BASE_URL', 'https://sandbox.interswitchng.com/api/v1');

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

function interswitchConfig() {
    $resolvedRedirectUrl = INTERSWITCH_REDIRECT_URL ?: (getBaseUrl() . '/integrations.php/payments/callback');

    return [
        'env' => INTERSWITCH_ENV,
        'client_id' => INTERSWITCH_CLIENT_ID,
        'client_secret' => INTERSWITCH_CLIENT_SECRET,
        'merchant_code' => INTERSWITCH_MERCHANT_CODE,
        'terminal_id' => INTERSWITCH_TERMINAL_ID,
        'redirect_url' => $resolvedRedirectUrl,
        'payment_base_url' => INTERSWITCH_PAYMENT_BASE_URL,
        'passport_base_url' => INTERSWITCH_PASSPORT_BASE_URL,
        'api_base_url' => INTERSWITCH_API_BASE_URL,
    ];
}

function interswitchHttpRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'status' => $httpCode ?: 500,
            'error' => $curlError ?: 'Unknown cURL error',
            'data' => null,
        ];
    }

    $decoded = json_decode($response, true);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'error' => null,
        'data' => $decoded ?? $response,
    ];
}

function getInterswitchAccessToken() {
    $config = interswitchConfig();
    static $cachedToken = null;

    if ($cachedToken && ($cachedToken['expires_at'] ?? 0) > time() + 60) {
        return $cachedToken['token'];
    }

    $credentials = base64_encode($config['client_id'] . ':' . $config['client_secret']);
    $response = interswitchHttpRequest(
        'POST',
        $config['passport_base_url'] . '/oauth/token',
        [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        http_build_query(['grant_type' => 'client_credentials'])
    );

    if (!$response['success'] || !is_array($response['data'])) {
        $details = is_array($response['data']) ? $response['data'] : ['raw' => $response['data'], 'status' => $response['status'] ?? null, 'error' => $response['error'] ?? null];
        errorResponse('Unable to authenticate with Interswitch: ' . json_encode($details), 502);
    }

    $token = $response['data']['access_token'] ?? null;
    $expiresIn = (int)($response['data']['expires_in'] ?? 3600);

    if (!$token) {
        errorResponse('Invalid Interswitch token response: ' . json_encode($response['data']), 502);
    }

    $cachedToken = [
        'token' => $token,
        'expires_at' => time() + $expiresIn,
    ];

    return $token;
}

function interswitchAuthorizedJsonRequest($method, $url, $payload = null, $extraHeaders = []) {
    $token = getInterswitchAccessToken();
    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);

    return interswitchHttpRequest(
        $method,
        $url,
        $headers,
        $payload !== null ? json_encode($payload) : null
    );
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
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
    header('Access-Control-Expose-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json');
}

// CORS Headers - Set these FIRST before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Expose-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

setCorsHeaders();

// Activity Logging
function logActivity($userId, $action, $entityType = null, $entityId = null, $details = null) {
    $db = Database::getInstance();
    
    try {
        $detailsJson = is_array($details) ? json_encode($details) : $details;
        $stmt = $db->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $detailsJson,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Silently fail - don't break the main operation for logging failures
        error_log('Activity logging failed: ' . $e->getMessage());
    }
}
