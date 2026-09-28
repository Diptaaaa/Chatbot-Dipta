<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/groq.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inisialisasi Token CSRF (Mendukung Session Lokal & Stateless Double-Submit Cookie di Serverless Vercel)
if (empty($_COOKIE['dipta_csrf'])) {
    $csrf_token = bin2hex(random_bytes(32));
    setcookie('dipta_csrf', $csrf_token, [
        'expires' => time() + 86400 * 7,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
} else {
    $csrf_token = $_COOKIE['dipta_csrf'];
}
$_SESSION['csrf_token'] = $csrf_token;

function get_expected_csrf_token() {
    return $_COOKIE['dipta_csrf'] ?? ($_SESSION['csrf_token'] ?? '');
}

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Inisialisasi Token Pengguna Unik (Isolasi data riwayat percakapan per perangkat)
if (empty($_COOKIE['dipta_uid']) || !preg_match('/^[a-f0-9]{32}$/', $_COOKIE['dipta_uid'])) {
    $user_token = bin2hex(random_bytes(16));
    setcookie('dipta_uid', $user_token, [
        'expires' => time() + 86400 * 365,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE['dipta_uid'] = $user_token;
} else {
    $user_token = $_COOKIE['dipta_uid'];
}

// --- Tangani Logout Langsung (Mencegah kendala AJAX/CSRF/Cache di Production) ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    clear_auth_cookie();
    header("Location: index.php");
    exit;
}

// Cek Pengguna yang Sedang Login (Autentikasi Hybrid)
$current_user_id = get_current_user_id();
$currentUser = null;
if ($current_user_id) {
    $stmtUser = $conn->prepare("SELECT id, nama, email FROM users WHERE id = ? LIMIT 1");
    if ($stmtUser) {
        $stmtUser->bind_param("i", $current_user_id);
        $stmtUser->execute();
        $currentUser = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();
    }
}

// Migrasikan obrolan lama jika masih ada yang belum memiliki user_token
$hasLegacy = $conn->query("SELECT id FROM rooms WHERE user_token = '' LIMIT 1");
if ($hasLegacy && $hasLegacy->num_rows > 0) {
    $stmtClaim = $conn->prepare("UPDATE rooms SET user_token = ? WHERE user_token = ''");
    if ($stmtClaim) {
        $stmtClaim->bind_param("s", $user_token);
        $stmtClaim->execute();
        $stmtClaim->close();
    }
}

// --- Buat Obrolan Baru (Shortcut ?new=1 atau POST) ---
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (isset($_GET['new']) || ($requestMethod === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_room')) {
    if ($requestMethod === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals(get_expected_csrf_token(), $token)) {
            http_response_code(403);
            die("Token keamanan tidak valid.");
        }
    }
    $default_title = "Obrolan Baru";
    if ($current_user_id) {
        $stmt = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sis", $user_token, $current_user_id, $default_title);
            $stmt->execute();
            $new_id = $conn->insert_id;
            $stmt->close();
            header("Location: index.php?room_id=" . $new_id);
            exit;
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, NULL, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $user_token, $default_title);
            $stmt->execute();
            $new_id = $conn->insert_id;
            $stmt->close();
            header("Location: index.php?room_id=" . $new_id);
            exit;
        } else {
            $stmt = $conn->prepare("INSERT INTO rooms (judul) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param("s", $default_title);
                $stmt->execute();
                $new_id = $conn->insert_id;
                $stmt->close();
                header("Location: index.php?room_id=" . $new_id);
                exit;
            }
        }
    }
}

// --- Tambah Room Manual via Form ---
if ($requestMethod === 'POST' && isset($_POST['judul_room']) && trim($_POST['judul_room']) !== '') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(get_expected_csrf_token(), $token)) {
        http_response_code(403);
        die("Token keamanan tidak valid.");
    }
    $judul = trim($_POST['judul_room']);
    if ($current_user_id) {
        $stmt = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sis", $user_token, $current_user_id, $judul);
            $stmt->execute();
            $new_id = $conn->insert_id;
            $stmt->close();
            header("Location: index.php?room_id=" . $new_id);
            exit;
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, NULL, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $user_token, $judul);
            $stmt->execute();
            $new_id = $conn->insert_id;
            $stmt->close();
            header("Location: index.php?room_id=" . $new_id);
            exit;
        } else {
            $stmt = $conn->prepare("INSERT INTO rooms (judul) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param("s", $judul);
                $stmt->execute();
                $new_id = $conn->insert_id;
                $stmt->close();
                header("Location: index.php?room_id=" . $new_id);
                exit;
            }
        }
    }
}

// --- Hapus Room via POST dengan Proteksi CSRF & Batas Hak Akses User ---
if ($requestMethod === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_room') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(get_expected_csrf_token(), $token)) {
        http_response_code(403);
        die("Token keamanan CSRF tidak valid.");
    }
    $hapus_id = intval($_POST['hapus_id'] ?? 0);
    if ($hapus_id > 0) {
        if ($current_user_id) {
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ? AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $hapus_id, $current_user_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ? AND (user_id IS NULL OR user_id = 0) AND user_token = ?");
            if ($stmt) {
                $stmt->bind_param("is", $hapus_id, $user_token);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $hapus_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
    header("Location: index.php");
    exit;
}

// --- Ambil Daftar Room Pengguna Ini (Urutkan dari yang terbaru) ---
$rooms = [];
$stmtRooms = null;
if ($current_user_id) {
    $stmtRooms = $conn->prepare("SELECT * FROM rooms WHERE user_id = ? ORDER BY id DESC");
    if ($stmtRooms) {
        $stmtRooms->bind_param("i", $current_user_id);
    }
} else {
    $stmtRooms = $conn->prepare("SELECT * FROM rooms WHERE (user_id IS NULL OR user_id = 0) AND user_token = ? ORDER BY id DESC");
    if ($stmtRooms) {
        $stmtRooms->bind_param("s", $user_token);
    } else {
        // Fallback jika skema kolom user_token belum termigrasi di remote DB
        $stmtRooms = $conn->prepare("SELECT * FROM rooms ORDER BY id DESC");
    }
}

if ($stmtRooms) {
    $stmtRooms->execute();
    $res = $stmtRooms->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rooms[] = $row;
        }
    }
    $stmtRooms->close();
}

// Jika belum ada room sama sekali untuk pengguna ini di database, buat room default
if (empty($rooms)) {
    if ($current_user_id) {
        $stmtInit = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, ?, 'Obrolan Baru')");
        if ($stmtInit) {
            $stmtInit->bind_param("si", $user_token, $current_user_id);
            $stmtInit->execute();
            $stmtInit->close();
        }
    } else {
        $stmtInit = $conn->prepare("INSERT INTO rooms (user_token, user_id, judul) VALUES (?, NULL, 'Obrolan Baru')");
        if ($stmtInit) {
            $stmtInit->bind_param("s", $user_token);
            $stmtInit->execute();
            $stmtInit->close();
        } else {
            $stmtInit = $conn->prepare("INSERT INTO rooms (judul) VALUES ('Obrolan Baru')");
            if ($stmtInit) {
                $stmtInit->execute();
                $stmtInit->close();
            }
        }
    }
    header("Location: index.php");
    exit;
}

// --- Room Aktif (default: room paling atas/terbaru milik pengguna ini) ---
$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : $rooms[0]['id'];

// Pastikan room_id valid dan milik pengguna saat ini
$current_room = null;
foreach ($rooms as $r) {
    if ($r['id'] == $room_id) {
        $current_room = $r;
        break;
    }
}
if (!$current_room) {
    $current_room = $rooms[0];
    $room_id = $current_room['id'];
}

// --- Ambil Riwayat Chat Sesuai Room Aktif Menggunakan Prepared Statement ---
$chat = [];
$stmt = $conn->prepare("SELECT sender, text FROM chat WHERE room_id = ? ORDER BY id ASC");
if ($stmt) {
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chat[] = $row;
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($current_room['judul']) ?> - Dipta AI</title>
    <link rel="icon" type="image/png" href="asset/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Stylesheet Utama dengan Cache Buster -->
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    
    <!-- Markdown Parser, DOMPurify Sanitizer & Code Syntax Highlighting -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.9/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
</head>
<body>

<div class="app-container">
    <!-- Backdrop Overlay untuk Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Kiri (Gaya Gemini & Claude) -->
    <aside class="sidebar" id="sidebar">
        <div>
            <!-- Header Sidebar -->
            <div class="sidebar-header">
                <a href="index.php" class="brand">
                    <img src="asset/logo.png" alt="Dipta" class="brand-logo" width="32" height="32" style="width:32px;height:32px;object-fit:contain;">
                    <span class="brand-name">Dipta</span>
                </a>
                <button class="btn-icon" id="toggleSidebarBtn" title="Tutup / Buka Sidebar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                        <path d="M9 3v18"/>
                    </svg>
                </button>
            </div>

            <!-- Tombol + Obrolan Baru -->
            <div class="sidebar-actions">
                <a href="?new=1" class="btn-new-chat">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14"/>
                        <path d="M12 5v14"/>
                    </svg>
                    <span>Obrolan baru</span>
                </a>
            </div>

            <!-- Daftar Riwayat Obrolan -->
            <div class="sidebar-content">
                <div class="history-label">Terbaru</div>
                <ul class="chat-list" id="chatRoomList">
                    <?php foreach ($rooms as $room): ?>
                        <li class="chat-item<?= ($room['id'] == $room_id) ? ' active' : '' ?>">
                            <a href="?room_id=<?= $room['id'] ?>" class="chat-item-link" title="<?= htmlspecialchars($room['judul']) ?>">
                                <svg class="chat-item-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                </svg>
                                <span class="chat-item-title"><?= htmlspecialchars($room['judul']) ?></span>
                            </a>
                            <div class="chat-item-actions">
                                <button 
                                    type="button"
                                    class="btn-action-room btn-rename-room" 
                                    data-room-id="<?= $room['id'] ?>" 
                                    data-room-title="<?= htmlspecialchars($room['judul'], ENT_QUOTES, 'UTF-8') ?>" 
                                    title="Ubah Nama"
                                >
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                                    </svg>
                                </button>
                                <button 
                                    type="button"
                                    class="btn-action-room btn-delete-room" 
                                    data-room-id="<?= $room['id'] ?>" 
                                    data-room-title="<?= htmlspecialchars($room['judul'], ENT_QUOTES, 'UTF-8') ?>" 
                                    title="Hapus Obrolan"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18"/>
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                    </svg>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Profil User di Bawah Sidebar (Gaya ChatGPT & Claude) -->
        <div class="sidebar-footer">
            <?php if ($currentUser): ?>
                <div class="user-profile">
                    <div class="user-avatar"><?= strtoupper(mb_substr($currentUser['nama'], 0, 1)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($currentUser['nama']) ?></div>
                        <div class="user-badge">
                            <span class="status-dot"></span>
                            <span>Member</span>
                            <a href="index.php?action=logout" class="btn-logout" id="btnLogout" title="Keluar">Keluar</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="user-profile">
                    <div class="user-avatar" style="background: linear-gradient(135deg, #64748b, #475569);">T</div>
                    <div class="user-info">
                        <div class="user-name">Mode Tamu</div>
                        <div class="user-badge">
                            <button type="button" class="btn-guest-login trigger-open-auth">Masuk / Daftar</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Area Utama Aplikasi -->
    <main class="main-wrapper">
        <!-- Top Bar Navigasi -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="btn-icon" id="openSidebarBtn" title="Buka Sidebar">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" x2="20" y1="12" y2="12"/>
                        <line x1="4" x2="20" y1="6" y2="6"/>
                        <line x1="4" x2="20" y1="18" y2="18"/>
                    </svg>
                </button>
                <div class="topbar-title" id="activeRoomTitle">
                    <?= htmlspecialchars($current_room['judul']) ?>
                </div>
            </div>
            <div class="topbar-right">
                <select id="modelSelector" class="model-select" title="Pilih Model AI">
                    <option value="openai/gpt-oss-120b">Dipta 120B (Flagship)</option>
                    <option value="openai/gpt-oss-20b">Dipta 20B (Instant)</option>
                    <option value="qwen/qwen3.8-27b">Qwen 27B (Multilingual)</option>
                </select>
            </div>
        </header>

        <!-- Container Percakapan (Scroll Area) -->
        <section class="chat-viewport" id="chatViewport">
            <div class="chat-content-container" id="chatContainer">
                
                <!-- Hero / Empty State saat obrolan masih kosong -->
                <div class="hero-state" id="heroState" style="<?= empty($chat) ? 'display:flex;' : 'display:none;' ?>">
                    <div class="hero-icon-container">
                        <img src="asset/logo.png" alt="Dipta AI" width="44" height="44" style="width:44px;height:44px;object-fit:contain;">
                    </div>
                    <h1 class="hero-title">Ada yang bisa Dipta bantu hari ini?</h1>
                    <p class="hero-subtitle">Mulai percakapan cerdas atau pilih salah satu inspirasi pertanyaan di bawah ini.</p>
                    
                    <!-- Suggestion Chips Grid (Gaya Claude & Gemini) -->
                    <div class="suggestion-grid">
                        <div class="suggestion-card" data-prompt="Bantu saya membuat ide konten dan artikel yang menarik tentang perkembangan AI tahun ini.">
                            <div class="suggestion-header">
                                <span>💡 Tulis & Buat Konten</span>
                            </div>
                            <div class="suggestion-desc">Buat draf artikel, postingan media sosial, atau ide kreatif</div>
                        </div>
                        <div class="suggestion-card" data-prompt="Jelaskan cara membuat REST API sederhana menggunakan PHP dan MySQL beserta contoh kodenya.">
                            <div class="suggestion-header">
                                <span>💻 Bantuan Pemrograman</span>
                            </div>
                            <div class="suggestion-desc">Tulis kode PHP, JavaScript, Python, atau perbaiki bug program</div>
                        </div>
                        <div class="suggestion-card" data-prompt="Jelaskan konsep machine learning dan neural networks dengan analogi yang mudah dipahami pemula.">
                            <div class="suggestion-header">
                                <span>📚 Pelajari Konsep Baru</span>
                            </div>
                            <div class="suggestion-desc">Penjelasan konsep ilmiah atau teknis dengan bahasa santai</div>
                        </div>
                        <div class="suggestion-card" data-prompt="Berikan strategi dan langkah-langkah efektif untuk manajemen waktu bagi mahasiswa dan programmer.">
                            <div class="suggestion-header">
                                <span>⚡ Produktivitas & Solusi</span>
                            </div>
                            <div class="suggestion-desc">Rencana kerja terstruktur, tips fokus, dan pemecahan masalah</div>
                        </div>
                    </div>
                </div>

                <!-- Pesan-pesan yang sudah ada dari database -->
                <?php foreach ($chat as $msg): ?>
                    <div class="message-row <?= $msg['sender'] ?>">
                        <?php if ($msg['sender'] === 'bot'): ?>
                            <div class="bot-avatar">
                                <img src="asset/logo.png" alt="Dipta">
                            </div>
                            <div class="message-bubble bot-content">
                                <div class="raw-markdown" style="display:none;"><?= htmlspecialchars($msg['text']) ?></div>
                                <div class="rendered-markdown"></div>
                            </div>
                        <?php else: ?>
                            <div class="message-bubble"><?= nl2br(htmlspecialchars(trim($msg['text']))) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            </div>
        </section>

        <!-- Floating Input Bar Melayang di Bawah (Gaya ChatGPT & Claude) -->
        <div class="input-dock-container">
            <div class="input-dock">
                <form id="chatForm">
                    <input type="hidden" name="room_id" id="roomIdInput" value="<?= $room_id ?>">
                    <input type="hidden" name="csrf_token" id="csrfTokenInput" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="input-dock-main">
                        <textarea 
                            name="pesan" 
                            id="promptInput" 
                            class="prompt-textarea" 
                            rows="1" 
                            placeholder="Kirim pesan ke Dipta..." 
                            required
                        ></textarea>
                        <button type="submit" id="btnSend" class="btn-send" title="Kirim Pesan" disabled>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="19" x2="12" y2="5"/>
                                <polyline points="5 12 12 5 19 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="input-dock-footer">
                        <div class="dock-model-info">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L14.4 7.6L20 10L14.4 12.4L12 18L9.6 12.4L4 10L9.6 7.6L12 2Z"/>
                            </svg>
                            <span>Groq Fast Inference · Dipta 120B</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="disclaimer-text">
                Dipta dapat membuat kesalahan. Harap verifikasi informasi penting secara mandiri.
            </div>
        </div>
    </main>
</div>

<!-- Modal Konfirmasi Hapus Obrolan (Tengah Layar) -->
<div class="modal-overlay" id="deleteModalOverlay">
    <div class="modal-dialog">
        <div class="modal-icon-danger">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"/>
                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                <line x1="10" x2="10" y1="11" y2="17"/>
                <line x1="14" x2="14" y1="11" y2="17"/>
            </svg>
        </div>
        <h3 class="modal-title">Hapus Obrolan?</h3>
        <p class="modal-desc" id="deleteModalDesc">
            Tindakan ini akan menghapus obrolan ini beserta seluruh riwayat pesannya secara permanen. Tindakan tidak dapat dibatalkan.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" id="btnCancelDelete">Batal</button>
            <button type="button" class="btn-modal-confirm" id="btnConfirmDelete">Hapus</button>
        </div>
    </div>
</div>

<!-- Modal Ubah Nama Obrolan -->
<div class="modal-overlay" id="renameModalOverlay">
    <div class="modal-dialog">
        <h3 class="modal-title" style="margin-bottom:12px;">Ubah Nama Obrolan</h3>
        <div class="auth-input-group">
            <input type="text" id="renameInput" class="auth-input" maxlength="100" placeholder="Masukkan judul baru...">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" id="btnCancelRename">Batal</button>
            <button type="button" class="btn-modal-confirm" id="btnConfirmRename" style="background:#6366f1;border-color:#4f46e5;box-shadow:0 4px 14px rgba(99,102,241,0.3);">Simpan</button>
        </div>
    </div>
</div>

<!-- Modal Autentikasi Pengguna (Login & Register) -->
<div class="modal-overlay" id="authModalOverlay">
    <div class="modal-dialog auth-modal-dialog">
        <!-- Tombol Tutup X di Sudut Kanan Atas -->
        <button type="button" class="btn-modal-close" id="btnCloseAuthModal" title="Tutup Modal" aria-label="Tutup">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>

        <!-- Brand Icon & Header -->
        <div class="auth-header">
            <div class="auth-logo-badge">
                <img src="asset/logo.png" alt="Dipta AI" width="34" height="34" style="object-fit:contain;">
            </div>
            <h3 class="auth-title" id="authModalTitle">Selamat Datang</h3>
            <p class="auth-subtitle" id="authModalSubtitle">Masuk untuk melanjutkan riwayat obrolan Anda</p>
        </div>

        <!-- Segmented Tab Switcher (Modern Pill Style) -->
        <div class="auth-pill-tabs" role="tablist">
            <button type="button" class="auth-pill-btn active" id="tabLoginBtn" role="tab" aria-selected="true">Masuk</button>
            <button type="button" class="auth-pill-btn" id="tabRegisterBtn" role="tab" aria-selected="false">Daftar</button>
        </div>

        <!-- Alert Notification Card (Success / Error) -->
        <div class="auth-alert" id="authAlert" style="display:none;">
            <div class="auth-alert-icon" id="authAlertIcon"></div>
            <div class="auth-alert-text" id="authAlertText"></div>
        </div>

        <!-- Form Autentikasi -->
        <form id="authForm" novalidate autocomplete="on">
            <!-- Nama (Hanya Register) -->
            <div class="auth-field" id="authNameGroup" style="display:none;">
                <label for="authNama" class="auth-field-label">Nama Lengkap</label>
                <div class="auth-input-wrapper">
                    <span class="auth-input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <input type="text" name="nama" id="authNama" class="auth-input-modern" placeholder="Nama Anda" autocomplete="name">
                </div>
            </div>

            <!-- Email -->
            <div class="auth-field">
                <label for="authEmail" class="auth-field-label">Alamat Email</label>
                <div class="auth-input-wrapper">
                    <span class="auth-input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                    </span>
                    <input type="email" name="email" id="authEmail" class="auth-input-modern" placeholder="nama@email.com" autocomplete="email" required>
                </div>
            </div>

            <!-- Kata Sandi -->
            <div class="auth-field">
                <div class="auth-field-header">
                    <label for="authPassword" class="auth-field-label">Kata Sandi</label>
                </div>
                <div class="auth-input-wrapper">
                    <span class="auth-input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </span>
                    <input type="password" name="password" id="authPassword" class="auth-input-modern has-toggle" placeholder="Minimal 8 karakter..." autocomplete="current-password" required>
                    <button type="button" class="btn-toggle-password" id="btnTogglePassword" title="Tampilkan kata sandi" aria-label="Tampilkan kata sandi">
                        <!-- Icon Eye (Sandi Tersembunyi) -->
                        <svg class="icon-eye" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <!-- Icon Eye-Off (Sandi Terlihat) -->
                        <svg class="icon-eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>

                <!-- Indikator & Syarat Kekuatan Password (Aktif saat Register) -->
                <div class="pw-strength-box" id="pwStrengthBox" style="display:none;">
                    <div class="strength-bar-track">
                        <div class="strength-bar-fill" id="strengthBarFill"></div>
                    </div>
                    <div class="strength-status-row">
                        <span class="strength-status-text">Kekuatan: <b id="strengthStatusLabel">Sangat Lemah</b></span>
                    </div>
                    <div class="pw-req-list">
                        <div class="pw-req-item" id="reqLen">
                            <span class="pw-req-badge">✕</span> Minimal 8 karakter
                        </div>
                        <div class="pw-req-item" id="reqUpper">
                            <span class="pw-req-badge">✕</span> Huruf kapital (A-Z)
                        </div>
                        <div class="pw-req-item" id="reqLower">
                            <span class="pw-req-badge">✕</span> Huruf kecil (a-z)
                        </div>
                        <div class="pw-req-item" id="reqNum">
                            <span class="pw-req-badge">✕</span> Angka (0-9)
                        </div>
                        <div class="pw-req-item" id="reqSym">
                            <span class="pw-req-badge">✕</span> Simbol khusus (!@#$)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tombol Submit -->
            <button type="submit" class="btn-auth-submit-modern" id="btnAuthSubmit">
                <span id="btnAuthText">Masuk ke Akun</span>
                <span id="btnAuthSpinner" style="display:none;" class="spinner-inline">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="spin-icon">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
                    </svg>
                </span>
            </button>
        </form>

        <!-- Footer Switcher Link -->
        <div class="auth-footer-prompt">
            <span id="authFooterText">Belum punya akun?</span>
            <button type="button" class="auth-footer-btn" id="authFooterBtn">Daftar sekarang</button>
        </div>
    </div>
</div>

<!-- Logika Aplikasi Terpusat & Caching-Friendly (Prinsip Ponytail) -->
<script src="asset/app.js?v=<?= filemtime(__DIR__ . '/asset/app.js') ?>"></script>
</body>
</html>
