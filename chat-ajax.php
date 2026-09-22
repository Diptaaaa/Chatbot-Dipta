<?php
/**
 * Endpoint AJAX Percakapan Chatbot Dipta
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/groq.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metode permintaan tidak diizinkan.']);
    exit;
}

$pesan = trim($_POST['pesan'] ?? '');
$room_id = intval($_POST['room_id'] ?? 1);

if ($pesan === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pesan tidak boleh kosong.']);
    exit;
}

// 1. Simpan pesan pengguna dengan Prepared Statement
$stmt = $conn->prepare("INSERT INTO chat (sender, text, room_id) VALUES ('user', ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal mempersiapkan query: ' . $conn->error]);
    exit;
}
$stmt->bind_param("si", $pesan, $room_id);
$stmt->execute();
$stmt->close();

// Update nama room otomatis jika sebelumnya masih nama default
$newTitle = null;
$stmtCheck = $conn->prepare("SELECT judul FROM rooms WHERE id = ?");
if ($stmtCheck) {
    $stmtCheck->bind_param("i", $room_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($rCheck = $resCheck->fetch_assoc()) {
        if ($rCheck['judul'] === 'Obrolan Baru' || $rCheck['judul'] === 'Obrolan Utama') {
            $shortTitle = mb_substr($pesan, 0, 30);
            if (mb_strlen($pesan) > 30) $shortTitle .= '...';
            $stmtUp = $conn->prepare("UPDATE rooms SET judul = ? WHERE id = ?");
            if ($stmtUp) {
                $stmtUp->bind_param("si", $shortTitle, $room_id);
                $stmtUp->execute();
                $stmtUp->close();
                $newTitle = $shortTitle;
            }
        }
    }
    $stmtCheck->close();
}

// 2. Ambil 10 riwayat pesan terakhir dari room ini untuk konteks AI
$history = [];
$stmtHist = $conn->prepare("SELECT sender, text FROM chat WHERE room_id = ? ORDER BY id DESC LIMIT 10");
if ($stmtHist) {
    $stmtHist->bind_param("i", $room_id);
    $stmtHist->execute();
    $resHist = $stmtHist->get_result();
    while ($row = $resHist->fetch_assoc()) {
        $history[] = $row;
    }
    $stmtHist->close();
    // Urutkan dari pesan terlama ke terbaru
    $history = array_reverse($history);
}

// 3. Dapatkan balasan dari Groq AI dengan context memory
$balasan = get_groq_reply($pesan, $history);

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
]);