#!/bin/bash
# Modernized Script to generate API modules (Model, Controller, Request, Resource) 
# and insert CRM fields and module relations.
# 
# This script automatically:
# 1. Installs Composer and NPM dependencies (if not already installed)
# 2. Sets up database configuration
# 3. Runs database migrations
# 4. Generates API modules
# 5. Inserts CRM fields and relations
# 
# Usage: ./create_module.sh [module_name]  (if module_name provided, only processes that module)
#        ./create_module.sh                (processes all modules)
#
# Security: This script requires an installation password to prevent unauthorized access.
#           Set CRM_INSTALLER_PASSWORD environment variable or modify INSTALLER_PASSWORD below.
#           Example: export CRM_INSTALLER_PASSWORD="YourSecurePassword123!" && ./create_module.sh
#
# Note: After cloning the repository, run this script. It will automatically install
#       all required dependencies before proceeding with database setup and module generation.

set -uo pipefail  # Strict error handling (removed -e to allow script to continue on warnings)

# --- CONFIGURATION ---
# Installation password - Change this to your secure password
# You can also set it via environment variable: export CRM_INSTALLER_PASSWORD="your_password"
INSTALLER_PASSWORD="${CRM_INSTALLER_PASSWORD:-"1"}"   # Default password is "AtomPen@123"

# Modules that have InitialModuleFields and API/CRUD (order matches processing)
MODULES=(
    "Organization" "User" "Contact" "Lead" "Product" "Quotation" "QuotationItem"
    "Invoice" "InvoiceItem" "Payment" "ChecklistTemplate" "ChecklistTemplateItem"
    "Checklist" "ChecklistItem" "Activity" "Folder" "Asset" "ModuleNumberingDetail"
    "GlobalSearchIndex" "AuditLog" "Comment" "CommentRel" "ActivityRelation"
)

API_PATH="app/Modules/Api/V1"
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
LARAVEL_ROOT="${SCRIPT_DIR}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- UTILITY FUNCTIONS ---
log_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

log_success() {
    echo -e "${GREEN}✅${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}❌${NC} $1" >&2
}

# --- SECURITY FUNCTIONS ---
verify_installer_password() {
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🔐 CRM Installation Security Check"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    
    local attempts=0
    local max_attempts=3
    
    while [ $attempts -lt $max_attempts ]; do
        read -sp "Enter installation password: " entered_password
        echo ""
        
        if [ "$entered_password" = "$INSTALLER_PASSWORD" ]; then
            log_success "Password verified successfully!"
            echo ""
            return 0
        else
            attempts=$((attempts + 1))
            remaining=$((max_attempts - attempts))
            if [ $remaining -gt 0 ]; then
                log_error "Invalid password. $remaining attempt(s) remaining."
            else
                log_error "Maximum attempts reached. Exiting."
                exit 1
            fi
        fi
    done
    
    exit 1
}

# --- ENV FILE CREATION FUNCTION ---
create_basic_env_file() {
    local env_file="$1"
    
    cat > "$env_file" <<'ENVFILE'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"

# Third Party Services
ZAPIER_WEBHOOK_TOKEN=
POSTMARK_TOKEN=
RESEND_KEY=
SLACK_BOT_USER_OAUTH_TOKEN=
SLACK_BOT_USER_DEFAULT_CHANNEL=
OPENAI_API_KEY=
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
WHATSAPP_VERIFY_TOKEN=
ENVFILE
    
    log_info "Basic .env file created at: $env_file"
}

# --- DATABASE SETUP FUNCTIONS ---
setup_database() {
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🗄️  Database Configuration"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    
    # Read database configuration
    read -p "Database Host [127.0.0.1]: " db_host
    db_host=${db_host:-127.0.0.1}
    
    read -p "Database Port [3306]: " db_port
    db_port=${db_port:-3306}
    
    read -p "Database Username [root]: " db_username
    db_username=${db_username:-root}
    
    read -sp "Database Password: " db_password
    echo ""
    
    # Note: Empty password is allowed (some MySQL setups use no password)
    # But we'll store it as-is in .env
    
    read -p "Database Name: " db_name
    if [ -z "$db_name" ]; then
        log_error "Database name is required!"
        exit 1
    fi
    
    # Step 1: Test database connection and create database using PHP
    log_info "Step 1: Testing database connection..."
    cd "$LARAVEL_ROOT"
    
    # Use PHP to test connection and create database
    # Use a temporary PHP file to avoid bash variable expansion issues
    local php_script=$(mktemp)
    cat > "$php_script" <<'PHPSCRIPT'
<?php
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$db_name = getenv('DB_NAME');

try {
    // Connect without selecting database first
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if database exists
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "CREATED";
    } else {
        echo "EXISTS";
    }
    
    // Test connection to the specific database
    $pdo_db = new PDO("mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "|SUCCESS";
} catch (PDOException $e) {
    echo "ERROR|" . $e->getMessage();
    exit(1);
}
PHPSCRIPT
    
    php_result=$(DB_HOST="$db_host" DB_PORT="$db_port" DB_USERNAME="$db_username" DB_PASSWORD="$db_password" DB_NAME="$db_name" php "$php_script")
    rm -f "$php_script"
    
    if [[ "$php_result" == *"SUCCESS"* ]]; then
        if [[ "$php_result" == *"CREATED"* ]]; then
            log_success "Database '$db_name' created successfully!"
        else
            log_info "Database '$db_name' already exists."
        fi
        log_success "Database connection successful!"
    else
        log_error "Failed to connect to database: ${php_result#*|}"
        exit 1
    fi
    
    # Step 2: Update .env file with database configuration
    echo ""
    log_info "Step 2: Updating .env file with database configuration..."
    local env_file="${LARAVEL_ROOT}/.env"
    
    if [ ! -f "$env_file" ]; then
        log_warning ".env file not found. Creating .env file..."
        if [ -f "${LARAVEL_ROOT}/.env.example" ]; then
            cp "${LARAVEL_ROOT}/.env.example" "$env_file"
            log_success "Created .env file from .env.example"
        else
            log_warning ".env.example file not found. Creating basic .env file..."
            create_basic_env_file "$env_file"
            log_success "Created basic .env file"
        fi
        
        # Generate APP_KEY if it's empty
        if ! grep -q "^APP_KEY=base64:" "$env_file" 2>/dev/null; then
            log_info "Generating application key..."
            cd "$LARAVEL_ROOT"
            if php artisan key:generate --force >/dev/null 2>&1; then
                log_success "Application key generated successfully"
            else
                log_warning "Could not generate APP_KEY automatically. You may need to run: php artisan key:generate"
            fi
        fi
    fi
    
    # Store original permissions
    local original_perms=$(stat -c "%a" "$env_file" 2>/dev/null || stat -f "%OLp" "$env_file" 2>/dev/null || echo "644")
    
    # Make .env file writable for updates
    log_info "Making .env file writable for updates..."
    if ! chmod 644 "$env_file" 2>/dev/null && ! chmod u+w "$env_file" 2>/dev/null; then
        log_error "Cannot make .env file writable. Current permissions: $(stat -c "%a" "$env_file" 2>/dev/null || echo 'unknown')"
        log_error "Please run manually: chmod 644 .env"
        exit 1
    fi
    
    # Backup .env file
    cp "$env_file" "${env_file}.backup.$(date +%Y%m%d_%H%M%S)"
    
    # Update database configuration in .env using PHP for robust handling
    log_info "Updating database configuration in .env file..."
    
    local update_script=$(mktemp)
    cat > "$update_script" <<'ENVUPDATE'
<?php
$envFile = getenv('ENV_FILE_PATH');
$dbConnection = getenv('DB_CONNECTION_VAL');
$dbHost = getenv('DB_HOST_VAL');
$dbPort = getenv('DB_PORT_VAL');
$dbDatabase = getenv('DB_DATABASE_VAL');
$dbUsername = getenv('DB_USERNAME_VAL');
$dbPassword = getenv('DB_PASSWORD_VAL');

if (!file_exists($envFile)) {
    echo "ERROR: .env file not found\n";
    exit(1);
}

// Read the .env file
$lines = file($envFile, FILE_IGNORE_NEW_LINES);
$updated = [];
$found = [
    'DB_CONNECTION' => false,
    'DB_HOST' => false,
    'DB_PORT' => false,
    'DB_DATABASE' => false,
    'DB_USERNAME' => false,
    'DB_PASSWORD' => false,
];

// Process each line
foreach ($lines as $line) {
    $trimmed = trim($line);
    
    // Skip empty lines and comments
    if (empty($trimmed) || strpos($trimmed, '#') === 0) {
        $updated[] = $line;
        continue;
    }
    
    // Check if this is a DB configuration line
    $isDbConfig = false;
    $key = null;
    $value = null;
    
    // Handle different formats: KEY=value, KEY = value, KEY="value", etc.
    if (preg_match('/^DB_CONNECTION\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_CONNECTION';
        $value = $dbConnection;
        $found['DB_CONNECTION'] = true;
    } elseif (preg_match('/^DB_HOST\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_HOST';
        $value = $dbHost;
        $found['DB_HOST'] = true;
    } elseif (preg_match('/^DB_PORT\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_PORT';
        $value = $dbPort;
        $found['DB_PORT'] = true;
    } elseif (preg_match('/^DB_DATABASE\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_DATABASE';
        $value = $dbDatabase;
        $found['DB_DATABASE'] = true;
    } elseif (preg_match('/^DB_USERNAME\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_USERNAME';
        $value = $dbUsername;
        $found['DB_USERNAME'] = true;
    } elseif (preg_match('/^DB_PASSWORD\s*=\s*(.*)$/i', $trimmed, $matches)) {
        $isDbConfig = true;
        $key = 'DB_PASSWORD';
        // Quote the password value
        $value = '"' . str_replace('"', '\\"', $dbPassword) . '"';
        $found['DB_PASSWORD'] = true;
    }
    
    if ($isDbConfig) {
        $updated[] = "{$key}={$value}";
    } else {
        $updated[] = $line;
    }
}

// Add missing configuration entries at the end
if (!$found['DB_CONNECTION']) {
    $updated[] = "DB_CONNECTION={$dbConnection}";
}
if (!$found['DB_HOST']) {
    $updated[] = "DB_HOST={$dbHost}";
}
if (!$found['DB_PORT']) {
    $updated[] = "DB_PORT={$dbPort}";
}
if (!$found['DB_DATABASE']) {
    $updated[] = "DB_DATABASE={$dbDatabase}";
}
if (!$found['DB_USERNAME']) {
    $updated[] = "DB_USERNAME={$dbUsername}";
}
if (!$found['DB_PASSWORD']) {
    // Quote the password value
    $passwordValue = '"' . str_replace('"', '\\"', $dbPassword) . '"';
    $updated[] = "DB_PASSWORD={$passwordValue}";
}

// Write the updated content back to the file
file_put_contents($envFile, implode("\n", $updated) . "\n");

// Report what was updated
$updatedKeys = [];
foreach ($found as $key => $wasFound) {
    if ($wasFound) {
        $updatedKeys[] = $key;
    }
}
$addedKeys = [];
if (!$found['DB_CONNECTION']) $addedKeys[] = 'DB_CONNECTION';
if (!$found['DB_HOST']) $addedKeys[] = 'DB_HOST';
if (!$found['DB_PORT']) $addedKeys[] = 'DB_PORT';
if (!$found['DB_DATABASE']) $addedKeys[] = 'DB_DATABASE';
if (!$found['DB_USERNAME']) $addedKeys[] = 'DB_USERNAME';
if (!$found['DB_PASSWORD']) $addedKeys[] = 'DB_PASSWORD';

if (!empty($updatedKeys)) {
    echo "UPDATED:" . implode(',', $updatedKeys);
}
if (!empty($addedKeys)) {
    echo "|ADDED:" . implode(',', $addedKeys);
}
echo "|SUCCESS";
ENVUPDATE
    
    # Execute the PHP script to update .env
    local update_result=$(ENV_FILE_PATH="$env_file" \
        DB_CONNECTION_VAL="mysql" \
        DB_HOST_VAL="$db_host" \
        DB_PORT_VAL="$db_port" \
        DB_DATABASE_VAL="$db_name" \
        DB_USERNAME_VAL="$db_username" \
        DB_PASSWORD_VAL="$db_password" \
        php "$update_script" 2>&1)
    
    rm -f "$update_script"
    
    if [[ "$update_result" == *"SUCCESS"* ]]; then
        # Show what was updated/added
        if [[ "$update_result" == *"UPDATED:"* ]]; then
            local updated_keys=$(echo "$update_result" | sed -n 's/.*UPDATED:\([^|]*\).*/\1/p')
            log_info "Updated existing entries: $updated_keys"
        fi
        if [[ "$update_result" == *"ADDED:"* ]]; then
            local added_keys=$(echo "$update_result" | sed -n 's/.*ADDED:\([^|]*\).*/\1/p')
            log_info "Added new entries: $added_keys"
        fi
        log_success ".env file updated successfully!"
    else
        log_error "Failed to update .env file: $update_result"
        # Restore original permissions on failure
        chmod "$original_perms" "$env_file" 2>/dev/null || chmod 644 "$env_file" 2>/dev/null || true
        exit 1
    fi
    
    # Verify .env file can be read by testing connection
    log_info "Verifying database connection with updated .env..."
    cd "$LARAVEL_ROOT"
    
    # Clear ALL Laravel caches to ensure new .env values are read
    log_info "Clearing Laravel caches..."
    php artisan config:clear >/dev/null 2>&1 || true
    php artisan cache:clear >/dev/null 2>&1 || true
    php artisan route:clear >/dev/null 2>&1 || true
    php artisan view:clear >/dev/null 2>&1 || true
    
    # Remove bootstrap cache files if they exist
    rm -f bootstrap/cache/config.php 2>/dev/null || true
    rm -f bootstrap/cache/services.php 2>/dev/null || true
    rm -f bootstrap/cache/packages.php 2>/dev/null || true
    
    # Wait a moment for cache to clear
    sleep 2
    
    # Test connection using Laravel's method to ensure it matches what migrations will use
    log_info "Testing database connection using Laravel's connection method..."
    
    # Use Laravel's DB facade to test connection (same method migrations use)
    local php_test_script=$(mktemp)
    cat > "$php_test_script" <<'PHPTEST'
<?php
$rootPath = getenv('LARAVEL_ROOT_ENV') ?: __DIR__;
if (!file_exists("{$rootPath}/vendor/autoload.php")) {
    $rootPath = realpath($rootPath . '/..');
}

require "{$rootPath}/vendor/autoload.php";
$app = require_once "{$rootPath}/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Use Laravel's DB connection (same as migrations)
    DB::connection()->getPdo();
    echo "SUCCESS";
} catch (Exception $e) {
    $message = $e->getMessage();
    // Extract useful error info
    if (strpos($message, 'Access denied') !== false) {
        $config = config('database.connections.mysql');
        echo "ERROR: Access denied for user '{$config['username']}'@'{$config['host']}'";
        echo "\nPlease verify:";
        echo "\n  - Username: {$config['username']}";
        echo "\n  - Password: " . (empty($config['password']) ? '(empty)' : '***SET***');
        echo "\n  - Host: {$config['host']}";
        echo "\n  - Database: {$config['database']}";
    } else {
        echo "ERROR: " . $message;
    }
    exit(1);
}
PHPTEST
    
    local test_result=$(LARAVEL_ROOT_ENV="$LARAVEL_ROOT" php "$php_test_script" 2>&1)
    rm -f "$php_test_script"
    
    if [[ "$test_result" == *"SUCCESS"* ]]; then
        log_success "Database connection verified using Laravel's connection method!"
        
        # Make .env file read-only after successful update and verification
        # Use 444 (read-only for all) to ensure web server can read it
        log_info "Securing .env file (making it read-only)..."
        chmod 444 "$env_file" 2>/dev/null || {
            # Fallback: make it read-only but ensure it's readable
            chmod 440 "$env_file" 2>/dev/null || chmod 400 "$env_file" 2>/dev/null || chmod u-w "$env_file" 2>/dev/null || true
        }
        log_success ".env file is now read-only (secured) - readable by all, writable by none"
    else
        log_error "Failed to connect to database with updated .env credentials."
        echo ""
        echo "$test_result" | while IFS= read -r line; do
            if [[ "$line" == *"ERROR:"* ]] || [[ "$line" == *"Please verify:"* ]] || [[ "$line" == *"- "* ]]; then
                echo "   $line"
            fi
        done
        echo ""
        log_error "The .env file has been updated, but the credentials are incorrect."
        log_warning "You can:"
        log_warning "  1. Manually edit .env file with correct credentials"
        log_warning "  2. Re-run this script with correct credentials"
        log_warning "  3. The backup is saved as: ${env_file}.backup.*"
        echo ""
        read -p "Do you want to retry with new credentials? (y/n): " retry_choice
        if [[ "$retry_choice" =~ ^[Yy]$ ]]; then
            # Restore backup and retry
            mv "${env_file}.backup."* "$env_file" 2>/dev/null || true
            setup_database  # Recursive call to retry
            return
        else
            # Keep .env writable so user can fix it manually
            log_warning "Keeping .env file writable so you can fix credentials manually."
            chmod 644 "$env_file" 2>/dev/null || chmod u+w "$env_file" 2>/dev/null || true
            log_error "Exiting. Please fix .env file manually and re-run the script."
            exit 1
        fi
    fi
    
    # Step 3: Run migrations (after .env is updated and verified)
    echo ""
    log_info "Step 3: Running database migrations (php artisan migrate)..."
    
    # Clear config one more time before migrations to be absolutely sure
    php artisan config:clear >/dev/null 2>&1 || true
    rm -f bootstrap/cache/config.php 2>/dev/null || true
    sleep 1
    
    local migrate_output
    if migrate_output=$(php artisan migrate --force 2>&1); then
        log_success "All database migrations completed successfully! (including WhatsApp migrations)"
        
        # Ensure .env file is read-only after successful migrations
        if [ -w "$env_file" ]; then
            log_info "Securing .env file (making it read-only)..."
            chmod 444 "$env_file" 2>/dev/null || {
                chmod 440 "$env_file" 2>/dev/null || chmod 400 "$env_file" 2>/dev/null || chmod u-w "$env_file" 2>/dev/null || true
            }
            log_success ".env file is now read-only (secured) - readable by all, writable by none"
        fi
    else
        echo ""
        log_error "Database migrations failed. Error details:"
        echo "$migrate_output" | head -20 | while IFS= read -r line; do
            echo "   $line"
        done
        echo ""
        log_error "Possible causes:"
        log_error "  - Incorrect database credentials"
        log_error "  - Database user lacks required permissions"
        log_error "  - Database server is not accessible"
        echo ""
        log_warning "Current database configuration:"
        grep "^DB_" "$env_file" | grep -v "PASSWORD" | sed 's/^/  /' || true
        grep "^DB_PASSWORD=" "$env_file" | sed 's/DB_PASSWORD=.*/DB_PASSWORD=***HIDDEN***/' | sed 's/^/  /' || true
        echo ""
        log_warning "Backup saved as: ${env_file}.backup.*"
        # Keep .env writable so user can fix credentials
        chmod 644 "$env_file" 2>/dev/null || chmod u+w "$env_file" 2>/dev/null || true
        log_warning "Keeping .env file writable so you can fix credentials manually."
        log_error "Please fix the database credentials and re-run the script."
        exit 1
    fi
    
    
    echo ""
    log_success "Database setup completed!"
    echo ""
}

safe_replace() {
    local PATTERN="$1"
    local REPLACEMENT="$2"
    local FILE="$3"
    
    if [[ ! -f "$FILE" ]]; then
        log_error "File not found: $FILE"
        return 1
    fi
    
    # Use perl for more reliable replacement (handles special chars better)
    perl -i -pe "s#\Q${PATTERN}\E#${REPLACEMENT}#g" "$FILE" 2>/dev/null || {
        # Fallback to sed if perl fails
        sed -i.bak -e "s#${PATTERN}#${REPLACEMENT}#g" "$FILE"
        rm -f "$FILE.bak"
    }
}

# Map module name to DB table name (from InitialModuleFields / migrations)
get_table_name() {
    local name="$1"
    case "$name" in
        Organization) echo "organizations" ;;
        User) echo "users" ;;
        Contact) echo "contacts" ;;
        Lead) echo "leads" ;;
        Product) echo "products" ;;
        Quotation) echo "quotations" ;;
        QuotationItem) echo "quotation_items" ;;
        Invoice) echo "invoices" ;;
        InvoiceItem) echo "invoice_items" ;;
        Payment) echo "payments" ;;
        ChecklistTemplate) echo "checklist_templates" ;;
        ChecklistTemplateItem) echo "checklist_template_items" ;;
        Checklist) echo "checklists" ;;
        ChecklistItem) echo "checklist_items" ;;
        Activity) echo "activities" ;;
        Folder) echo "folders" ;;
        Asset) echo "assets" ;;
        ModuleNumberingDetail) echo "module_numbering_details" ;;
        GlobalSearchIndex) echo "global_search_index" ;;
        AuditLog) echo "audit_log" ;;
        Comment) echo "comments" ;;
        CommentRel) echo "comment_rel" ;;
        ActivityRelation) echo "activity_relations" ;;
        *) echo "${name,,}" ;;  # fallback: lowercase
    esac
}

# --- MODULE GENERATION FUNCTIONS ---
create_folders() {
    local MODULE_NAME=$1
    local MODULE_PATH="${API_PATH}/${MODULE_NAME}"
    
    # Check if main folder exists
    local folder_existed=false
    if [[ -d "$MODULE_PATH" ]]; then
        folder_existed=true
        log_warning "Folder already exists for $MODULE_NAME, but ensuring all subfolders exist..."
    fi
    
    # Always ensure all subfolders exist, even if main folder exists
    mkdir -p "${MODULE_PATH}/Models" \
             "${MODULE_PATH}/Controllers" \
             "${MODULE_PATH}/Requests" \
             "${MODULE_PATH}/Resources"
    
    if [[ "$folder_existed" == "true" ]]; then
        log_info "Verified all subfolders exist for $MODULE_NAME"
    else
        log_success "Created folders for $MODULE_NAME"
    fi
    
    return 0
}

generate_model() {
    local MODULE_NAME=$1
    local MODEL_PATH="${API_PATH}/${MODULE_NAME}/Models/${MODULE_NAME}.php"
    local TABLE_NAME
    TABLE_NAME=$(get_table_name "$MODULE_NAME")
    
    if [[ -f "$MODEL_PATH" ]]; then
        log_warning "Model already exists for $MODULE_NAME, skipping"
        return 0
    fi
    
    cat > "$MODEL_PATH" <<EOL
<?php

namespace App\Modules\Api\V1\\${MODULE_NAME}\Models;

use App\Models\AtomModel;

class ${MODULE_NAME} extends AtomModel
{
    protected \$table = '${TABLE_NAME}';
}
EOL
    
    # Fix namespace escaping
    safe_replace "Api\\\\V1\\\\\\\\${MODULE_NAME}" "Api\\\\V1\\\\${MODULE_NAME}" "$MODEL_PATH"
    log_success "Model created: $MODEL_PATH"
}

generate_controller() {
    local MODULE_NAME=$1
    local CONTROLLER_PATH="${API_PATH}/${MODULE_NAME}/Controllers/${MODULE_NAME}Controller.php"
    
    if [[ -f "$CONTROLLER_PATH" ]]; then
        log_warning "Controller already exists for $MODULE_NAME, skipping"
        return 0
    fi
    
    local TMP_CONTROLLER_PATH="app/Http/Controllers/${MODULE_NAME}Controller.php"
    
    if php artisan make:controller "${MODULE_NAME}Controller" --api >/dev/null 2>&1; then
        if [[ -f "$TMP_CONTROLLER_PATH" ]]; then
            mv "$TMP_CONTROLLER_PATH" "$CONTROLLER_PATH"
            safe_replace "namespace App\\\Http\\\Controllers" \
                         "namespace App\\\Modules\\\Api\\\V1\\\${MODULE_NAME}\\\Controllers" \
                         "$CONTROLLER_PATH"
            log_success "Controller created: $CONTROLLER_PATH"
        else
            log_error "Controller file not found after generation: $TMP_CONTROLLER_PATH"
            return 1
        fi
    else
        log_error "Failed to create controller for $MODULE_NAME"
        return 1
    fi
}

generate_request() {
    local MODULE_NAME=$1
    local REQUEST_PATH="${API_PATH}/${MODULE_NAME}/Requests/${MODULE_NAME}Request.php"
    
    if [[ -f "$REQUEST_PATH" ]]; then
        log_warning "Request already exists for $MODULE_NAME, skipping"
        return 0
    fi
    
    local TMP_REQUEST_PATH="app/Http/Requests/${MODULE_NAME}Request.php"
    
    if php artisan make:request "${MODULE_NAME}Request" >/dev/null 2>&1; then
        if [[ -f "$TMP_REQUEST_PATH" ]]; then
            mv "$TMP_REQUEST_PATH" "$REQUEST_PATH"
            safe_replace "namespace App\\\Http\\\Requests" \
                         "namespace App\\\Modules\\\Api\\\V1\\\${MODULE_NAME}\\\Requests" \
                         "$REQUEST_PATH"
            log_success "Request created: $REQUEST_PATH"
        else
            log_error "Request file not found after generation: $TMP_REQUEST_PATH"
            return 1
        fi
    else
        log_error "Failed to create request for $MODULE_NAME"
        return 1
    fi
}

generate_resource() {
    local MODULE_NAME=$1
    local RESOURCE_PATH="${API_PATH}/${MODULE_NAME}/Resources/${MODULE_NAME}Resource.php"
    
    if [[ -f "$RESOURCE_PATH" ]]; then
        log_warning "Resource already exists for $MODULE_NAME, skipping"
        return 0
    fi
    
    local TMP_RESOURCE_PATH="app/Http/Resources/${MODULE_NAME}Resource.php"
    
    if php artisan make:resource "${MODULE_NAME}Resource" >/dev/null 2>&1; then
        if [[ -f "$TMP_RESOURCE_PATH" ]]; then
            mv "$TMP_RESOURCE_PATH" "$RESOURCE_PATH"
            safe_replace "namespace App\\\Http\\\Resources" \
                         "namespace App\\\Modules\\\Api\\\V1\\\${MODULE_NAME}\\\Resources" \
                         "$RESOURCE_PATH"
            log_success "Resource created: $RESOURCE_PATH"
        else
            log_error "Resource file not found after generation: $TMP_RESOURCE_PATH"
            return 1
        fi
    else
        log_error "Failed to create resource for $MODULE_NAME"
        return 1
    fi
}

generate_laravel_files() {
    local MODULE_NAME=$1
    local success_count=0
    local skip_count=0
    
    # Process all files independently - don't stop if one exists
    if generate_model "$MODULE_NAME"; then
        ((success_count++))
    else
        ((skip_count++))
    fi
    
    if generate_controller "$MODULE_NAME"; then
        ((success_count++))
    else
        ((skip_count++))
    fi
    
    if generate_request "$MODULE_NAME"; then
        ((success_count++))
    else
        ((skip_count++))
    fi
    
    if generate_resource "$MODULE_NAME"; then
        ((success_count++))
    else
        ((skip_count++))
    fi
    
    # Always return success to continue processing
    return 0
}

# Insert CRM fields + relations (optimized PHP script)
insert_crm_fields() {
    local MODULE_NAME=$1
    local ROOT_PATH="$LARAVEL_ROOT"

    # Always run field insertion - continue even on errors
    MODULE_NAME_ENV="$MODULE_NAME" LARAVEL_ROOT_ENV="$ROOT_PATH" php <<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

// --- DYNAMIC PATH RESOLUTION ---
$rootPath = getenv('LARAVEL_ROOT_ENV') ?: __DIR__;
if (!file_exists("{$rootPath}/vendor/autoload.php")) {
    $rootPath = realpath($rootPath . '/..');
}

require "{$rootPath}/vendor/autoload.php";
$app = require_once "{$rootPath}/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$moduleName = getenv('MODULE_NAME_ENV');
$fieldsFile = "{$rootPath}/app/Models/AtomPen/InitialModuleFields.php";

if (!file_exists($fieldsFile)) {
    echo "   ⚠ Fields file not found: {$fieldsFile} — skipping CRM insert for {$moduleName}\n";
    exit(0);
}

$modulesArray = include $fieldsFile;
if (!isset($modulesArray[$moduleName])) {
    echo "   ↪ No field definitions for {$moduleName} — skipping CRM insert\n";
    exit(0);
}

$fieldDetails = $modulesArray[$moduleName]['field_details'] ?? [];
if (empty($fieldDetails)) {
    echo "   ↪ No fields found for {$moduleName} — skipping CRM insert\n";
    exit(0);
}

$now = Carbon::now();
$tablename = $modulesArray[$moduleName]['module_details']['tablename'] ?? strtolower($moduleName);

// Early exit optimization: Check if all fields already exist in database
$totalFields = count($fieldDetails);
$existingFieldsCount = DB::table('crm_fields')
    ->where('modulename', $moduleName)
    ->whereIn('fieldname', array_column($fieldDetails, 'fieldname'))
    ->count();

if ($existingFieldsCount >= $totalFields && $totalFields > 0) {
    echo "   ↪ All {$totalFields} fields already exist for {$moduleName} — skipping CRM insert\n";
    exit(0);
}

// Enhanced related module detection (align with ModuleRelationFields / migrations)
function detectRelatedModule($fieldName, $moduleName) {
    $map = [
        'organization_id' => 'Organization',
        'contact_id' => 'Contact',
        'customer_id' => 'Contact',
        'entity_id' => 'Contact',
        'parent_id' => 'Contact',
        'invoice_id' => 'Invoice',
        'quotation_id' => 'Quotation',
        'parent_quotation_id' => 'Quotation',
        'converted_to_invoice_id' => 'Invoice',
        'created_by' => 'User',
        'assigned_to' => 'User',
        'issued_by' => 'User',
        'product_id' => 'Product',
        'checklist_id' => 'Checklist',
        'checklist_template_id' => 'ChecklistTemplate',
        'template_item_id' => 'ChecklistTemplateItem',
        'lead_id' => 'Lead',
        'activity_id' => 'Activity',
        'folder_id' => 'Folder',
    ];
    return $map[$fieldName] ?? null;
}

// Batch insert for better performance
$fieldsToInsert = [];
$picklistsToInsert = [];
$relationsToInsert = [];

foreach ($fieldDetails as $index => $field) {
    $fieldName = $field['fieldname'] ?? '';
    if (empty($fieldName)) continue;

    $exists = DB::table('crm_fields')
        ->where('modulename', $moduleName)
        ->where('fieldname', $fieldName)
        ->exists();

    if ($exists) {
        echo "   ↪ Field {$fieldName} already exists in {$moduleName}, skipping\n";
        continue;
    }

    $fieldId = Str::uuid()->toString();

    $fieldsToInsert[] = [
        'id' => $fieldId,
        'modulename' => $moduleName,
        'fieldname' => $fieldName,
        'fieldlabel' => $field['fieldlabel'] ?? '',
        'fieldtype' => $field['fieldtype'] ?? '',
        'tablename' => $tablename,
        'mandatory' => (int)($field['mandatory'] ?? 0),
        'apifieldname' => $field['apifieldname'] ?? '',
        'displaytype' => (int)($field['displaytype'] ?? 1),
        'is_custom_field' => 0,
        'organization_id' => 'default',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted' => 0,
        'seq' => $index + 1,
    ];

    // Handle Picklist Options
    if (($field['fieldtype'] ?? '') === 'picklist' && !empty($field['picklist_options'])) {
        $sortOrder = 1;
        foreach ($field['picklist_options'] as $option) {
            $value = $option['value'] ?? null;
            $label = $option['label'] ?? $value;
            if (!$value) continue;

            $exists = DB::table('picklist_values')
                ->where('field_id', $fieldId)
                ->where('value', $value)
                ->exists();

            if (!$exists) {
                $picklistsToInsert[] = [
                    'id' => Str::uuid()->toString(),
                    'field_id' => $fieldId,
                    'value' => $value,
                    'label' => $label,
                    'sort_order' => $sortOrder++,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
    }

    // Handle Relations
    if (($field['fieldtype'] ?? '') === 'relationPickList') {
        $relatedModule = detectRelatedModule($fieldName, $moduleName);
        if ($relatedModule) {
            $exists = DB::table('module_relation_fields')
                ->where('field_id', $fieldId)
                ->where('modulename', $moduleName)
                ->where('related_module', $relatedModule)
                ->exists();

            if (!$exists) {
                $relationsToInsert[] = [
                    'id' => Str::uuid()->toString(),
                    'field_id' => $fieldId,
                    'modulename' => $moduleName,
                    'related_module' => $relatedModule,
                    'deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
    }
}

// Batch insert fields
if (!empty($fieldsToInsert)) {
    DB::table('crm_fields')->insert($fieldsToInsert);
    echo "   ✅ Inserted " . count($fieldsToInsert) . " fields for {$moduleName}\n";
}

// Batch insert picklists
if (!empty($picklistsToInsert)) {
    DB::table('picklist_values')->insert($picklistsToInsert);
    echo "   🎨 Added " . count($picklistsToInsert) . " picklist options for {$moduleName}\n";
}

// Batch insert relations
if (!empty($relationsToInsert)) {
    DB::table('module_relation_fields')->insert($relationsToInsert);
    echo "   🔗 Added " . count($relationsToInsert) . " relations for {$moduleName}\n";
}

// Insert static relation fields if file exists (resolve field_id name -> crm_fields.id, generate UUID when id is null)
$relationFile = "{$rootPath}/app/Models/AtomPen/ModuleRelationFields.php";
if (file_exists($relationFile)) {
    $relations = include $relationFile;
    $staticRelationsToInsert = [];
    
    foreach ($relations as $relation) {
        if (empty($relation['field_id'])) continue;
        
        // Resolve field_id (field name) to crm_fields.id (UUID) for this module
        $fieldUuid = DB::table('crm_fields')
            ->where('modulename', $relation['modulename'])
            ->where('fieldname', $relation['field_id'])
            ->value('id');
        
        if (!$fieldUuid) continue;
        
        $exists = DB::table('module_relation_fields')
            ->where('field_id', $fieldUuid)
            ->where('modulename', $relation['modulename'])
            ->where('related_module', $relation['related_module'])
            ->exists();

        if (!$exists) {
            $row = [
                'id' => empty($relation['id']) ? \Illuminate\Support\Str::uuid()->toString() : $relation['id'],
                'field_id' => $fieldUuid,
                'modulename' => $relation['modulename'],
                'related_module' => $relation['related_module'],
                'deleted' => (int)($relation['deleted'] ?? 0),
                'created_at' => isset($relation['created_at']) ? $relation['created_at'] : $now,
                'updated_at' => isset($relation['updated_at']) ? $relation['updated_at'] : $now,
            ];
            $staticRelationsToInsert[] = $row;
        }
    }
    
    if (!empty($staticRelationsToInsert)) {
        DB::table('module_relation_fields')->insert($staticRelationsToInsert);
        echo "   🔗 Inserted " . count($staticRelationsToInsert) . " static relations\n";
    }
}
PHP

    # Always return success to continue processing other modules
    return 0
}

# Insert static system tables (runs once, not per module)
insert_static_tables() {
    local ROOT_PATH="$LARAVEL_ROOT"

    LARAVEL_ROOT_ENV="$ROOT_PATH" php <<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// --- DYNAMIC PATH RESOLUTION ---
$rootPath = getenv('LARAVEL_ROOT_ENV') ?: __DIR__;
if (!file_exists("{$rootPath}/vendor/autoload.php")) {
    $rootPath = realpath($rootPath . '/..');
}

require "{$rootPath}/vendor/autoload.php";
$app = require_once "{$rootPath}/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$now = Carbon::now();
$insertedCount = 0;

// 1. Field Operators
$fieldOperatorsFile = "{$rootPath}/app/Models/AtomPen/FieldOperators.php";
if (file_exists($fieldOperatorsFile)) {
    $operators = include $fieldOperatorsFile;
    $operatorsToInsert = [];
    
    foreach ($operators as $operator) {
        $exists = DB::table('field_operators')
            ->where('id', $operator['id'])
            ->exists();
        
        if (!$exists) {
            $operatorsToInsert[] = array_merge($operator, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
    
    if (!empty($operatorsToInsert)) {
        DB::table('field_operators')->insert($operatorsToInsert);
        $insertedCount += count($operatorsToInsert);
        echo "   ✅ Inserted " . count($operatorsToInsert) . " field operators\n";
    }
}

// 2. Global Actions
$globalActionsFile = "{$rootPath}/app/Models/AtomPen/GlobalActions.php";
if (file_exists($globalActionsFile)) {
    $actions = include $globalActionsFile;
    $actionsToInsert = [];
    
    foreach ($actions as $action) {
        $exists = DB::table('global_actions')
            ->where('id', $action['id'])
            ->exists();
        
        if (!$exists) {
            $actionsToInsert[] = $action;
        }
    }
    
    if (!empty($actionsToInsert)) {
        DB::table('global_actions')->insert($actionsToInsert);
        $insertedCount += count($actionsToInsert);
        echo "   ✅ Inserted " . count($actionsToInsert) . " global actions\n";
    }
}

// 3. Module Record Label Fields (generate UUID when id is null)
$recordLabelFile = "{$rootPath}/app/Models/AtomPen/ModuleRecordLabelFields.php";
if (file_exists($recordLabelFile)) {
    $labels = include $recordLabelFile;
    $labelsToInsert = [];
    
    foreach ($labels as $label) {
        $exists = DB::table('module_record_label_fields')
            ->where('module_name', $label['module_name'])
            ->where('field_name', $label['field_name'])
            ->exists();
        
        if (!$exists) {
            $row = $label;
            if (empty($row['id'])) {
                $row['id'] = \Illuminate\Support\Str::uuid()->toString();
            }
            $labelsToInsert[] = $row;
        }
    }
    
    if (!empty($labelsToInsert)) {
        DB::table('module_record_label_fields')->insert($labelsToInsert);
        $insertedCount += count($labelsToInsert);
        echo "   ✅ Inserted " . count($labelsToInsert) . " module record label fields\n";
    }
}

// 4. Portal Module (generate UUID when id is null)
$portalModuleFile = "{$rootPath}/app/Models/AtomPen/PortalModule.php";
if (file_exists($portalModuleFile)) {
    $modules = include $portalModuleFile;
    $modulesToInsert = [];
    
    foreach ($modules as $module) {
        $exists = DB::table('portal_module')
            ->where('modulename', $module['modulename'])
            ->exists();
        
        if (!$exists) {
            $row = array_merge($module, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (empty($row['id'])) {
                $row['id'] = \Illuminate\Support\Str::uuid()->toString();
            }
            $modulesToInsert[] = $row;
        }
    }
    
    if (!empty($modulesToInsert)) {
        DB::table('portal_module')->insert($modulesToInsert);
        $insertedCount += count($modulesToInsert);
        echo "   ✅ Inserted " . count($modulesToInsert) . " portal modules\n";
    }
}

// 5. Searchable Modules
$searchableFile = "{$rootPath}/app/Models/AtomPen/SearchableModules.php";
if (file_exists($searchableFile)) {
    $searchables = include $searchableFile;
    $searchablesToInsert = [];
    
    foreach ($searchables as $searchable) {
        $exists = DB::table('searchable_modules')
            ->where('id', $searchable['id'])
            ->exists();
        
        if (!$exists) {
            $searchablesToInsert[] = array_merge($searchable, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
    
    if (!empty($searchablesToInsert)) {
        DB::table('searchable_modules')->insert($searchablesToInsert);
        $insertedCount += count($searchablesToInsert);
        echo "   ✅ Inserted " . count($searchablesToInsert) . " searchable modules\n";
    }
}

// 6. System Actions
$systemActionsFile = "{$rootPath}/app/Models/AtomPen/SystemActions.php";
if (file_exists($systemActionsFile)) {
    $systemActions = include $systemActionsFile;
    $systemActionsToInsert = [];
    
    foreach ($systemActions as $action) {
        $exists = DB::table('system_actions')
            ->where('id', $action['id'])
            ->exists();
        
        if (!$exists) {
            $systemActionsToInsert[] = array_merge($action, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
    
    if (!empty($systemActionsToInsert)) {
        DB::table('system_actions')->insert($systemActionsToInsert);
        $insertedCount += count($systemActionsToInsert);
        echo "   ✅ Inserted " . count($systemActionsToInsert) . " system actions\n";
    }
}

if ($insertedCount > 0) {
    echo "\n   📊 Total static entries inserted: {$insertedCount}\n";
} else {
    echo "   ↪ All static tables already populated\n";
}
PHP
}

# --- DEPENDENCY INSTALLATION FUNCTION ---
install_dependencies() {
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📦 Installing Dependencies"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    
    cd "$LARAVEL_ROOT"
    
    # Check if composer.json exists
    if [ ! -f "composer.json" ]; then
        log_error "composer.json not found. Is this a Laravel project?"
        exit 1
    fi
    
    # Install Composer dependencies
    if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
        log_info "Installing Composer dependencies (this may take a while)..."
        if composer install --no-interaction --prefer-dist --optimize-autoloader; then
            log_success "Composer dependencies installed successfully"
        else
            log_error "Failed to install Composer dependencies"
            log_error "Please ensure:"
            log_error "  - Composer is installed and accessible"
            log_error "  - You have internet connectivity"
            log_error "  - PHP extensions are properly configured"
            exit 1
        fi
    else
        log_info "Composer dependencies already installed"
        # Verify autoloader is up to date
        log_info "Verifying Composer autoloader..."
        if composer dump-autoload --optimize --quiet 2>/dev/null; then
            log_success "Composer autoloader verified"
        else
            log_warning "Autoloader verification failed (non-critical, continuing...)"
        fi
    fi
    
    # Install npm dependencies if package.json exists
    if [ -f "package.json" ]; then
        if command -v npm &> /dev/null; then
            if [ ! -d "node_modules" ]; then
                log_info "Installing npm dependencies..."
                if npm install --silent; then
                    log_success "NPM dependencies installed successfully"
                else
                    log_warning "NPM dependency installation failed (non-critical, continuing...)"
                fi
            else
                log_info "NPM dependencies already installed"
            fi
        else
            log_warning "npm not found. Skipping frontend dependencies."
            log_warning "Install Node.js and npm if you need frontend assets."
        fi
    fi
    
    echo ""
    log_success "Dependency installation completed!"
    echo ""
}

# --- STORAGE PERMISSIONS ---
set_storage_permissions() {
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📁 Setting storage and cache permissions"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    
    cd "$LARAVEL_ROOT"
    
    if [ -d "storage" ] || [ -d "bootstrap/cache" ]; then
        log_info "Setting ownership to www-data:www-data and permissions to 775..."
        if sudo chown -R www-data:www-data storage bootstrap/cache 2>/dev/null; then
            log_success "Ownership set for storage and bootstrap/cache"
        else
            log_warning "Could not set ownership (sudo chown). You may need to run: sudo chown -R www-data:www-data storage bootstrap/cache"
        fi
        if sudo chmod -R 775 storage bootstrap/cache 2>/dev/null; then
            log_success "Permissions set to 775 for storage and bootstrap/cache"
        else
            log_warning "Could not set permissions (sudo chmod). You may need to run: sudo chmod -R 775 storage bootstrap/cache"
        fi
    else
        log_warning "storage or bootstrap/cache directory not found, skipping permission setup"
    fi
    
    echo ""
    log_success "Storage permission setup completed!"
    echo ""
}

# --- MAIN SCRIPT ---
main() {
    # Step 1: Verify installer password
    verify_installer_password
    
    # Step 2: Install dependencies
    install_dependencies
    
    # Step 3: Set storage and bootstrap/cache permissions
    set_storage_permissions
    
    # Step 4: Setup database
    setup_database
    
    # Step 5: Continue with module generation
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "🚀 Starting Module Generation Script"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""

    # Insert static system tables (runs once)
    echo "📦 Populating static system tables..."
    insert_static_tables
    echo ""

    # Check if specific module provided
    if [[ $# -gt 0 ]]; then
        MODULE_NAME="$1"
        if [[ " ${MODULES[@]} " =~ " ${MODULE_NAME} " ]]; then
            log_info "Processing single module: **$MODULE_NAME**"
            
            # Process everything independently - always continue even if something exists
            create_folders "$MODULE_NAME" || true
            generate_laravel_files "$MODULE_NAME" || true
            insert_crm_fields "$MODULE_NAME" || true
            
            log_success "Module $MODULE_NAME processed successfully!"
        else
            log_error "Module '$MODULE_NAME' not found in MODULES list"
            exit 1
        fi
    else
        # Process all modules
        local processed=0
        
        for MODULE_NAME in "${MODULES[@]}"; do
            echo ""
            log_info "Processing module: **$MODULE_NAME**"
            
            # Process everything independently - always continue even if something exists
            # Use || true to ensure script never stops due to existing items
            create_folders "$MODULE_NAME" || true
            generate_laravel_files "$MODULE_NAME" || true
            insert_crm_fields "$MODULE_NAME" || true
            
            ((processed++))
        done
        
        echo ""
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log_success "All modules processed! ($processed modules processed)"
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    fi
}

# Run main function
main "$@"