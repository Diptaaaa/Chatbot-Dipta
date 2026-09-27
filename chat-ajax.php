<?php
/**
 * Endpoint AJAX Percakapan Chatbot Dipta
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/groq.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode permintaan tidak diizinkan.']);
    exit;
}

// Validasi Token CSRF (Mendukung Session Lokal & Stateless Double-Submit Cookie di Serverless Vercel)
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
$expected_token = $_COOKIE['dipta_csrf'] ?? ($_SESSION['csrf_token'] ?? '');

if (empty($csrf_token) || empty($expected_token) || !hash_equals($expected_token, $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sesi kedaluwarsa atau token keamanan CSRF tidak valid. Silakan muat ulang halaman.']);
    exit;
}

$pesan = trim($_POST['pesan'] ?? '');
$room_id = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);

if ($pesan === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pesan tidak boleh kosong.']);
    exit;
}

if (mb_strlen($pesan) > 4000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pesan terlalu panjang (maksimal 4.000 karakter).']);
    exit;
}

if (!$room_id || $room_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID ruang obrolan tidak valid.']);
    exit;
}

// Validasi keberadaan room di database
$stmtCheck = $conn->prepare("SELECT judul FROM rooms WHERE id = ?");
if (!$stmtCheck) {
    error_log("Database prepare error: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan internal pada server.']);
    exit;
}
$stmtCheck->bind_param("i", $room_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
$roomData = $resCheck->fetch_assoc();
$stmtCheck->close();

if (!$roomData) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Ruang obrolan tidak ditemukan.']);
    exit;
}

// 1. Simpan pesan pengguna dengan Prepared Statement
$stmt = $conn->prepare("INSERT INTO chat (sender, text, room_id) VALUES ('user', ?, ?)");
if (!$stmt) {
    error_log("Database prepare error: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memproses pesan di database.']);
    exit;
}
$stmt->bind_param("si", $pesan, $room_id);
if (!$stmt->execute()) {
    error_log("Database execute error: " . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal menyimpan pesan ke database.']);
    exit;
}
$stmt->close();

// Update nama room otomatis jika sebelumnya masih nama default
$newTitle = null;
if ($roomData['judul'] === 'Obrolan Baru' || $roomData['judul'] === 'Obrolan Utama') {
    $shortTitle = mb_substr($pesan, 0, 30);
    if (mb_strlen($pesan) > 30) $shortTitle .= '...';
    $stmtUp = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ?");
    if ($stmtUp) {
        $stmtUp->bind_param("si", $shortTitle, $room_id);
        if ($stmtUp->execute()) {
            $newTitle = $shortTitle;
        }
        $stmtUp->close();
    }
}

// 2. Ambil 10 riwayat pesan terakhir dari room ini untuk konteks AI
$history = [];
$stmtHist = $conn->prepare("SELECT sender, text FROM chat WHERE room_id = ? ORDER BY id DESC LIMIT 10");
if ($stmtHist) {
    $stmtHist->bind_param("i", $room_id);
    if ($stmtHist->execute()) {
        $resHist = $stmtHist->get_result();
        while ($row = $resHist->fetch_assoc()) {
            $history[] = $row;
        }
    }
    $stmtHist->close();
    // Urutkan dari pesan terlama ke terbaru
    $history = array_reverse($history);
}

// 3. Dapatkan balasan dari Groq AI dengan context memory
$aiResult = get_groq_reply($pesan, $history);
if (!$aiResult['success']) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => $aiResult['error']
    ]);
    exit;
}

$balasan = $aiResult['reply'];

// 4. Simpan balasan bot ke database dengan Prepared Statement
$stmtBot = $conn->prepare("INSERT INTO chat (sender, text, room_id) VALUES ('bot', ?, ?)");
if ($stmtBot) {
    $stmtBot->bind_param("si", $balasan, $room_id);
    $stmtBot->execute();
    $stmtBot->close();
}

// 5. Kembalikan respons JSON
echo json_encode([
    'success' => true,
    'reply' => $balasan,
    'new_title' => $newTitle
], JSON_UNESCAPED_UNICODE);