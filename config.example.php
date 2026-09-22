<?php
/**
 * Contoh Konfigurasi Aplikasi Chatbot Dipta
 * Salin file ini menjadi `config.php` lalu sesuaikan dengan kredensial Anda.
 */

// Konfigurasi Database MySQL
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');
define('DB_NAME', getenv('DB_NAME') ?: 'chatapp');

// Konfigurasi Groq AI API
// Dapatkan API Key gratis di https://console.groq.com/keys
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: 'MASUKKAN_GROQ_API_KEY_ANDA_DI_SINI');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

// Inisialisasi Koneksi Database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Pastikan charset UTF-8 mb4 untuk mendukung karakter internasional & emoji
$conn->set_charset("utf8mb4");
