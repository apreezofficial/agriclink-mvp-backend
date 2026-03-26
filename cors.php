<?php
/**
 * AgriMarket API - CORS Headers
 * 
 * This file MUST be included at the VERY TOP of every API file
 * before any other code or output to prevent CORS errors.
 */

// Set CORS headers immediately - before anything else
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control');
header('Access-Control-Expose-Headers: Content-Type, Authorization, X-Total-Count');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
