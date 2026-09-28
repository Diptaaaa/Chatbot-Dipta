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

$current_user_id = get_current_user_id();
$user_token = $_COOKIE['dipta_uid'] ?? '';

if (!$current_user_id && (empty($user_token) || !preg_match('/^[a-f0-9]{32}$/', $user_token))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sesi pengguna tidak valid. Silakan muat ulang halaman.']);
    exit;
}

// --- AKSI: UBAH JUDUL ROOM (RENAME) ---
if (isset($_POST['action']) && $_POST['action'] === 'rename_room') {
    $new_title = trim($_POST['judul'] ?? '');
    $room_id = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$room_id || $room_id <= 0 || $new_title === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Judul dan ID obrolan wajib diisi.']);
        exit;
    }
    $new_title = mb_substr($new_title, 0, 100);
    if ($current_user_id) {
        $stmt = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ? AND user_id = ?");
        if ($stmt) $stmt->bind_param("sii", $new_title, $room_id, $current_user_id);
    } else {
        $stmt = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ? AND (user_id IS NULL OR user_id = 0) AND user_token = ?");
        if ($stmt) {
            $stmt->bind_param("sis", $new_title, $room_id, $user_token);
        } else {
            $stmt = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ?");
            if ($stmt) $stmt->bind_param("si", $new_title, $room_id);
        }
    }
    if ($stmt && $stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'new_title' => $new_title]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal mengubah nama obrolan.']);
    }
    exit;
}

$pesan = trim($_POST['pesan'] ?? '');
$room_id = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
$selectedModel = trim($_POST['model'] ?? '');
$isStream = isset($_POST['stream']) && ($_POST['stream'] === '1' || $_POST['stream'] === 'true');

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

// Validasi kepemilikan room (Pengguna Login vs Pengguna Tamu)
$stmtCheck = null;
if ($current_user_id) {
    $stmtCheck = $conn->prepare("SELECT judul FROM rooms WHERE id = ? AND user_id = ?");
    if ($stmtCheck) $stmtCheck->bind_param("ii", $room_id, $current_user_id);
} else {
    $stmtCheck = $conn->prepare("SELECT judul FROM rooms WHERE id = ? AND (user_id IS NULL OR user_id = 0) AND user_token = ?");
    if ($stmtCheck) {
        $stmtCheck->bind_param("is", $room_id, $user_token);
    } else {
        $stmtCheck = $conn->prepare("SELECT judul FROM rooms WHERE id = ?");
        if ($stmtCheck) $stmtCheck->bind_param("i", $room_id);
    }
}

if (!$stmtCheck) {
    error_log("Database prepare error: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan internal pada server.']);
    exit;
}
$stmtCheck->execute();
$roomData = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();

if (!$roomData) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Ruang obrolan tidak ditemukan atau bukan milik Anda.']);
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
    if ($current_user_id) {
        $stmtUp = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ? AND user_id = ?");
        $stmtUp->bind_param("sii", $shortTitle, $room_id, $current_user_id);
    } else {
        $stmtUp = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ? AND user_id IS NULL AND user_token = ?");
        $stmtUp->bind_param("sis", $shortTitle, $room_id, $user_token);
    }
    if ($stmtUp) {
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
    $history = array_reverse($history);
}

// 3. Mode Streaming Server-Sent Events (SSE)
if ($isStream) {
    // Matikan batasan waktu eksekusi untuk streaming respons panjang
    set_time_limit(60);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Nonaktifkan buffer di Nginx & Vercel Proxy

    if ($newTitle) {
        echo "event: title\ndata: " . json_encode(['new_title' => $newTitle], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    $aiResult = stream_groq_reply($pesan, $history, $selectedModel, function($chunk) {
        echo "event: chunk\ndata: " . json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    });

    if (!$aiResult['success'] && empty($aiResult['reply'])) {
        echo "event: error\ndata: " . json_encode(['error' => $aiResult['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
        exit;
    }

    $balasan = $aiResult['reply'];

    // Simpan balasan bot ke database
    $stmtBot = $conn->prepare("INSERT INTO chat (sender, text, room_id) VALUES ('bot', ?, ?)");
    if ($stmtBot) {
        $stmtBot->bind_param("si", $balasan, $room_id);
        $stmtBot->execute();
        $stmtBot->close();
    }

    echo "event: done\ndata: " . json_encode([
        'success' => true,
        'model' => $aiResult['model'] ?? '',
        'new_title' => $newTitle
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit;
}

// 4. Mode Standar (Fallback Non-Streaming)
$aiResult = get_groq_reply($pesan, $history, $selectedModel);
if (!$aiResult['success']) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => $aiResult['error']
    ]);
    exit;
}

$balasan = $aiResult['reply'];

// Simpan balasan bot ke database
$stmtBot = $conn->prepare("INSERT INTO chat (sender, text, room_id) VALUES ('bot', ?, ?)");
if ($stmtBot) {
    $stmtBot->bind_param("si", $balasan, $room_id);
    $stmtBot->execute();
    $stmtBot->close();
}

// Kembalikan respons JSON
echo json_encode([
    'success' => true,
    'reply' => $balasan,
    'new_title' => $newTitle,
    'model' => $aiResult['model'] ?? ''
], JSON_UNESCAPED_UNICODE);