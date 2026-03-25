# AgriMarket API Documentation

Complete API documentation for the AgriMarket PHP backend with MySQL database.

## Base URL
```
http://localhost/agri-market1/backend/api/
```

## Authentication
All protected endpoints require a JWT token in the Authorization header:
```
Authorization: Bearer <token>
```

## Common Headers
```
Content-Type: application/json
```

---

## Endpoints Overview

| Module | Endpoints | Description |
|--------|-----------|-------------|
| **Auth** | register, login, profile, logout, password-reset | User authentication & profile |
| **Listings** | CRUD + search | Farm produce listings |
| **Orders** | CRUD + status management | Order processing with escrow |
| **Wallet** | balance, transactions, bank-accounts, topup, withdraw | Financial management |
| **Users** | search, profile | User management |
| **Notifications** | CRUD + mark read | User notifications |
| **Admin** | dashboard, user management, moderation | Admin controls |
| **Integrations** | weather, crop-prices, payments, sms, bank-verify | Third-party services |

---

## 1. Authentication API (`auth.php`)

### POST /api/auth/register
Register a new user.

**Request:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "role": "farmer",  // or "buyer"
  "location": "Lagos, Nigeria"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "farmer",
      "location": "Lagos, Nigeria",
      "is_verified": false,
      "created_at": "2024-01-15T10:30:00Z"
    },
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
  }
}
```

### POST /api/auth/login
Login and get JWT token.

**Request:**
```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

### GET /api/auth/me
Get current user profile (Protected).

### PUT /api/auth/me
Update current user profile (Protected).

**Request:**
```json
{
  "name": "John Updated",
  "location": "Abuja, Nigeria",
  "phone": "+2348012345678"
}
```

### POST /api/auth/logout
Logout current user (Protected).

### POST /api/auth/refresh
Refresh JWT token (Protected).

### POST /api/auth/password-reset
Request password reset.

**Request:**
```json
{
  "email": "john@example.com"
}
```

---

## 2. Listings API (`listings.php`)

### GET /api/listings
Get all active listings with filters.

**Query Parameters:**
- `page` (default: 1)
- `per_page` (default: 20)
- `status` (active/sold/suspended)
- `crop` - filter by crop name
- `location` - filter by location
- `farmer_id` - filter by farmer
- `min_price`, `max_price` - price range
- `min_quantity`, `max_quantity` - quantity range

### GET /api/listings/{id}
Get single listing details.

### GET /api/listings/my
Get current farmer's listings (Protected, Farmer only).

### GET /api/listings/search
Advanced search with sorting.

**Query Parameters:**
- `q` - search query
- `crop` - filter by crop
- `location` - filter by location
- `sort_by` (created_at, price, quantity, crop_name)
- `sort_order` (ASC, DESC)

### POST /api/listings
Create new listing (Protected, Farmer only).

**Request:**
```json
{
  "crop_name": "Tomatoes",
  "quantity": 100,
  "unit": "kg",
  "price": 150,
  "location": "Owerri, Imo",
  "description": "Fresh tomatoes from my farm",
  "image_url": "https://example.com/tomatoes.jpg"
}
```

### PUT /api/listings/{id}
Update listing (Protected, Owner only).

### DELETE /api/listings/{id}
Delete listing (Protected, Owner only).

---

## 3. Orders API (`orders.php`)

### GET /api/orders
Get all orders with filters (Protected).

**Query Parameters:**
- `page`, `per_page`
- `status` (pending/accepted/completed/cancelled)
- `escrow_status` (held/released/refunded)
- `buyer_id`, `farmer_id`

### GET /api/orders/my
Get current user's orders (Protected).

### GET /api/orders/{id}
Get order details (Protected).

### POST /api/orders/create
Create order from listing (Protected, Buyer only).

**Request:**
```json
{
  "listing_id": 1,
  "quantity": 50,
  "notes": "Please deliver in the morning"
}
```

### POST /api/orders/accept
Farmer accepts order (Protected, Farmer only).

**Request:**
```json
{
  "order_id": 1
}
```

### POST /api/orders/reject
Farmer rejects order (Protected, Farmer only).

**Request:**
```json
{
  "order_id": 1,
  "reason": "Out of stock"
}
```

### POST /api/orders/confirm-delivery
Buyer confirms delivery (Protected, Buyer only).

**Request:**
```json
{
  "order_id": 1
}
```

---

## 4. Wallet API (`wallet.php`)

### GET /api/wallet
Get wallet balance and details (Protected).

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 1,
    "balance": 50000,
    "pending_balance": 5000,
    "total_earned": 150000,
    "total_spent": 80000,
    "total_withdrawn": 20000,
    "pending_in_escrow": 10000,
    "recent_transactions": [...],
    "created_at": "2024-01-01T00:00:00Z"
  }
}
```

### GET /api/wallet/transactions
Get transaction history (Protected).

**Query Parameters:**
- `page`, `per_page`
- `type` (payment/escrow_release/withdrawal/refund)
- `status` (pending/completed/failed)

### GET /api/wallet/bank-accounts
Get saved bank accounts (Protected).

### POST /api/wallet/bank-accounts
Add bank account (Protected).

**Request:**
```json
{
  "bank_name": "GT Bank",
  "account_number": "0123456789",
  "account_name": "John Doe",
  "is_default": true
}
```

### PUT /api/wallet/bank-accounts/{id}
Update bank account (Protected).

### DELETE /api/wallet/bank-accounts/{id}
Delete bank account (Protected).

### POST /api/wallet/topup
Top up wallet (Protected, Buyer only).

**Request:**
```json
{
  "amount": 10000,
  "payment_method": "card",
  "card_last4": "1234"
}
```

### POST /api/wallet/withdraw
Withdraw funds (Protected).

**Request:**
```json
{
  "amount": 5000,
  "bank_account_id": 1
}
```

---

## 5. Users API (`users.php`)

### GET /api/users
Search users with filters (Protected).

**Query Parameters:**
- `page`, `per_page`
- `search` - search by name/email
- `role` (farmer/buyer)
- `location` - filter by location
- `is_verified` (true/false)

### GET /api/users/{id}
Get user profile with stats.

### PUT /api/users/{id}
Update user (Protected, Owner/Admin only).

---

## 6. Notifications API (`notifications.php`)

### GET /api/notifications
Get user notifications (Protected).

**Query Parameters:**
- `page`, `per_page`
- `type` (order/payment/system)
- `is_read` (true/false)

### GET /api/notifications/unread-count
Get unread count (Protected).

### POST /api/notifications/mark-read
Mark notifications as read (Protected).

**Request:**
```json
{
  "notification_ids": [1, 2, 3]
}
```

### POST /api/notifications/mark-all-read
Mark all as read (Protected).

### DELETE /api/notifications/{id}
Delete notification (Protected).

### DELETE /api/notifications/clear
Clear all notifications (Protected).

---

## 7. Admin API (`admin.php`)

### GET /api/admin/dashboard
Get dashboard statistics (Protected, Admin only).

**Response:**
```json
{
  "success": true,
  "data": {
    "users": {
      "total": 1000,
      "farmers": 600,
      "buyers": 390,
      "suspended": 10
    },
    "listings": {
      "total": 500,
      "active": 300,
      "suspended": 20,
      "sold": 180
    },
    "orders": {
      "total": 200,
      "pending": 30,
      "accepted": 50,
      "completed": 110,
      "cancelled": 10,
      "total_revenue": 5000000,
      "total_fees": 100000
    },
    "escrow": {
      "held": 200000,
      "released": 4500000,
      "refunded": 50000
    },
    "recent_users": [...],
    "recent_orders": [...],
    "top_crops": [...],
    "top_farmers": [...]
  }
}
```

### GET /api/admin/users
Get all users (Protected, Admin only).

### GET /api/admin/users/{id}
Get user details (Protected, Admin only).

### POST /api/admin/users/suspend
Suspend user (Protected, Admin only).

**Request:**
```json
{
  "user_id": 1,
  "reason": "Violation of terms"
}
```

### POST /api/admin/users/activate
Reactivate user (Protected, Admin only).

### POST /api/admin/users/verify
Verify user (Protected, Admin only).

### GET /api/admin/listings
Get all listings (Protected, Admin only).

### POST /api/admin/listings/approve
Approve listing (Protected, Admin only).

### POST /api/admin/listings/suspend
Suspend listing (Protected, Admin only).

### GET /api/admin/transactions
Get all transactions (Protected, Admin only).

### POST /api/admin/transactions/release-escrow
Manually release escrow (Protected, Admin only).

### POST /api/admin/transactions/refund
Refund transaction (Protected, Admin only).

---

## 8. Integrations API (`integrations.php`)

### GET /api/integrations/weather
Get weather data (Mock).

**Query Parameters:**
- `location` (required) - e.g., "Lagos"

**Response:**
```json
{
  "success": true,
  "data": {
    "location": "Lagos",
    "current": {
      "temperature": 28,
      "feels_like": 30,
      "condition": "Partly Cloudy",
      "icon": "02d",
      "humidity": 65,
      "wind_speed": 12,
      "pressure": 1015,
      "visibility": 10,
      "uv_index": 6,
      "timestamp": "2024-01-15T12:00:00Z"
    },
    "forecast": [
      {
        "date": "2024-01-16",
        "condition": "Sunny",
        "temp_min": 24,
        "temp_max": 32,
        "humidity": 55,
        "wind_speed": 10,
        "rain_chance": 10
      }
    ],
    "agricultural_tips": [
      "Good day for drying harvested crops"
    ]
  }
}
```

### GET /api/integrations/crop-prices
Get current market crop prices (Mock).

**Query Parameters:**
- `location` (optional) - e.g., "Lagos"

**Response:**
```json
{
  "success": true,
  "data": {
    "prices": [
      {
        "crop": "Tomatoes",
        "current_price": 155.00,
        "previous_price": 148.00,
        "change_percent": 4.73,
        "trend": "up",
        "unit": "kg",
        "market": "Lagos Central Market",
        "last_updated": "2024-01-15T12:00:00Z"
      }
    ],
    "predictions": {
      "Tomatoes": {
        "next_week": 167.40,
        "confidence": 75,
        "factors": ["seasonal_demand", "supply_availability"]
      }
    },
    "location": "Lagos",
    "updated_at": "2024-01-15T12:00:00Z"
  }
}
```

### GET /api/integrations/exchange-rates
Get currency exchange rates (Mock, USD base).

### POST /api/integrations/payments/initialize
Initialize payment (Mock).

**Request:**
```json
{
  "amount": 10000,
  "currency": "NGN",
  "customer_email": "buyer@example.com",
  "customer_name": "John Buyer"
}
```

### POST /api/integrations/payments/verify
Verify payment (Mock).

**Request:**
```json
{
  "reference": "AGR-ABC123DEF"
}
```

### POST /api/integrations/payments/save-card
Save card for future payments (Protected).

### POST /api/integrations/sms
Send SMS (Mock).

**Request:**
```json
{
  "to": "+2348012345678",
  "message": "Your order has been shipped"
}
```

### POST /api/integrations/bank-verify
Verify bank account (Mock).

**Request:**
```json
{
  "bank_code": "058",
  "account_number": "0123456789"
}
```

### POST /api/integrations/email
Send email (Protected).

---

## Database Schema

### users
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| name | VARCHAR(255) | User name |
| email | VARCHAR(255) | Unique email |
| password | VARCHAR(255) | Bcrypt hash |
| role | ENUM | farmer/buyer/admin |
| location | VARCHAR(255) | Location |
| phone | VARCHAR(20) | Phone number |
| is_verified | BOOLEAN | Verification status |
| is_active | BOOLEAN | Account status |
| created_at | TIMESTAMP | Creation date |
| updated_at | TIMESTAMP | Last update |

### listings
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| farmer_id | INT | Foreign key to users |
| crop_name | VARCHAR(255) | Crop type |
| quantity | DECIMAL | Available quantity |
| unit | VARCHAR(20) | Unit of measure |
| price | DECIMAL | Price per unit |
| description | TEXT | Description |
| location | VARCHAR(255) | Farm location |
| image_url | VARCHAR(500) | Image URL |
| status | ENUM | active/sold/suspended |
| created_at | TIMESTAMP | Creation date |
| updated_at | TIMESTAMP | Last update |

### orders
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| listing_id | INT | Foreign key to listings |
| buyer_id | INT | Foreign key to users |
| farmer_id | INT | Foreign key to users |
| quantity | DECIMAL | Ordered quantity |
| unit | VARCHAR(20) | Unit of measure |
| unit_price | DECIMAL | Price per unit |
| total_amount | DECIMAL | Total order amount |
| service_fee | DECIMAL | Platform fee (2%) |
| status | ENUM | pending/accepted/completed/cancelled |
| escrow_status | ENUM | held/released/refunded |
| payment_reference | VARCHAR(100) | Payment reference |
| notes | TEXT | Order notes |
| created_at | TIMESTAMP | Creation date |
| updated_at | TIMESTAMP | Last update |

### wallets
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | Foreign key to users |
| balance | DECIMAL | Available balance |
| pending_balance | DECIMAL | Pending balance |
| total_earned | DECIMAL | Total earnings |
| total_spent | DECIMAL | Total spending |
| total_withdrawn | DECIMAL | Total withdrawn |
| created_at | TIMESTAMP | Creation date |
| updated_at | TIMESTAMP | Last update |

### transactions
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | Foreign key to users |
| order_id | INT | Foreign key to orders |
| amount | DECIMAL | Transaction amount |
| type | ENUM | payment/escrow_release/withdrawal/refund |
| status | ENUM | pending/completed/failed |
| reference | VARCHAR(100) | Transaction reference |
| description | TEXT | Description |
| created_at | TIMESTAMP | Creation date |

### notifications
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | Foreign key to users |
| title | VARCHAR(255) | Notification title |
| message | TEXT | Notification message |
| type | ENUM | order/payment/system |
| is_read | BOOLEAN | Read status |
| created_at | TIMESTAMP | Creation date |

### activity_logs
| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | Foreign key to users |
| action | VARCHAR(100) | Action type |
| entity_type | VARCHAR(50) | Entity type |
| entity_id | INT | Entity ID |
| details | JSON | Additional details |
| ip_address | VARCHAR(45) | IP address |
| user_agent | VARCHAR(500) | User agent |
| created_at | TIMESTAMP | Creation date |

---

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "error": "Error message here"
}
```

### Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `500` - Internal Server Error

---

## Rate Limiting
Currently no rate limiting in place. Implement at web server level for production.

---

## Third-Party Integrations (Mocks)
All third-party integrations in `integrations.php` are mock implementations:

- **Weather**: Generates pseudo-random but consistent weather based on location hash
- **Crop Prices**: Base prices with time/location variations
- **Payments**: Simulated payment flow with 90% success rate
- **SMS**: Mock SMS sending with message ID
- **Bank Verification**: 80% success rate based on hash

For production, replace with actual API calls to:
- Weather: OpenWeatherMap, WeatherAPI
- Payments: Flutterwave, Paystack
- SMS: Twilio, Termii
- Bank Verify: NIBSS, Paystack
