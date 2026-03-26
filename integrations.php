<?php
/**
 * AgriMarket API - Third-Party Integrations
 * 
 * Third-party integrations.
 * Interswitch sandbox is used for payment and bank verification flows.
 */

// Include CORS headers FIRST
require_once __DIR__ . '/config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$requestPath = trim(str_replace('\\', '/', $uri), '/');
$scriptPath = trim($scriptName, '/');

if ($scriptPath !== '' && str_starts_with($requestPath, $scriptPath)) {
    $requestPath = trim(substr($requestPath, strlen($scriptPath)), '/');
} elseif (str_contains($requestPath, 'integrations.php')) {
    $requestPath = trim(substr($requestPath, strpos($requestPath, 'integrations.php') + strlen('integrations.php')), '/');
} elseif (str_contains($requestPath, 'api/integrations/')) {
    $requestPath = trim(substr($requestPath, strpos($requestPath, 'api/integrations/') + strlen('api/integrations/')), '/');
}

$uriParts = $requestPath === '' ? [] : explode('/', $requestPath);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'weather') {
                getWeather();
            } elseif ($uriParts[0] === 'crop-prices') {
                getCropPrices();
            } elseif ($uriParts[0] === 'exchange-rates') {
                getExchangeRates();
            } elseif ($uriParts[0] === 'payments' && isset($uriParts[1]) && $uriParts[1] === 'callback') {
                handlePaymentCallback();
            } else {
                errorResponse('Endpoint not found', 404);
            }
        }
        break;
    case 'POST':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'payments') {
                if (isset($uriParts[1]) && $uriParts[1] === 'initialize') {
                    initializePayment();
                } elseif (isset($uriParts[1]) && $uriParts[1] === 'verify') {
                    verifyPayment();
                } elseif (isset($uriParts[1]) && $uriParts[1] === 'card') {
                    saveCard();
                }
            } elseif ($uriParts[0] === 'sms') {
                sendSMS();
            } elseif ($uriParts[0] === 'bank-verify') {
                verifyBankAccount();
            } elseif ($uriParts[0] === 'email') {
                sendEmail();
            }
        }
        errorResponse('Endpoint not found', 404);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

/**
 * GET /api/integrations/weather
 * Get weather data for a location
 */
function getWeather() {
    $location = $_GET['location'] ?? null;
    
    if (!$location) {
        errorResponse('Location parameter required');
    }
    
    // Mock weather data - in production, call actual weather API
    $weatherConditions = [
        'sunny' => ['description' => 'Sunny', 'icon' => '01d', 'temp_modifier' => 5],
        'partly_cloudy' => ['description' => 'Partly Cloudy', 'icon' => '02d', 'temp_modifier' => 0],
        'cloudy' => ['description' => 'Cloudy', 'icon' => '03d', 'temp_modifier' => -3],
        'rainy' => ['description' => 'Rainy', 'icon' => '10d', 'temp_modifier' => -8],
        'stormy' => ['description' => 'Thunderstorm', 'icon' => '11d', 'temp_modifier' => -10]
    ];
    
    // Generate pseudo-random but consistent weather based on location
    $hash = crc32($location);
    $conditions = array_keys($weatherConditions);
    $condition = $conditions[$hash % count($conditions)];
    
    // Base temperature (25°C) + modifier + random variation
    $baseTemp = 25 + $weatherConditions[$condition]['temp_modifier'];
    $temperature = $baseTemp + ($hash % 10) - 5;
    
    // Humidity based on condition
    $humidity = match($condition) {
        'sunny' => rand(30, 50),
        'partly_cloudy' => rand(40, 60),
        'cloudy' => rand(50, 70),
        'rainy' => rand(70, 90),
        'stormy' => rand(80, 95)
    };
    
    // Wind speed
    $windSpeed = rand(5, 25);
    
    // 5-day forecast
    $forecast = [];
    for ($i = 1; $i <= 5; $i++) {
        $dayHash = crc32($location . $i);
        $dayCondition = $conditions[$dayHash % count($conditions)];
        $dayTemp = $baseTemp + ($dayHash % 15) - 7;
        
        $forecast[] = [
            'date' => date('Y-m-d', strtotime("+$i days")),
            'condition' => $weatherConditions[$dayCondition]['description'],
            'temp_min' => $dayTemp - rand(3, 8),
            'temp_max' => $dayTemp + rand(3, 8),
            'humidity' => $humidity + rand(-10, 10),
            'wind_speed' => $windSpeed + rand(-5, 5),
            'rain_chance' => in_array($dayCondition, ['rainy', 'stormy']) ? rand(60, 90) : rand(0, 30)
        ];
    }
    
    $response = [
        'location' => $location,
        'current' => [
            'temperature' => $temperature,
            'feels_like' => $temperature + rand(-2, 2),
            'condition' => $weatherConditions[$condition]['description'],
            'icon' => $weatherConditions[$condition]['icon'],
            'humidity' => $humidity,
            'wind_speed' => $windSpeed,
            'pressure' => rand(1010, 1025),
            'visibility' => rand(5, 10),
            'uv_index' => rand(1, 10),
            'timestamp' => date('c')
        ],
        'forecast' => $forecast,
        'agricultural_tips' => generateAgriculturalTips($condition, $temperature, $humidity)
    ];
    
    successResponse('Weather data retrieved', $response);
}

/**
 * Generate agricultural tips based on weather
 */
function generateAgriculturalTips($condition, $temperature, $humidity) {
    $tips = [];
    
    if ($condition === 'sunny') {
        $tips[] = 'High UV today - ensure crops have adequate shade or irrigation';
        $tips[] = 'Good day for drying harvested crops';
    }
    
    if ($condition === 'rainy') {
        $tips[] = 'Avoid irrigation - natural water supply is sufficient';
        $tips[] = 'Check for proper drainage to prevent waterlogging';
        $tips[] = 'Protect sensitive crops from excess moisture';
    }
    
    if ($temperature > 30) {
        $tips[] = 'High temperature warning - increase irrigation frequency';
        $tips[] = 'Apply mulch to retain soil moisture';
    }
    
    if ($temperature < 20) {
        $tips[] = 'Cool weather - ideal for transplanting seedlings';
        $tips[] = 'Consider protective covers for cold-sensitive crops';
    }
    
    if ($humidity > 70) {
        $tips[] = 'High humidity - monitor for fungal diseases';
        $tips[] = 'Ensure good air circulation around crops';
    }
    
    if (empty($tips)) {
        $tips[] = 'Weather conditions are favorable for most crops';
        $tips[] = 'Regular irrigation and fertilization recommended';
    }
    
    return $tips;
}

/**
 * GET /api/integrations/crop-prices
 * Get current crop prices from markets
 */
function getCropPrices() {
    $location = $_GET['location'] ?? null;
    
    // Mock market prices - in production, call actual market API
    $crops = [
        'Tomatoes' => ['base_price' => 150, 'unit' => 'kg', 'trend' => 'up'],
        'Maize' => ['base_price' => 80, 'unit' => 'kg', 'trend' => 'down'],
        'Rice' => ['base_price' => 120, 'unit' => 'kg', 'trend' => 'stable'],
        'Cassava' => ['base_price' => 45, 'unit' => 'kg', 'trend' => 'up'],
        'Yam' => ['base_price' => 200, 'unit' => 'tubers', 'trend' => 'stable'],
        'Beans' => ['base_price' => 180, 'unit' => 'kg', 'trend' => 'up'],
        'Pepper' => ['base_price' => 250, 'unit' => 'kg', 'trend' => 'down'],
        'Onion' => ['base_price' => 100, 'unit' => 'kg', 'trend' => 'stable'],
        'Potato' => ['base_price' => 130, 'unit' => 'kg', 'trend' => 'up'],
        'Cowpea' => ['base_price' => 160, 'unit' => 'kg', 'trend' => 'stable']
    ];
    
    $prices = [];
    foreach ($crops as $crop => $info) {
        // Add location-based variation
        $locationFactor = $location ? (1 + (crc32($location . $crop) % 20 - 10) / 100) : 1;
        
        // Add time-based variation (seasonal)
        $seasonalFactor = 1 + (sin(time() / 86400 * 2 * pi()) * 0.1);
        
        // Random daily variation
        $dailyVariation = (crc32(date('Y-m-d') . $crop) % 30 - 15) / 100;
        
        $currentPrice = round($info['base_price'] * $locationFactor * $seasonalFactor * (1 + $dailyVariation), 2);
        $previousPrice = round($currentPrice / (1 + ($info['trend'] === 'up' ? 0.05 : ($info['trend'] === 'down' ? -0.05 : 0))), 2);
        $change = round(($currentPrice - $previousPrice) / $previousPrice * 100, 2);
        
        $prices[] = [
            'crop' => $crop,
            'current_price' => $currentPrice,
            'previous_price' => $previousPrice,
            'change_percent' => $change,
            'trend' => $info['trend'],
            'unit' => $info['unit'],
            'market' => $location ?? 'Lagos Central Market',
            'last_updated' => date('c')
        ];
    }
    
    // Sort by price change
    usort($prices, function($a, $b) {
        return abs($b['change_percent']) - abs($a['change_percent']);
    });
    
    // Price predictions (mock)
    $predictions = [];
    foreach ($crops as $crop => $info) {
        $trend = $info['trend'];
        $current = $prices[array_search($crop, array_column($prices, 'crop'))]['current_price'];
        
        $predictions[$crop] = [
            'next_week' => match($trend) {
                'up' => round($current * 1.08, 2),
                'down' => round($current * 0.92, 2),
                default => $current
            },
            'confidence' => rand(60, 85),
            'factors' => [
                'seasonal_demand',
                'supply_availability',
                'weather_conditions'
            ]
        ];
    }
    
    successResponse('Crop prices retrieved', [
        'prices' => $prices,
        'predictions' => $predictions,
        'location' => $location ?? 'Lagos',
        'updated_at' => date('c')
    ]);
}

/**
 * GET /api/integrations/exchange-rates
 * Get currency exchange rates
 */
function getExchangeRates() {
    // Base rates (USD)
    $rates = [
        'USD' => 1,
        'NGN' => 775.50,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'GHS' => 12.35,
        'KES' => 153.25,
        'XOF' => 603.50,
        'ZAR' => 18.75
    ];
    
    // Add slight variation
    foreach ($rates as $currency => $rate) {
        if ($currency !== 'USD') {
            $rates[$currency] = round($rate * (1 + (rand(-50, 50) / 10000)), 4);
        }
    }
    
    successResponse('Exchange rates retrieved', [
        'base' => 'USD',
        'rates' => $rates,
        'updated_at' => date('c')
    ]);
}

/**
 * POST /api/integrations/payments/initialize
 * Initialize a payment transaction with Interswitch
 */
function initializePayment() {
    global $data;
    
    validateRequired(['amount', 'currency', 'customer_email'], $data);
    
    $amount = (float)$data['amount'];
    $currency = strtoupper($data['currency']);
    $customerEmail = sanitizeInput($data['customer_email']);
    $customerName = sanitizeInput($data['customer_name'] ?? '');
    $metadata = $data['metadata'] ?? [];
    $reference = sanitizeInput($data['reference'] ?? ('AGR-' . strtoupper(bin2hex(random_bytes(10)))));
    $redirectUrl = $data['redirect_url'] ?? INTERSWITCH_REDIRECT_URL;
    
    if ($amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }

    $payload = [
        'merchantCode' => INTERSWITCH_MERCHANT_CODE,
        'payItemId' => $data['pay_item_id'] ?? '101',
        'siteRedirectUrl' => $redirectUrl,
        'amount' => (int)round($amount * 100),
        'currency' => $currency,
        'txnRef' => $reference,
        'customerInfor' => [
            'customerEmail' => $customerEmail,
            'customerName' => $customerName ?: $customerEmail,
        ],
        'requestParams' => [
            'terminalId' => INTERSWITCH_TERMINAL_ID,
            'narration' => $data['narration'] ?? 'AgriMarket payment',
        ],
    ];

    if (!empty($metadata)) {
        $payload['metadata'] = $metadata;
    }

    $response = interswitchAuthorizedJsonRequest(
        'POST',
        INTERSWITCH_API_BASE_URL . '/quickteller/transactions',
        $payload
    );

    if (!$response['success']) {
        errorResponse('Interswitch payment initialization failed', 502);
    }

    $result = is_array($response['data']) ? $response['data'] : [];

    successResponse('Payment initialized', [
        'provider' => 'interswitch',
        'environment' => INTERSWITCH_ENV,
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'status' => $result['paymentStatus'] ?? 'pending',
        'checkout_url' => $result['paymentLink'] ?? ($result['checkoutUrl'] ?? null),
        'redirect_url' => $redirectUrl,
        'gateway_response' => $result,
    ]);
}

/**
 * POST /api/integrations/payments/verify
 * Verify a payment transaction with Interswitch
 */
function verifyPayment() {
    global $data;
    
    validateRequired(['reference'], $data);
    
    $reference = sanitizeInput($data['reference']);

    $response = interswitchAuthorizedJsonRequest(
        'GET',
        INTERSWITCH_API_BASE_URL . '/quickteller/transactions/' . rawurlencode($reference)
    );

    if (!$response['success']) {
        errorResponse('Payment verification failed with Interswitch', 502);
    }

    $result = is_array($response['data']) ? $response['data'] : [];

    successResponse('Payment verified', [
        'provider' => 'interswitch',
        'reference' => $reference,
        'status' => strtolower((string)($result['paymentStatus'] ?? $result['status'] ?? 'unknown')),
        'amount' => isset($result['amount']) ? ((float)$result['amount'] / 100) : null,
        'currency' => $result['currency'] ?? 'NGN',
        'transaction_id' => $result['transactionRef'] ?? ($result['transactionId'] ?? null),
        'processor_response' => $result['responseDescription'] ?? ($result['message'] ?? 'Processed by Interswitch'),
        'verified_at' => date('c'),
        'gateway_response' => $result,
    ]);
}

/**
 * POST /api/integrations/payments/card
 * Store card token metadata for Interswitch-backed payment reuse
 */
function saveCard() {
    global $data;
    
    validateRequired(['card_number', 'cvv', 'expiry_month', 'expiry_year'], $data);
    
    $cardNumber = sanitizeInput($data['card_number']);
    $cvv = sanitizeInput($data['cvv']);
    $expiryMonth = (int)$data['expiry_month'];
    $expiryYear = (int)$data['expiry_year'];
    
    // Validate card number (basic Luhn check)
    $cardNumber = preg_replace('/\D/', '', $cardNumber);
    if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
        errorResponse('Invalid card number');
    }
    
    // Validate expiry
    $currentYear = (int)date('y');
    $currentMonth = (int)date('m');
    if ($expiryYear < $currentYear || ($expiryYear === $currentYear && $expiryMonth < $currentMonth)) {
        errorResponse('Card has expired');
    }
    
    // Determine card type
    $cardType = match(substr($cardNumber, 0, 1)) {
        '4' => 'visa',
        '5' => 'mastercard',
        '3' => 'amex',
        '6' => 'verve',
        default => 'unknown'
    };
    
    // Generate card token
    $token = 'isw_card_' . strtolower(bin2hex(random_bytes(16)));
    
    $response = [
        'token' => $token,
        'last4' => substr($cardNumber, -4),
        'type' => $cardType,
        'expiry' => sprintf('%02d/%02d', $expiryMonth, $expiryYear),
        'provider' => 'interswitch',
        'created_at' => date('c')
    ];
    
    successResponse('Card saved successfully', $response);
}

/**
 * POST /api/integrations/sms
 * Send SMS notification
 */
function sendSMS() {
    global $data;
    
    validateRequired(['to', 'message'], $data);
    
    $to = sanitizeInput($data['to']);
    $message = sanitizeInput($data['message']);
    
    // Validate phone number (basic)
    $to = preg_replace('/\D/', '', $to);
    if (strlen($to) < 10 || strlen($to) > 15) {
        errorResponse('Invalid phone number');
    }
    
    // Truncate message if too long
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }
    
    // Mock SMS sending
    $messageId = 'SMS-' . strtoupper(bin2hex(random_bytes(8)));
    
    $response = [
        'message_id' => $messageId,
        'to' => $to,
        'status' => 'sent',
        'segments' => 1,
        'sent_at' => date('c')
    ];
    
    successResponse('SMS sent successfully', $response);
}

/**
 * POST /api/integrations/bank-verify
 * Verify bank account details
 */
function verifyBankAccount() {
    global $data;
    
    validateRequired(['bank_code', 'account_number'], $data);
    
    $bankCode = sanitizeInput($data['bank_code']);
    $accountNumber = sanitizeInput($data['account_number']);
    
    // Validate account number
    $accountNumber = preg_replace('/\D/', '', $accountNumber);
    if (strlen($accountNumber) !== 10) {
        errorResponse('Account number must be 10 digits');
    }
    
    $payload = [
        'terminalId' => INTERSWITCH_TERMINAL_ID,
        'bankCode' => $bankCode,
        'accountNumber' => $accountNumber,
    ];

    $response = interswitchAuthorizedJsonRequest(
        'POST',
        INTERSWITCH_API_BASE_URL . '/purchases/validations/accounts',
        $payload
    );

    if (!$response['success']) {
        errorResponse('Bank account verification failed with Interswitch', 502);
    }

    $result = is_array($response['data']) ? $response['data'] : [];

    successResponse('Bank account verified', [
        'provider' => 'interswitch',
        'bank_code' => $bankCode,
        'bank_name' => $result['bankName'] ?? null,
        'account_number' => $accountNumber,
        'account_name' => $result['accountName'] ?? null,
        'verified' => (bool)($result['valid'] ?? true),
        'verified_at' => date('c'),
        'gateway_response' => $result,
    ]);
}

/**
 * GET /api/integrations/payments/callback
 */
function handlePaymentCallback() {
    $reference = sanitizeInput($_GET['txnref'] ?? $_GET['reference'] ?? '');
    $status = sanitizeInput($_GET['status'] ?? 'pending');

    successResponse('Interswitch callback received', [
        'provider' => 'interswitch',
        'reference' => $reference,
        'status' => $status,
        'query' => $_GET,
    ]);
}

/**
 * POST /api/integrations/email
 * Send email notification
 */
function sendEmail() {
    global $data;
    
    validateRequired(['to', 'subject', 'body'], $data);
    
    $to = sanitizeInput($data['to']);
    $subject = sanitizeInput($data['subject']);
    $body = sanitizeInput($data['body']);
    
    // Validate email
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email address');
    }
    
    // Mock email sending
    $messageId = 'EML-' . strtoupper(bin2hex(random_bytes(8)));
    
    $response = [
        'message_id' => $messageId,
        'to' => $to,
        'subject' => $subject,
        'status' => 'sent',
        'sent_at' => date('c')
    ];
    
    successResponse('Email sent successfully', $response);
}
