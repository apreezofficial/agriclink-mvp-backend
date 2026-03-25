<?php
/**
 * AgriMarket API - Third-Party Integrations
 * 
 * Mock implementations for third-party services:
 * - Payment Gateway (Flutterwave/Paystack style)
 * - SMS Notifications (Twilio style)
 * - Weather API
 * - Crop Price API
 * - Bank Account Verification
 */

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Route handling
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api/integrations/', '', $uri);
$uriParts = explode('/', $uri);

switch ($method) {
    case 'GET':
        if (isset($uriParts[0])) {
            if ($uriParts[0] === 'weather') {
                getWeather();
            } elseif ($uriParts[0] === 'crop-prices') {
                getCropPrices();
            } elseif ($uriParts[0] === 'exchange-rates') {
                getExchangeRates();
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
 * Initialize a payment transaction
 */
function initializePayment() {
    global $data;
    
    validateRequired(['amount', 'currency', 'customer_email'], $data);
    
    $amount = (float)$data['amount'];
    $currency = strtoupper($data['currency']);
    $customerEmail = sanitizeInput($data['customer_email']);
    $customerName = sanitizeInput($data['customer_name'] ?? '');
    $metadata = $data['metadata'] ?? [];
    
    if ($amount <= 0) {
        errorResponse('Amount must be greater than 0');
    }
    
    // Generate payment reference
    $reference = 'AGR-' . strtoupper(bin2hex(random_bytes(12)));
    
    // Mock payment link (in production, integrate with Flutterwave/Paystack)
    $response = [
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'status' => 'pending',
        'checkout_url' => "https://checkout.agrimarket.com/$reference",
        'qr_code' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($reference),
        'expires_at' => date('c', strtotime('+30 minutes')),
        'metadata' => $metadata
    ];
    
    successResponse('Payment initialized', $response);
}

/**
 * POST /api/integrations/payments/verify
 * Verify a payment transaction
 */
function verifyPayment() {
    global $data;
    
    validateRequired(['reference'], $data);
    
    $reference = sanitizeInput($data['reference']);
    
    // Mock verification (in production, call payment gateway API)
    // Simulate random success/failure based on reference hash
    $hash = crc32($reference);
    $isSuccessful = $hash % 10 !== 0; // 90% success rate
    
    if ($isSuccessful) {
        $response = [
            'reference' => $reference,
            'status' => 'successful',
            'amount' => rand(1000, 100000),
            'currency' => 'NGN',
            'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(8))),
            'processor_response' => 'Approved',
            'verified_at' => date('c')
        ];
        successResponse('Payment verified', $response);
    } else {
        errorResponse('Payment verification failed', 400);
    }
}

/**
 * POST /api/integrations/payments/card
 * Save a card for future payments
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
    $token = 'card_' . strtolower(bin2hex(random_bytes(16)));
    
    $response = [
        'token' => $token,
        'last4' => substr($cardNumber, -4),
        'type' => $cardType,
        'expiry' => sprintf('%02d/%02d', $expiryMonth, $expiryYear),
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
    
    // Nigerian bank codes (sample)
    $bankCodes = [
        '044' => 'Access Bank',
        '023' => 'Citi Bank',
        '063' => 'Diamond Bank',
        '215' => 'Ecobank',
        '050' => 'EcoBank',
        '070' => 'Fidelity Bank',
        '011' => 'First Bank',
        '058' => 'Guaranty Trust Bank (GTBank)',
        '030' => 'Heritage Bank',
        '082' => 'Keystone Bank',
        '014' => 'Providus Bank',
        '076' => 'Skye Bank',
        '101' => 'Stanbic IBTC Bank',
        '068' => 'Sterling Bank',
        '032' => 'Union Bank',
        '020' => 'United Bank for Africa (UBA)',
        '033' => 'United Bank for Africa (UBA)',
        '035' => 'Wema Bank',
        '057' => 'Zenith Bank'
    ];
    
    if (!isset($bankCodes[$bankCode])) {
        errorResponse('Invalid bank code');
    }
    
    // Mock account verification
    // In production, call Nigerian bank verification API
    $hash = crc32($bankCode . $accountNumber);
    $isValid = $hash % 5 !== 0; // 80% success rate
    
    if ($isValid) {
        // Generate mock account name
        $names = ['John Doe', 'Jane Smith', 'Michael Johnson', 'Sarah Williams', 'David Brown'];
        $accountName = $names[$hash % count($names)];
        
        $response = [
            'bank_code' => $bankCode,
            'bank_name' => $bankCodes[$bankCode],
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'verified' => true,
            'verified_at' => date('c')
        ];
        
        successResponse('Bank account verified', $response);
    } else {
        errorResponse('Could not verify account details');
    }
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
