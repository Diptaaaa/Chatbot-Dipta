<?php
/**
 * Konfigurasi Terpusat Aplikasi Chatbot Dipta
 */

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
