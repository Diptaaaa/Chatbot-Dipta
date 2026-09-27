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

// --- Buat Obrolan Baru (Shortcut ?new=1 atau POST) ---
if (isset($_GET['new']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_room')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals(get_expected_csrf_token(), $token)) {
            http_response_code(403);
            die("Token keamanan tidak valid.");
        }
    }
    $default_title = "Obrolan Baru";
    $stmt = $conn->prepare("INSERT INTO rooms (judul) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param("s", $default_title);
        $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF'] . "?room_id=" . $new_id);
        exit;
    }
}

// --- Tambah Room Manual via Form ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['judul_room']) && trim($_POST['judul_room']) !== '') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(get_expected_csrf_token(), $token)) {
        http_response_code(403);
        die("Token keamanan tidak valid.");
    }
    $judul = trim($_POST['judul_room']);
    $stmt = $conn->prepare("INSERT INTO rooms (judul) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param("s", $judul);
        $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF'] . "?room_id=" . $new_id);
        exit;
    }
}

// --- Hapus Room via POST dengan Proteksi CSRF (Aman dari CSRF via GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_room') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(get_expected_csrf_token(), $token)) {
        http_response_code(403);
        die("Token keamanan CSRF tidak valid.");
    }
    $hapus_id = intval($_POST['hapus_id'] ?? 0);
    if ($hapus_id > 0) {
        // Otomatis menghapus chat di dalamnya karena relasi FOREIGN KEY ON DELETE CASCADE
        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $hapus_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Ambil Daftar Room (Urutkan dari yang terbaru) ---
$rooms = [];
$res = $conn->query("SELECT * FROM rooms ORDER BY id DESC");
while ($row = $res->fetch_assoc()) {
    $rooms[] = $row;
}

// Jika belum ada room sama sekali di database, buat room default
if (empty($rooms)) {
    $conn->query("INSERT INTO rooms (judul) VALUES ('Obrolan Baru')");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Room Aktif (default: room paling atas/terbaru) ---
$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : $rooms[0]['id'];

// Pastikan room_id valid
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
$stmt->bind_param("i", $room_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $chat[] = $row;
}
$stmt->close();
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
                            <button 
                                type="button"
                                class="btn-delete-room" 
                                data-room-id="<?= $room['id'] ?>" 
                                data-room-title="<?= htmlspecialchars($room['judul'], ENT_QUOTES, 'UTF-8') ?>" 
                                title="Hapus Obrolan"
                            >
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 6h18"/>
                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                </svg>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Profil User di Bawah Sidebar (Gaya ChatGPT & Claude) -->
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">D</div>
                <div class="user-info">
                    <div class="user-name">Muhammad Rafli Pradipta</div>
                    <div class="user-badge">
                        <span class="status-dot"></span>
                        <span>Dipta Pro</span>
                    </div>
                </div>
            </div>
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
                <div class="model-pill" title="Model AI Aktif">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L14.4 7.6L20 10L14.4 12.4L12 18L9.6 12.4L4 10L9.6 7.6L12 2Z"/>
                    </svg>
                    <span>Dipta 120B</span>
                </div>
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
                        <?php endif; ?>
                        <div class="message-bubble <?= $msg['sender'] === 'bot' ? 'bot-content' : '' ?>">
                            <?php if ($msg['sender'] === 'user'): ?>
                                <?= nl2br(htmlspecialchars($msg['text'])) ?>
                            <?php else: ?>
                                <div class="raw-markdown" style="display:none;"><?= htmlspecialchars($msg['text']) ?></div>
                                <div class="rendered-markdown"></div>
                            <?php endif; ?>
                        </div>
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

<script>
const chatForm = document.getElementById('chatForm');
const promptInput = document.getElementById('promptInput');
const btnSend = document.getElementById('btnSend');
const chatViewport = document.getElementById('chatViewport');
const chatContainer = document.getElementById('chatContainer');
const heroState = document.getElementById('heroState');
const sidebar = document.getElementById('sidebar');
const toggleSidebarBtn = document.getElementById('toggleSidebarBtn');
const openSidebarBtn = document.getElementById('openSidebarBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

// Konfigurasi Marked.js untuk Markdown & Highlighting
marked.setOptions({
    highlight: function(code, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(code, { language: lang }).value;
            } catch (__) {}
        }
        return hljs.highlightAuto(code).value;
    },
    breaks: true,
    gfm: true
});

// Fungsi untuk membungkus blok kode dengan header dan tombol "Salin"
function formatCodeBlocks(container) {
    const preElements = container.querySelectorAll('pre');
    preElements.forEach(pre => {
        if (pre.parentElement.classList.contains('code-wrapper')) return;

        const code = pre.querySelector('code');
        let lang = 'code';
        if (code) {
            const classes = code.className.split(' ');
            for (let c of classes) {
                if (c.startsWith('language-')) {
                    lang = c.replace('language-', '');
                    break;
                }
            }
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'code-wrapper';

        const header = document.createElement('div');
        header.className = 'code-header';
        header.innerHTML = `
            <span>${lang}</span>
            <button class="btn-copy" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                </svg>
                <span>Salin</span>
            </button>
        `;

        const copyBtn = header.querySelector('.btn-copy');
        copyBtn.addEventListener('click', () => {
            const textToCopy = code ? code.innerText : pre.innerText;
            navigator.clipboard.writeText(textToCopy).then(() => {
                const span = copyBtn.querySelector('span');
                span.textContent = 'Tersalin!';
                copyBtn.style.color = '#10b981';
                setTimeout(() => {
                    span.textContent = 'Salin';
                    copyBtn.style.color = '';
                }, 2000);
            });
        });

        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(header);
        wrapper.appendChild(pre);
    });
}

// Render Markdown awal dari riwayat yang sudah ada dengan sanitasi DOMPurify (CWE-79 Defense)
document.querySelectorAll('.raw-markdown').forEach(rawElem => {
    const renderedElem = rawElem.nextElementSibling;
    if (renderedElem) {
        renderedElem.innerHTML = DOMPurify.sanitize(marked.parse(rawElem.textContent));
        formatCodeBlocks(renderedElem);
    }
});

// Auto scroll ke bawah
function scrollToBottom() {
    chatViewport.scrollTop = chatViewport.scrollHeight;
}
scrollToBottom();

// Auto-expand textarea saat mengetik
promptInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 180) + 'px';
    if (this.value.trim().length > 0) {
        btnSend.classList.add('active');
        btnSend.removeAttribute('disabled');
    } else {
        btnSend.classList.remove('active');
        btnSend.setAttribute('disabled', 'true');
    }
});

// Handle Enter untuk submit, Shift+Enter untuk newline
promptInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (promptInput.value.trim().length > 0) {
            chatForm.dispatchEvent(new Event('submit'));
        }
    }
});

// Handle Klik Suggestion Chips
document.querySelectorAll('.suggestion-card').forEach(card => {
    card.addEventListener('click', function() {
        const prompt = this.getAttribute('data-prompt');
        promptInput.value = prompt;
        promptInput.dispatchEvent(new Event('input'));
        promptInput.focus();
        chatForm.dispatchEvent(new Event('submit'));
    });
});

// Handle Submit Pesan via AJAX
chatForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const userText = promptInput.value.trim();
    if (!userText) return;

    // Sembunyikan Hero state jika sebelumnya kosong
    if (heroState) {
        heroState.style.display = 'none';
    }

    // 1. Tampilkan Bubble Pesan Pengguna (Optimistic UI)
    const userRow = document.createElement('div');
    userRow.className = 'message-row user';
    const userBubble = document.createElement('div');
    userBubble.className = 'message-bubble';
    userBubble.textContent = userText;
    userRow.appendChild(userBubble);
    chatContainer.appendChild(userRow);

    // Reset input bar
    promptInput.value = '';
    promptInput.style.height = 'auto';
    btnSend.classList.remove('active');
    btnSend.setAttribute('disabled', 'true');

    // 2. Tampilkan Indikator Bot Sedang Mengetik
    const typingId = 'typing-' + Date.now();
    const botRow = document.createElement('div');
    botRow.className = 'message-row bot';
    botRow.id = typingId;
    botRow.innerHTML = `
        <div class="bot-avatar">
            <img src="asset/logo.png" alt="Dipta">
        </div>
        <div class="message-bubble bot-content">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    `;
    chatContainer.appendChild(botRow);
    scrollToBottom();

    // 3. Kirim via Fetch ke chat-ajax.php dengan proteksi CSRF
    const formData = new FormData(chatForm);
    formData.set('pesan', userText);

    fetch('chat-ajax.php', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': "<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
        },
        body: formData
    })
    .then(res => {
        if (!res.ok) {
            return res.json().then(errData => {
                throw new Error(errData.error || ('HTTP Error ' + res.status));
            }).catch(jsonErr => {
                throw new Error(jsonErr.message || ('HTTP Error ' + res.status));
            });
        }
        return res.json();
    })
    .then(data => {
        // Hapus elemen pengetik
        const typingElem = document.getElementById(typingId);
        if (typingElem) typingElem.remove();

        const botReply = data.reply || data.error || 'Maaf, tidak ada respons.';

        // Render bubble bot baru
        const newBotRow = document.createElement('div');
        newBotRow.className = 'message-row bot';
        newBotRow.innerHTML = `
            <div class="bot-avatar">
                <img src="asset/logo.png" alt="Dipta">
            </div>
            <div class="message-bubble bot-content">
                <div class="rendered-markdown"></div>
            </div>
        `;
        chatContainer.appendChild(newBotRow);

        const renderedContainer = newBotRow.querySelector('.rendered-markdown');
        // Sanitasi output Markdown dengan DOMPurify sebelum di-render ke innerHTML
        renderedContainer.innerHTML = DOMPurify.sanitize(marked.parse(botReply));
        formatCodeBlocks(renderedContainer);
        scrollToBottom();

        // Update judul sidebar dan topbar jika server memperbarui judul room
        if (data.new_title) {
            const activeTitleElem = document.getElementById('activeRoomTitle');
            if (activeTitleElem) activeTitleElem.textContent = data.new_title;
            const activeSidebarItem = document.querySelector('.chat-item.active .chat-item-title');
            if (activeSidebarItem) activeSidebarItem.textContent = data.new_title;
        }
    })
    .catch(err => {
        const typingElem = document.getElementById(typingId);
        if (typingElem) typingElem.remove();

        const errRow = document.createElement('div');
        errRow.className = 'message-row bot';
        errRow.innerHTML = `
            <div class="bot-avatar"><img src="asset/logo.png" alt="Dipta"></div>
            <div class="message-bubble bot-content" style="color:#ef4444;">
                ⚠️ Terjadi kendala saat menghubungi server: ${err.message}
            </div>
        `;
        chatContainer.appendChild(errRow);
        scrollToBottom();
    });
});

// Sidebar Toggle di Desktop & Mobile
function toggleSidebar() {
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('open');
        sidebarOverlay.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
    }
}

if (toggleSidebarBtn) toggleSidebarBtn.addEventListener('click', toggleSidebar);
if (openSidebarBtn) openSidebarBtn.addEventListener('click', toggleSidebar);
if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('active');
});

// Modal Kustom Hapus Room di Tengah Layar
let targetDeleteId = null;
const deleteModalOverlay = document.getElementById('deleteModalOverlay');
const deleteModalDesc = document.getElementById('deleteModalDesc');
const btnCancelDelete = document.getElementById('btnCancelDelete');
const btnConfirmDelete = document.getElementById('btnConfirmDelete');

const csrfTokenGlobal = "<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>";

function bukaModalHapus(id, judul) {
    targetDeleteId = id;
    
    // Format teks deskripsi yang aman & elegan
    const safeTitle = judul.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    deleteModalDesc.innerHTML = `Yakin ingin menghapus obrolan <strong>"${safeTitle}"</strong>? Semua riwayat percakapan di dalamnya akan dihapus secara permanen.`;
    
    deleteModalOverlay.classList.add('show');
}

// Pasang event listener untuk tombol hapus pada daftar obrolan
document.querySelectorAll('.btn-delete-room').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        e.preventDefault();
        const id = this.getAttribute('data-room-id');
        const title = this.getAttribute('data-room-title') || 'Obrolan ini';
        bukaModalHapus(id, title);
    });
});

function closeDeleteModal() {
    deleteModalOverlay.classList.remove('show');
    targetDeleteId = null;
}

if (btnCancelDelete) {
    btnCancelDelete.addEventListener('click', closeDeleteModal);
}

if (btnConfirmDelete) {
    btnConfirmDelete.addEventListener('click', function() {
        if (targetDeleteId) {
            // Gunakan metode POST dengan token CSRF (Aman dari eksploitasi CSRF melalui URL GET)
            const postForm = document.createElement('form');
            postForm.method = 'POST';
            postForm.action = 'index.php';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'hapus_room';
            postForm.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'hapus_id';
            idInput.value = targetDeleteId;
            postForm.appendChild(idInput);

            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'csrf_token';
            tokenInput.value = csrfTokenGlobal;
            postForm.appendChild(tokenInput);

            document.body.appendChild(postForm);
            postForm.submit();
        }
    });
}

// Tutup jika mengklik area gelap luar modal
if (deleteModalOverlay) {
    deleteModalOverlay.addEventListener('click', function(e) {
        if (e.target === deleteModalOverlay) {
            closeDeleteModal();
        }
    });
}

// Tutup jika tombol Escape (ESC) ditekan
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && deleteModalOverlay && deleteModalOverlay.classList.contains('show')) {
        closeDeleteModal();
    }
});
</script>
</body>
</html>
