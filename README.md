# AgriMarket Backend API

A complete PHP REST API with MySQL for the AgriMarket agricultural marketplace application.

## 🚀 Quick Deploy to cPanel

### Option 1: Direct Upload (Recommended for cPanel)

1. **Create a MySQL Database in cPanel:**
   - Go to "MySQL Databases" 
   - Create a new database (e.g., `agrimarket_db`)
   - Create a user and assign all privileges

2. **Import Database:**
   - Go to "phpMyAdmin"
   - Select your database
   - Import `database.sql`

3. **Upload Files:**
   - Zip the entire `backend/api` folder
   - Upload to cPanel File Manager → `public_html/api`
   - Extract the zip file

4. **Configure Database:**
   - Edit `config.php` with your cPanel MySQL credentials:
   ```php
   define('DB_HOST', 'localhost'); // Usually localhost in cPanel
   define('DB_NAME', 'your_username_agrimarket_db');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

5. **Update .htaccess (cPanel specific):**
   - The included `.htaccess` works with Apache mod_rewrite
   - If issues, ensure "RewriteEngine On" is enabled in cPanel

### Option 2: GitHub Deployment

1. Push this folder to GitHub
2. In cPanel, go to "Git™ Version Control"
3. Clone the repository to `public_html/api`

---

## 📡 API Endpoints

### Base URL
```
https://yourdomain.com/api/
```

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/register` | Register new user |
| POST | `/auth/login` | User login |
| GET | `/auth/me` | Get current user |
| PUT | `/auth/me` | Update profile |
| POST | `/auth/logout` | User logout |
| POST | `/auth/refresh` | Refresh token |
| POST | `/auth/password` | Change password |

### Listings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/listings` | List all active listings |
| GET | `/listings/{id}` | Get single listing |
| GET | `/listings/my` | Get farmer's listings |
| GET | `/listings/search` | Search listings |
| POST | `/listings` | Create new listing |
| PUT | `/listings/{id}` | Update listing |
| PATCH | `/listings/{id}/status` | Update status |
| DELETE | `/listings/{id}` | Delete listing |

### Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/orders` | List orders |
| GET | `/orders/{id}` | Get single order |
| GET | `/orders/my` | Get user's orders |
| POST | `/orders` | Create new order |
| POST | `/orders/{id}/accept` | Accept order |
| POST | `/orders/{id}/reject` | Reject order |
| POST | `/orders/{id}/deliver` | Confirm delivery |
| POST | `/orders/{id}/cancel` | Cancel order |
| POST | `/orders/{id}/complete` | Complete order |

### Wallet

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wallet` | Get wallet info |
| GET | `/wallet/transactions` | Get transactions |
| GET | `/wallet/accounts` | Get bank accounts |
| POST | `/wallet/accounts` | Add bank account |
| PUT | `/wallet/accounts/{id}` | Update bank account |
| DELETE | `/wallet/accounts/{id}` | Delete account |
| POST | `/wallet/topup` | Top up wallet |
| POST | `/wallet/withdraw` | Withdraw funds |

### Users

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/users` | Search users |
| GET | `/users/{id}` | Get user profile |

### Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notifications` | Get notifications |
| GET | `/notifications/unread` | Get unread count |
| POST | `/notifications/read` | Mark as read |
| POST | `/notifications/read-all` | Mark all as read |
| DELETE | `/notifications/{id}` | Delete notification |

### Admin

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/dashboard` | Admin dashboard |
| GET | `/admin/users` | List all users |
| GET | `/admin/users/{id}` | Get user |
| POST | `/admin/users/{id}/suspend` | Suspend user |
| POST | `/admin/users/{id}/activate` | Activate user |
| POST | `/admin/users/{id}/verify` | Verify user |
| GET | `/admin/listings` | List all listings |
| POST | `/admin/listings/{id}/approve` | Approve listing |
| POST | `/admin/listings/{id}/suspend` | Suspend listing |
| GET | `/admin/orders` | List all orders |
| POST | `/admin/orders/{id}/release-escrow` | Release escrow |
| POST | `/admin/orders/{id}/refund` | Refund order |
| GET | `/admin/transactions` | List transactions |

### Integrations (Third-Party Mock)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/integrations/weather` | Weather data |
| GET | `/integrations/crop-prices` | Crop prices |
| GET | `/integrations/exchange-rates` | Exchange rates |
| POST | `/integrations/payments/initialize` | Initialize payment |
| POST | `/integrations/payments/verify` | Verify payment |
| POST | `/integrations/sms` | Send SMS |
| POST | `/integrations/bank/verify` | Verify bank account |
| POST | `/integrations/email` | Send email |

---

## 🔐 Authentication

All protected endpoints require JWT token:

```http
Authorization: Bearer <your_token_here>
```

### Response Format

**Success:**
```json
{
  "success": true,
  "message": "Operation successful",
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "error": "Error message"
}
```

---

## 🛠️ Local Development

### Requirements
- PHP 8.0+
- MySQL 5.7+
- Apache/Nginx with mod_rewrite

### Setup

1. **Create Database:**
```bash
mysql -u root -p < database.sql
```

2. **Configure:**
Edit `config.php` with your database credentials.

3. **Run Server:**
```bash
cd backend/api
php -S localhost:8000
```

4. **Test API:**
```bash
# Login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}'
```

---

## 📁 File Structure

```
backend/api/
├── .htaccess              # URL routing
├── config.php             # Database config
├── database.sql           # MySQL schema
├── index.php              # API info
├── integrations.php       # Third-party APIs
├── auth/
│   └── index.php          # Auth endpoints
├── listings/
│   └── index.php          # Listings endpoints
├── orders/
│   └── index.php          # Orders endpoints
├── wallet/
│   └── index.php          # Wallet endpoints
├── users/
│   └── index.php          # User endpoints
├── notifications/
│   └── index.php          # Notification endpoints
└── admin/
    └── index.php          # Admin endpoints
```

---

## 🔧 Configuration

### config.php Variables

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'agrimarket_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// JWT Settings
define('JWT_SECRET', 'your-super-secret-key-change-in-production');
define('JWT_EXPIRY', '24h');

// API Settings
define('API_VERSION', '1.0.0');
define('SERVICE_FEE_PERCENT', 5);
```

---

## 📝 License

MIT License - Feel free to use for your project!
