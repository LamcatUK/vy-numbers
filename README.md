# VY Numbers Plugin

## Executive Overview

The VY Numbers plugin is a custom-built WordPress solution that powers your exclusive VY Founder Number system. This plugin transforms the traditional e-commerce experience into a premium, scarcity-driven membership platform.

### What It Does

**For Your Customers:**

- Provides an intuitive 4-digit number picker interface on your homepage
- Allows customers to select and secure their exclusive VY Founder numbers (0001-9999)
- Prevents duplicate purchases - each number can only be owned by one person
- Enables customers to purchase multiple unique numbers in a single transaction
- Shows real-time availability as customers type their desired numbers
- Displays clear messaging when numbers are taken or already in their cart

**For Your Business:**

- Creates artificial scarcity by limiting each number to one owner
- Implements a reservation system that temporarily holds numbers during the selection process
- Provides comprehensive admin tools to manage all 9,999 founder numbers
- Tracks ownership, purchase dates, and customer information for each number
- Integrates seamlessly with WooCommerce for payment processing
- Includes automatic cleanup of expired reservations to keep numbers available

### Key Benefits

1. **Exclusivity & Scarcity**: Each founder number is unique and can only be owned once, creating genuine scarcity
2. **Premium Experience**: Sleek, modern interface that matches your luxury brand positioning
3. **Fraud Prevention**: Built-in security measures prevent duplicate purchases and gaming of the system
4. **Customer Control**: Users can select multiple numbers and see exactly what they're purchasing
5. **Administrative Control**: Complete backend management of all numbers and customer relationships

---

## Technical Documentation

### Plugin Architecture

The VY Numbers plugin consists of several core components:

- **Database Management**: Custom table for tracking all 9,999 founder numbers
- **REST API**: Real-time availability checking without page reloads
- **WooCommerce Integration**: Seamless cart and checkout functionality
- **Admin Interface**: Comprehensive management tools
- **Frontend Interface**: User-friendly number selection system

---

## Frontend (Customer-Facing) Features

### Number Picker Shortcode

**Shortcode:** `[vy_number_picker]`

**Parameters:**

- `product_id` (default: configured in config.php) - WooCommerce product ID for VY Founder membership
- `button_text` (default: configured in config.php) - Customizable button text

**Configuration:**
Default values are automatically loaded from `config.php`:

```php
'product_id' => 134,                                    // Default product ID
'frontend' => array(
    'default_button_text' => 'Secure my number',      // Default button text
    'show_cart_link' => true,                          // Show cart link when applicable
),
```

**Features:**

#### Real-Time Availability Checking

- Instant feedback as customers type their 4-digit number
- Shows "available", "taken", or "already in your cart" status
- No page refreshes required - all checking happens in real-time

#### Duplicate Prevention

- Prevents customers from adding the same number twice to their cart
- Shows clear error messages when attempting to select taken numbers
- Disables form submission for invalid selections

#### Multiple Number Support

- Customers can add multiple unique numbers to their cart
- Each number appears as a separate line item in cart/checkout
- No limit on how many different numbers one customer can purchase

#### Smart Cart Integration

- Displays "View cart" link when numbers are already in cart
- Shows count of numbers currently selected
- Only appears on homepage when cart contains VY numbers

#### Responsive Design

- Mobile-optimized 4-digit input interface
- Touch-friendly number entry
- Accessible keyboard navigation

#### Security Features

- WordPress nonce verification for all form submissions
- XSS protection on all user inputs
- CSRF protection for cart operations

### Checkout Integration

#### Custom Checkout Experience

- Displays selected founder numbers prominently
- "Add Another Number" option during checkout
- Prevents nested form issues on checkout page
- AJAX-powered number addition during checkout flow

#### Session Management

- Handles expired WooCommerce sessions gracefully
- Redirects shop links to homepage instead of non-existent shop page
- Maintains user experience during session issues

---

## Backend (Administrative) Features

### Admin Dashboard

**Location:** WordPress Admin → VY Numbers

#### Number Management Table

- **Complete Overview**: View all 9,999 founder numbers in a paginated table
- **Status Filtering**: Filter by Available, Reserved, Sold status
- **Quick Actions**: Release reserved numbers, view customer details
- **Bulk Operations**: Mass release of expired reservations

#### Individual Number Details

- **Owner Information**: Customer name, email, purchase date
- **Status History**: Track when numbers were reserved, purchased, released
- **Manual Override**: Ability to manually release or reassign numbers
- **Customer Links**: Direct links to customer orders and profiles

#### Search and Filtering

- **Number Search**: Find specific numbers instantly
- **Customer Search**: Search by customer name or email
- **Status Filters**: View only available, reserved, or sold numbers
- **Date Filters**: Filter by purchase date ranges

### Database Management

#### Automatic Cleanup

- **Cron Jobs**: Automatic release of expired reservations
- **Configurable Timing**: Admin can set reservation expiry times
- **System Health**: Prevents stale reservations from blocking sales

#### Data Integrity

- **Atomic Operations**: Prevents race conditions during number selection
- **Backup Safety**: All number changes are logged and reversible
- **Consistency Checks**: Regular validation of number status accuracy

### WooCommerce Integration

#### Cart Management

- **Product Integration**: Numbers attach to WooCommerce products seamlessly
- **Order Processing**: Numbers are properly recorded in order history
- **Customer Records**: Full integration with WooCommerce customer profiles

#### Duplicate Prevention

- **Cart Validation**: Server-side validation prevents duplicate additions
- **Session Handling**: Maintains duplicate prevention across sessions
- **Multi-step Protection**: Validation at form, cart, and checkout levels

#### Custom Fields

- **Order Meta**: Founder numbers stored as order metadata
- **Customer Display**: Numbers shown clearly in order confirmations
- **Admin Orders**: Full number details visible in admin order views

### Security Features

#### Input Validation

- **Number Format**: Strict 4-digit validation (0001-9999)
- **SQL Injection Protection**: All database queries use prepared statements
- **Data Sanitization**: All user inputs properly sanitized

#### Access Control

- **Permission Checks**: Admin features restricted to appropriate user roles
- **Nonce Verification**: All administrative actions include security tokens
- **Audit Trail**: Complete logging of all number status changes

### Performance Optimization

#### Caching Strategy

- **Database Optimization**: Efficient queries with proper indexing
- **REST API Caching**: Smart caching of availability responses
- **Asset Loading**: Conditional loading of scripts and styles

#### Scalability

- **Large Dataset Handling**: Optimized for managing 9,999+ records
- **Concurrent Access**: Handles multiple simultaneous number selections
- **Load Distribution**: Efficient processing during high-traffic periods

---

## Installation & Configuration

### Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- MySQL 5.7+

### Setup Process

1. **Plugin Installation**: Upload and activate the VY Numbers plugin
2. **Database Creation**: Plugin automatically creates necessary database tables
3. **Number Population**: System initializes all 9,999 founder numbers as "available"
4. **Configuration**: Review and modify `config.php` for your specific needs
5. **WooCommerce Product**: Ensure VY Founder product exists (configurable ID)
6. **Shortcode Placement**: Add `[vy_number_picker]` to your homepage

### Configuration System

The plugin includes a comprehensive configuration system via `config.php` that allows you to customize all aspects without editing core files.

#### Core Settings

**Product & Business Configuration:**

```php
'product_id' => 134,                    // WooCommerce product ID for VY Founder membership
'reservation_timeout' => 300,          // How long to hold numbers (seconds)
'number_range' => array(
    'min' => 1,                         // Minimum founder number (0001)
    'max' => 9999,                      // Maximum founder number (9999)
),
```

**Frontend Experience:**

```php
'frontend' => array(
    'default_button_text' => 'Secure my number',      // Default shortcode button text
    'show_cart_link' => true,                         // Show "View cart" link on homepage
    'enable_realtime_checking' => true,               // Real-time availability checking
),
```

**Administrative Control:**

```php
'admin' => array(
    'required_capability' => 'manage_options',        // WordPress capability for admin access
    'numbers_per_page' => 100,                        // Pagination in admin interface
),
```

**Performance Optimization:**

```php
'performance' => array(
    'cache_duration' => 0,                            // API response caching (seconds)
    'cleanup_frequency' => 'hourly',                  // Automated cleanup schedule
),
```

**Security & Validation:**

```php
'security' => array(
    'strict_validation' => true,                      // Enforce strict number validation
    'rate_limit' => 60,                               // API requests per IP per minute
),
```

**Development & Debugging:**

```php
'debug' => array(
    'enable_logging' => false,                        // Debug logging (dev only)
    'log_api_requests' => false,                      // Log all API calls (dev only)
),
```

#### Configuration Usage

**Access in Code:**

```php
// Get specific values
$product_id = VY_Numbers_Config::get_product_id();
$timeout = VY_Numbers_Config::get_reservation_timeout();

// Use dot notation for nested values
$capability = VY_Numbers_Config::get('admin.required_capability');
$cache_time = VY_Numbers_Config::get('performance.cache_duration', 0);
```

**Common Customizations:**

_Change Product ID:_

```php
'product_id' => 456,  // Your WooCommerce product ID
```

_Extend Reservation Time (10 minutes):_

```php
'reservation_timeout' => 600,  // 10 minutes instead of 5
```

_Disable Cart Links:_

```php
'frontend' => array(
    'show_cart_link' => false,
),
```

_Enable Debug Mode (Development Only):_

```php
'debug' => array(
    'enable_logging' => true,
    'log_api_requests' => true,
),
```

#### Configuration Management

- **File Location**: `/wp-content/plugins/vy-numbers/config.php`
- **Backup Recommended**: Always backup config before changes
- **Environment Specific**: Different configs for dev/staging/production
- **Cache Clearing**: Clear any caches after configuration changes
- **Fallback Defaults**: Plugin includes safe defaults if config file is missing

---

## Support & Maintenance

### Monitoring

- **System Status**: Admin dashboard shows overall system health
- **Error Logging**: Comprehensive logging of all plugin activities
- **Performance Metrics**: Track usage patterns and system load

### Troubleshooting

- **Common Issues**: Built-in diagnostics for typical problems
- **Debug Mode**: Enhanced logging for development and testing
- **Reset Options**: Safe methods to reset specific numbers or entire system

### Updates

- **Version Control**: Safe update process that preserves all data
- **Backward Compatibility**: Maintains compatibility with existing installations
- **Feature Evolution**: Regular updates with new functionality

---

## Developer Information

### File Structure

```
vy-numbers/
├── vy-numbers.php (Main plugin file)
├── config.php (Configuration settings)
├── includes/
│   ├── class-vy-numbers-admin.php (Admin interface)
│   ├── class-vy-numbers-cart.php (Cart integration)
│   ├── class-vy-numbers-config.php (Configuration manager)
│   ├── class-vy-numbers-cron.php (Cleanup tasks)
│   ├── class-vy-numbers-installer.php (Database setup)
│   ├── class-vy-numbers-rest.php (API endpoints)
│   └── class-vy-numbers-shortcode.php (Frontend interface)
└── README.md (This file)
```

### Configuration Architecture

**Configuration Manager (`class-vy-numbers-config.php`):**

- Centralized configuration loading and management
- Automatic fallback to safe defaults
- Support for nested configuration values via dot notation
- Helper methods for common configuration access patterns

**Configuration File (`config.php`):**

- Comprehensive settings for all plugin aspects
- Extensive documentation for each setting
- Environment-specific customization support
- Version-controlled configuration management

### Configuration API

**Core Methods:**

```php
// Get any configuration value with optional default
VY_Numbers_Config::get($key, $default);

// Helper methods for common settings
VY_Numbers_Config::get_product_id();                    // WooCommerce product ID
VY_Numbers_Config::get_reservation_timeout();           // Reservation timeout (seconds)
VY_Numbers_Config::get_min_number();                    // Minimum founder number
VY_Numbers_Config::get_max_number();                    // Maximum founder number
VY_Numbers_Config::get_admin_capability();              // Required admin capability
VY_Numbers_Config::get_default_button_text();           // Default shortcode button text
VY_Numbers_Config::show_cart_link();                    // Whether to show cart link
VY_Numbers_Config::is_realtime_checking_enabled();      // Real-time checking enabled
VY_Numbers_Config::is_debug_logging_enabled();          // Debug logging enabled
```

**Dot Notation Support:**

```php
// Access nested configuration values
$capability = VY_Numbers_Config::get('admin.required_capability');
$cache_time = VY_Numbers_Config::get('performance.cache_duration');
$rate_limit = VY_Numbers_Config::get('security.rate_limit');
```

### API Endpoints

- `GET/POST /wp-json/vy/v1/number/{num}` - Check number availability
- Parameters: `num` (4-digit string), `cart_numbers` (array, optional)
- Response: `{status: 'available|reserved|sold|in_cart', message: string}`

### Hooks & Filters

- `vy_numbers_before_reserve` - Action before reserving a number
- `vy_numbers_after_purchase` - Action after successful purchase
- `vy_numbers_cleanup_expired` - Action during cleanup process
- `vy_numbers_admin_columns` - Filter for admin table columns

### Database Schema

```sql
CREATE TABLE wp_vy_numbers (
    num VARCHAR(4) PRIMARY KEY,
    status ENUM('available','reserved','sold'),
    reserved_by VARCHAR(255),
    reserve_expires DATETIME,
    sold_to VARCHAR(255),
    sold_date DATETIME,
    nickname VARCHAR(255),
    order_id INT
);
```

---

_VY Numbers Plugin - Powering Exclusive Founder Memberships_
