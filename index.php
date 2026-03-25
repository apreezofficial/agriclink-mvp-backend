/**
 * AgriMarket API - Main Router
 * 
 * Routes all API requests to the appropriate handlers
 * Uses pretty URLs: /api/auth/register, /api/listings, etc.
 */

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get the request URI and remove query string
$requestUri = $_SERVER['REQUEST_URI'];
$requestUri = parse_url($requestUri, PHP_URL_PATH);

// Remove leading/trailing slashes
$requestUri = trim($requestUri, '/');

// Route to the correct API
if (strpos($requestUri, 'api/auth') === 0) {
    require_once __DIR__ . '/auth/register.php';
} elseif (strpos($requestUri, 'api/listings') === 0) {
    require_once __DIR__ . '/listings/index.php';
} elseif (strpos($requestUri, 'api/orders') === 0) {
    require_once __DIR__ . '/orders/index.php';
} elseif (strpos($requestUri, 'api/wallet') === 0) {
    require_once __DIR__ . '/wallet/index.php';
} elseif (strpos($requestUri, 'api/users') === 0) {
    require_once __DIR__ . '/users/index.php';
} elseif (strpos($requestUri, 'api/notifications') === 0) {
    require_once __DIR__ . '/notifications/index.php';
} elseif (strpos($requestUri, 'api/admin') === 0) {
    require_once __DIR__ . '/admin/index.php';
} elseif (strpos($requestUri, 'api/integrations') === 0) {
    require_once __DIR__ . '/integrations/index.php';
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'API endpoint not found']);
}
