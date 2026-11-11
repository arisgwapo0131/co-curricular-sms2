<?php
// ============================================================
//  AI_CONFIG.PHP — Centralized AI Configuration
//  Google Gemini Free Tier (gemini-3.5-flash-lite)
//  Get your free key at: https://aistudio.google.com/apikey
// ============================================================

if (!defined('GEMINI_DEFAULT_KEY')) {
    define('GEMINI_DEFAULT_KEY', '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-2.5-flash');
}
if (!defined('AI_MAX_TOKENS')) {
    define('AI_MAX_TOKENS', 2048);
}
if (!defined('AI_TEMPERATURE')) {
    define('AI_TEMPERATURE', 0.7);
}

/**
 * Helper to check if a key string is a non-placeholder value
 */
function is_configured_api_key_string(string $k): bool {
    $k = trim($k);
    if (empty($k)) return false;
    if (str_contains($k, '••') || preg_match('/^[•\*]+$/u', $k)) return false;
    if (stripos($k, 'YOUR_GEMINI_API_KEY') !== false || stripos($k, 'PLACEHOLDER') !== false) return false;
    return true;
}

/**
 * Retrieve the active Gemini API key from database, session, environment, or default config
 */
function get_gemini_api_key(?mysqli $conn = null): string {
    // 1. Auto-resolve DB connection if not passed or closed
    if (!$conn || !($conn instanceof mysqli) || !@$conn->ping()) {
        global $conn;
    }
    if ((!$conn || !($conn instanceof mysqli) || !@$conn->ping()) && file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    }

    // 2. Query database system_settings (authoritative persistent source)
    if ($conn instanceof mysqli && @$conn->ping()) {
        $check = $conn->query("SHOW TABLES LIKE 'system_settings'");
        if ($check && $check->num_rows > 0) {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $k = trim($row['setting_value'] ?? '');
                if (is_configured_api_key_string($k)) {
                    $_SESSION['gemini_api_key'] = $k;
                    return $k;
                }
            }
        }
    }

    // 3. Check session cache (validate not placeholder)
    if (!empty($_SESSION['gemini_api_key'])) {
        $sessKey = trim($_SESSION['gemini_api_key']);
        if (is_configured_api_key_string($sessKey)) {
            return $sessKey;
        }
    }

    // 4. Check environment variable or .env file
    $envKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
    if (!is_configured_api_key_string($envKey)) {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            $envContent = @file_get_contents($envFile);
            if ($envContent && preg_match('/^GEMINI_API_KEY\s*=\s*["\']?([^"\'\r\n]+)["\']?/m', $envContent, $m)) {
                $envKey = trim($m[1]);
            }
        }
    }
    if (is_configured_api_key_string($envKey)) {
        if ($conn instanceof mysqli && @$conn->ping()) {
            @save_gemini_api_key($envKey, $conn);
        }
        return $envKey;
    }

    $defKey = defined('GEMINI_DEFAULT_KEY') ? GEMINI_DEFAULT_KEY : '';
    if (is_configured_api_key_string($defKey) && $conn instanceof mysqli && @$conn->ping()) {
        @save_gemini_api_key($defKey, $conn);
    }
    return $defKey;
}

/**
 * Save the Gemini API key to DB and session
 */
function save_gemini_api_key(string $key, mysqli $conn): bool {
    $cleanKey = trim($key);
    $_SESSION['gemini_api_key'] = $cleanKey;

    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $cleanKey, $cleanKey);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}

/**
 * Obfuscate sensitive API secrets to prevent plain-text exposure
 * Displays first 4 and last 4 characters separated by bullet masks.
 */
function mask_api_key(string $key): string {
    $trimmed = trim($key);
    $len = strlen($trimmed);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    $prefix = substr($trimmed, 0, 4);
    $suffix = substr($trimmed, -4);
    return $prefix . '••••••••••••••••' . $suffix;
}
