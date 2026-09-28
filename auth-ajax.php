<?php
/**
 * Endpoint AJAX Autentikasi Pengguna (Register, Login, Logout)
 * Ringan, cepat, aman, dan stateless (kompatibel penuh dengan Vercel Serverless)
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = trim($_POST['action'] ?? ($_GET['action'] ?? ''));

// --- AKSI: LOGOUT ---
// Logout harus selalu berhasil dan tidak boleh terblokir oleh token CSRF kedaluwarsa
if ($action === 'logout') {
    clear_auth_cookie();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header("Location: index.php");
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Berhasil keluar.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode permintaan tidak diizinkan.']);
    exit;
}

// Validasi Token CSRF (untuk Login & Register)
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
$expected_token = $_COOKIE['dipta_csrf'] ?? ($_SESSION['csrf_token'] ?? '');

if (empty($csrf_token) || empty($expected_token) || !hash_equals($expected_token, $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sesi kedaluwarsa atau token CSRF tidak valid. Silakan muat ulang halaman.']);
    exit;
}

$guest_token = $_COOKIE['dipta_uid'] ?? '';

// --- AKSI: REGISTER ---
if ($action === 'register') {
    $nama = trim($_POST['nama'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($nama === '' || $email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Semua kolom wajib diisi.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Format alamat email tidak valid.']);
        exit;
    }

    // Kebijakan Keamanan Password Ketat
    if (mb_strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kata sandi minimal 8 karakter.']);
        exit;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kata sandi harus mengandung minimal 1 huruf kapital (A-Z).']);
        exit;
    }
    if (!preg_match('/[a-z]/', $password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kata sandi harus mengandung minimal 1 huruf kecil (a-z).']);
        exit;
    }
    if (!preg_match('/[0-9]/', $password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kata sandi harus mengandung minimal 1 angka (0-9).']);
        exit;
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kata sandi harus mengandung minimal 1 simbol khusus (misal: !@#$%^&*).']);
        exit;
    }

    // Periksa apakah email sudah terdaftar
    $stmtCheck = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if ($stmtCheck) {
        $stmtCheck->bind_param("s", $email);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        if ($resCheck->num_rows > 0) {
            $stmtCheck->close();
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Email ini sudah terdaftar. Silakan masuk.']);
            exit;
        }
        $stmtCheck->close();
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (nama, email, password_hash) VALUES (?, ?, ?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal mendaftar pengguna baru.']);
        exit;
    }

    $stmt->bind_param("sss", $nama, $email, $passwordHash);
    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Terjadi kendala saat menyimpan akun.']);
        exit;
    }
    $newUserId = $conn->insert_id;
    $stmt->close();

    // Set autentikasi
    set_auth_cookie($newUserId);

    // Migrasikan obrolan guest saat ini ke akun pengguna baru
    if (!empty($guest_token)) {
        $stmtClaim = $conn->prepare("UPDATE rooms SET user_id = ? WHERE user_token = ? AND user_id IS NULL");
        if ($stmtClaim) {
            $stmtClaim->bind_param("is", $newUserId, $guest_token);
            $stmtClaim->execute();
            $stmtClaim->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Pendaftaran berhasil!',
        'user' => [
            'id' => $newUserId,
            'nama' => $nama,
            'email' => $email
        ]
    ]);
    exit;
}

// --- AKSI: LOGIN ---
if ($action === 'login') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email dan kata sandi wajib diisi.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, nama, email, password_hash FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Terjadi kendala pada server database.']);
        exit;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email atau kata sandi tidak cocok.']);
        exit;
    }

    // Set autentikasi
    set_auth_cookie($user['id']);

    // Migrasikan obrolan guest saat ini ke akun pengguna
    if (!empty($guest_token)) {
        $stmtClaim = $conn->prepare("UPDATE rooms SET user_id = ? WHERE user_token = ? AND user_id IS NULL");
        if ($stmtClaim) {
            $stmtClaim->bind_param("is", $user['id'], $guest_token);
            $stmtClaim->execute();
            $stmtClaim->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Berhasil masuk!',
        'user' => [
            'id' => $user['id'],
            'nama' => $user['nama'],
            'email' => $user['email']
        ]
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
