<?php
/**
 * Contoh Konfigurasi Aplikasi Chatbot Dipta
 * Salin file ini menjadi `config.php` lalu sesuaikan dengan kredensial Anda.
 */

// Konfigurasi Database MySQL (Mendukung Environment Variables)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', intval(getenv('DB_PORT') ?: 3306));
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');
define('DB_NAME', getenv('DB_NAME') ?: 'chatapp');

// Konfigurasi Groq AI API
// Dapatkan API Key gratis di https://console.groq.com/keys
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: 'MASUKKAN_GROQ_API_KEY_ANDA_DI_SINI');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

// Inisialisasi Koneksi Database (Mendukung Localhost & TiDB Cloud SSL)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
if (!$conn) {
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
    die("Koneksi database gagal. Silakan periksa kredensial di config.php.");
}

// Pastikan charset UTF-8 mb4 untuk mendukung karakter internasional & emoji
$conn->set_charset("utf8mb4");
