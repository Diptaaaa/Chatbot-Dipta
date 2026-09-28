<?php
/**
 * Konfigurasi Terpusat Aplikasi Chatbot Dipta
 */

// Matikan tampilan notice/error ke output HTTP agar respons JSON tidak rusak di serverless
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Muat konfigurasi dari file .env lokal jika ada
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Konfigurasi Database MySQL (Mendukung Environment Variables)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', intval(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');
define('DB_NAME', getenv('DB_NAME') ?: 'chatapp');

// Konfigurasi Groq AI API
// Dapatkan API Key di https://console.groq.com/keys
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

// Inisialisasi Koneksi Database (Mendukung Localhost & TiDB Cloud SSL)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
if (!$conn) {
    error_log("mysqli_init failed");
    http_response_code(500);
    die("Inisialisasi database gagal.");
}

$isRemote = (DB_HOST !== 'localhost' && DB_HOST !== '127.0.0.1');
if ($isRemote && defined('MYSQLI_CLIENT_SSL')) {
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);
} else {
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

if (!$connected || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?: mysqli_connect_error()));
    http_response_code(500);
    die("Koneksi database gagal. Silakan periksa konfigurasi server.");
}

// Pastikan charset UTF-8 mb4 untuk mendukung karakter internasional & emoji
$conn->set_charset("utf8mb4");

// Auto-migrasi ringan kompatibel dengan TiDB Cloud & MySQL
// 1. Pastikan tabel users ada
@$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 2. Pastikan kolom user_token ada di tabel rooms (tiap ALTER TABLE single-clause untuk TiDB)
$colToken = @$conn->query("SHOW COLUMNS FROM rooms LIKE 'user_token'");
if ($colToken && $colToken->num_rows === 0) {
    @$conn->query("ALTER TABLE rooms ADD COLUMN user_token VARCHAR(64) NOT NULL DEFAULT ''");
    @$conn->query("ALTER TABLE rooms ADD INDEX idx_rooms_user_token (user_token)");
}

// 3. Pastikan kolom user_id ada di tabel rooms
$colUserId = @$conn->query("SHOW COLUMNS FROM rooms LIKE 'user_id'");
if ($colUserId && $colUserId->num_rows === 0) {
    @$conn->query("ALTER TABLE rooms ADD COLUMN user_id INT NULL");
    @$conn->query("ALTER TABLE rooms ADD INDEX idx_rooms_user_id (user_id)");
    @$conn->query("ALTER TABLE rooms ADD CONSTRAINT fk_rooms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
}

// 4. Pastikan kolom created_at ada pada rooms & chat
$colRoomsCreated = @$conn->query("SHOW COLUMNS FROM rooms LIKE 'created_at'");
if ($colRoomsCreated && $colRoomsCreated->num_rows === 0) {
    @$conn->query("ALTER TABLE rooms ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
$colChatCreated = @$conn->query("SHOW COLUMNS FROM chat LIKE 'created_at'");
if ($colChatCreated && $colChatCreated->num_rows === 0) {
    @$conn->query("ALTER TABLE chat ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

/**
 * Autentikasi Pengguna Stateless (Kompatibel dengan Vercel Serverless & Localhost)
 */
function get_current_user_id() {
    if (!empty($_SESSION['user_id'])) {
        return intval($_SESSION['user_id']);
    }
    if (!empty($_COOKIE['dipta_auth'])) {
        $parts = explode('.', $_COOKIE['dipta_auth'], 2);
        if (count($parts) === 2) {
            list($uid, $sig) = $parts;
            $secret = defined('DB_PASS') ? DB_PASS : 'dipta_default_key';
            $expected = hash_hmac('sha256', $uid, $secret);
            if (hash_equals($expected, $sig)) {
                $_SESSION['user_id'] = intval($uid);
                return intval($uid);
            }
        }
    }
    return null;
}

function set_auth_cookie($userId) {
    $_SESSION['user_id'] = intval($userId);
    $secret = defined('DB_PASS') ? DB_PASS : 'dipta_default_key';
    $sig = hash_hmac('sha256', (string)$userId, $secret);
    setcookie('dipta_auth', $userId . '.' . $sig, [
        'expires' => time() + 86400 * 30, // Berlaku 30 hari
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clear_auth_cookie() {
    unset($_SESSION['user_id']);
    setcookie('dipta_auth', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
