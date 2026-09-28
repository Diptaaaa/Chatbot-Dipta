/**
 * Dipta AI - Modern Chatbot Application JavaScript
 * Ringan, cepat, modular, tanpa dependensi berlebih (Prinsip Ponytail)
 */

document.addEventListener('DOMContentLoaded', () => {
    // --- Elemen DOM Utama ---
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
    const modelSelector = document.getElementById('modelSelector');

    const csrfToken = document.getElementById('csrfTokenInput')?.value || '';

    // --- Konfigurasi Marked.js ---
    if (window.marked) {
        marked.setOptions({
            highlight: function(code, lang) {
                if (window.hljs) {
                    if (lang && hljs.getLanguage(lang)) {
                        try {
                            return hljs.highlight(code, { language: lang }).value;
                        } catch (__) {}
                    }
                    return hljs.highlightAuto(code).value;
                }
                return code;
            },
            breaks: true,
            gfm: true
        });
    }

    // --- Auto-Scroll Viewport ---
    function scrollToBottom() {
        if (chatViewport) {
            chatViewport.scrollTop = chatViewport.scrollHeight;
        }
    }
    scrollToBottom();

    // --- Format Blok Kode (Copy Button & Header) ---
    function formatCodeBlocks(container) {
        if (!container) return;
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
                <button class="btn-copy" type="button" title="Salin Kode">
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

    // --- Render Markdown Awal dari Riwayat Chat ---
    document.querySelectorAll('.raw-markdown').forEach(rawElem => {
        const renderedElem = rawElem.nextElementSibling;
        if (renderedElem && window.DOMPurify && window.marked) {
            renderedElem.innerHTML = DOMPurify.sanitize(marked.parse(rawElem.textContent));
            formatCodeBlocks(renderedElem);
        }
    });

    // --- Persistensi Model Switcher (localStorage) ---
    if (modelSelector) {
        const savedModel = localStorage.getItem('dipta_selected_model');
        if (savedModel && modelSelector.querySelector(`option[value="${savedModel}"]`)) {
            modelSelector.value = savedModel;
        }
        modelSelector.addEventListener('change', () => {
            localStorage.setItem('dipta_selected_model', modelSelector.value);
        });
    }

    // --- Auto-expand Textarea ---
    if (promptInput) {
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

        promptInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (promptInput.value.trim().length > 0) {
                    chatForm.dispatchEvent(new Event('submit'));
                }
            }
        });
    }

    // --- Suggestion Chips ---
    document.querySelectorAll('.suggestion-card').forEach(card => {
        card.addEventListener('click', function() {
            const prompt = this.getAttribute('data-prompt');
            if (promptInput) {
                promptInput.value = prompt;
                promptInput.dispatchEvent(new Event('input'));
                promptInput.focus();
                chatForm.dispatchEvent(new Event('submit'));
            }
        });
    });

    // --- Fungsi Helper Pasang Aksi Bubble Bot (Regenerate & Copy Text) ---
    function attachBotBubbleActions(bubbleContainer, rawText) {
        if (!bubbleContainer) return;
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'bot-actions';
        actionsDiv.innerHTML = `
            <button type="button" class="btn-bot-action btn-copy-reply" title="Salin Jawaban">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                </svg>
                <span>Salin</span>
            </button>
            <button type="button" class="btn-bot-action btn-regenerate" title="Coba lagi / Buat ulang jawaban">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                </svg>
                <span>Coba lagi</span>
            </button>
        `;

        actionsDiv.querySelector('.btn-copy-reply').addEventListener('click', function() {
            navigator.clipboard.writeText(rawText).then(() => {
                const span = this.querySelector('span');
                span.textContent = 'Tersalin!';
                this.style.color = '#10b981';
                setTimeout(() => {
                    span.textContent = 'Salin';
                    this.style.color = '';
                }, 2000);
            });
        });

        actionsDiv.querySelector('.btn-regenerate').addEventListener('click', function() {
            // Temukan pesan user terakhir
            const userMessages = chatContainer.querySelectorAll('.message-row.user .message-bubble');
            if (userMessages.length > 0) {
                const lastUserText = userMessages[userMessages.length - 1].textContent.trim();
                if (promptInput && lastUserText) {
                    promptInput.value = lastUserText;
                    promptInput.dispatchEvent(new Event('input'));
                    chatForm.dispatchEvent(new Event('submit'));
                }
            }
        });

        bubbleContainer.appendChild(actionsDiv);
    }

    // Pasang tombol aksi pada pesan bot yang sudah ada dari database
    document.querySelectorAll('.message-row.bot .message-bubble.bot-content').forEach(b => {
        const raw = b.querySelector('.raw-markdown')?.textContent || b.innerText;
        attachBotBubbleActions(b, raw);
    });

    // --- Pengiriman Pesan Chat Real-Time (Streaming SSE + Optimistic UI) ---
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const userText = promptInput.value.trim();
            if (!userText) return;

            if (heroState) heroState.style.display = 'none';

            // 1. Render Bubble User
            const userRow = document.createElement('div');
            userRow.className = 'message-row user';
            const userBubble = document.createElement('div');
            userBubble.className = 'message-bubble';
            userBubble.textContent = userText;
            userRow.appendChild(userBubble);
            chatContainer.appendChild(userRow);

            // Reset input form
            promptInput.value = '';
            promptInput.style.height = 'auto';
            btnSend.classList.remove('active');
            btnSend.setAttribute('disabled', 'true');

            // 2. Render Placeholder Bot untuk Streaming
            const botRow = document.createElement('div');
            botRow.className = 'message-row bot';
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
                    <div class="rendered-markdown" style="display:none;"></div>
                </div>
            `;
            chatContainer.appendChild(botRow);
            scrollToBottom();

            const botBubble = botRow.querySelector('.message-bubble.bot-content');
            const typingElem = botRow.querySelector('.typing-indicator');
            const renderedContainer = botRow.querySelector('.rendered-markdown');

            // 3. Siapkan Form Data
            const formData = new FormData(chatForm);
            formData.set('pesan', userText);
            formData.set('stream', '1'); // Aktifkan real-time streaming
            if (modelSelector) {
                formData.set('model', modelSelector.value);
            }

            let accumulatedText = '';
            let isStreamStarted = false;

            fetch('chat-ajax.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(j => { throw new Error(j.error || ('HTTP Error ' + res.status)); })
                                   .catch(err => { throw new Error(err.message || ('HTTP Error ' + res.status)); });
                }

                const contentType = res.headers.get('content-type') || '';
                // Jika respon bukan SSE (misal fallback JSON)
                if (!contentType.includes('text/event-stream')) {
                    return res.json().then(data => {
                        if (typingElem) typingElem.remove();
                        renderedContainer.style.display = 'block';
                        const reply = data.reply || data.error || 'Maaf, tidak ada respons.';
                        renderedContainer.innerHTML = DOMPurify.sanitize(marked.parse(reply));
                        formatCodeBlocks(renderedContainer);
                        attachBotBubbleActions(botBubble, reply);
                        if (data.new_title) updateRoomTitles(data.new_title);
                        scrollToBottom();
                    });
                }

                // Baca Stream Chunk SSE
                const reader = res.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let buffer = '';

                function readStream() {
                    return reader.read().then(({ done, value }) => {
                        if (done) {
                            if (typingElem) typingElem.remove();
                            renderedContainer.style.display = 'block';
                            renderedContainer.innerHTML = DOMPurify.sanitize(marked.parse(accumulatedText));
                            formatCodeBlocks(renderedContainer);
                            attachBotBubbleActions(botBubble, accumulatedText);
                            scrollToBottom();
                            return;
                        }

                        buffer += decoder.decode(value, { stream: true });
                        const lines = buffer.split("\n\n");
                        buffer = lines.pop(); // simpan sisa data

                        for (let eventBlock of lines) {
                            const eventLines = eventBlock.split("\n");
                            let eventName = 'message';
                            let dataStr = '';

                            for (let l of eventLines) {
                                if (l.startsWith('event: ')) {
                                    eventName = l.substring(7).trim();
                                } else if (l.startsWith('data: ')) {
                                    dataStr = l.substring(6).trim();
                                }
                            }

                            if (!dataStr) continue;

                            try {
                                const parsed = JSON.parse(dataStr);
                                if (eventName === 'chunk' && parsed.chunk) {
                                    if (!isStreamStarted) {
                                        isStreamStarted = true;
                                        if (typingElem) typingElem.style.display = 'none';
                                        renderedContainer.style.display = 'block';
                                    }
                                    accumulatedText += parsed.chunk;
                                    renderedContainer.innerHTML = DOMPurify.sanitize(marked.parse(accumulatedText));
                                    scrollToBottom();
                                } else if (eventName === 'title' && parsed.new_title) {
                                    updateRoomTitles(parsed.new_title);
                                } else if (eventName === 'done') {
                                    if (parsed.new_title) updateRoomTitles(parsed.new_title);
                                } else if (eventName === 'error') {
                                    throw new Error(parsed.error || 'Kendala saat memproses jawaban AI.');
                                }
                            } catch (parseErr) {
                                // Lewati chunk tak terbaca
                            }
                        }

                        return readStream();
                    });
                }

                return readStream();
            })
            .catch(err => {
                if (typingElem) typingElem.remove();
                renderedContainer.style.display = 'block';
                renderedContainer.innerHTML = `<span style="color:#ef4444;">⚠️ Terjadi kendala: ${err.message}</span>`;
                scrollToBottom();
            });
        });
    }

    function updateRoomTitles(title) {
        const activeTitleElem = document.getElementById('activeRoomTitle');
        if (activeTitleElem) activeTitleElem.textContent = title;
        const activeSidebarItem = document.querySelector('.chat-item.active .chat-item-title');
        if (activeSidebarItem) activeSidebarItem.textContent = title;
    }

    // --- Sidebar Toggle ---
    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar?.classList.toggle('open');
            sidebarOverlay?.classList.toggle('active');
        } else {
            sidebar?.classList.toggle('collapsed');
        }
    }

    if (toggleSidebarBtn) toggleSidebarBtn.addEventListener('click', toggleSidebar);
    if (openSidebarBtn) openSidebarBtn.addEventListener('click', toggleSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => {
        sidebar?.classList.remove('open');
        sidebarOverlay?.classList.remove('active');
    });

    // --- Modal Hapus Room ---
    let targetDeleteId = null;
    const deleteModalOverlay = document.getElementById('deleteModalOverlay');
    const deleteModalDesc = document.getElementById('deleteModalDesc');
    const btnCancelDelete = document.getElementById('btnCancelDelete');
    const btnConfirmDelete = document.getElementById('btnConfirmDelete');

    function bukaModalHapus(id, judul) {
        targetDeleteId = id;
        const safeTitle = judul.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        if (deleteModalDesc) {
            deleteModalDesc.innerHTML = `Yakin ingin menghapus obrolan <strong>"${safeTitle}"</strong>? Semua riwayat di dalamnya akan dihapus secara permanen.`;
        }
        deleteModalOverlay?.classList.add('show');
    }

    function closeDeleteModal() {
        deleteModalOverlay?.classList.remove('show');
        targetDeleteId = null;
    }

    document.querySelectorAll('.btn-delete-room').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            const id = this.getAttribute('data-room-id');
            const title = this.getAttribute('data-room-title') || 'Obrolan ini';
            bukaModalHapus(id, title);
        });
    });

    if (btnCancelDelete) btnCancelDelete.addEventListener('click', closeDeleteModal);
    if (btnConfirmDelete) {
        btnConfirmDelete.addEventListener('click', () => {
            if (targetDeleteId) {
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
                tokenInput.value = csrfToken;
                postForm.appendChild(tokenInput);

                document.body.appendChild(postForm);
                postForm.submit();
            }
        });
    }

    // --- Modal Rename Judul Room ---
    let targetRenameId = null;
    const renameModalOverlay = document.getElementById('renameModalOverlay');
    const renameInput = document.getElementById('renameInput');
    const btnCancelRename = document.getElementById('btnCancelRename');
    const btnConfirmRename = document.getElementById('btnConfirmRename');

    function bukaModalRename(id, judul) {
        targetRenameId = id;
        if (renameInput) renameInput.value = judul;
        renameModalOverlay?.classList.add('show');
        setTimeout(() => renameInput?.focus(), 100);
    }

    function closeRenameModal() {
        renameModalOverlay?.classList.remove('show');
        targetRenameId = null;
    }

    document.querySelectorAll('.btn-rename-room').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            const id = this.getAttribute('data-room-id');
            const title = this.getAttribute('data-room-title') || '';
            bukaModalRename(id, title);
        });
    });

    if (btnCancelRename) btnCancelRename.addEventListener('click', closeRenameModal);
    if (btnConfirmRename) {
        btnConfirmRename.addEventListener('click', () => {
            const newTitle = renameInput?.value.trim();
            if (!newTitle || !targetRenameId) return;

            const fd = new FormData();
            fd.set('action', 'rename_room');
            fd.set('room_id', targetRenameId);
            fd.set('judul', newTitle);
            fd.set('csrf_token', csrfToken);

            fetch('chat-ajax.php', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const roomItem = document.querySelector(`.btn-rename-room[data-room-id="${targetRenameId}"]`);
                    if (roomItem) {
                        roomItem.setAttribute('data-room-title', newTitle);
                        const titleSpan = roomItem.closest('.chat-item')?.querySelector('.chat-item-title');
                        if (titleSpan) titleSpan.textContent = newTitle;
                    }
                    const activeRoomId = document.getElementById('roomIdInput')?.value;
                    if (activeRoomId == targetRenameId) {
                        const topTitle = document.getElementById('activeRoomTitle');
                        if (topTitle) topTitle.textContent = newTitle;
                    }
                    closeRenameModal();
                } else {
                    alert(data.error || 'Gagal mengubah judul');
                }
            })
            .catch(err => alert('Kendala: ' + err.message));
        });
    }

    // --- Modal Autentikasi Pengguna Modern (Login & Register) ---
    const authModalOverlay = document.getElementById('authModalOverlay');
    const tabLoginBtn = document.getElementById('tabLoginBtn');
    const tabRegisterBtn = document.getElementById('tabRegisterBtn');
    const authNameGroup = document.getElementById('authNameGroup');
    const authNamaInput = document.getElementById('authNama');
    const authEmailInput = document.getElementById('authEmail');
    const authPasswordInput = document.getElementById('authPassword');
    const btnTogglePassword = document.getElementById('btnTogglePassword');
    const authModalTitle = document.getElementById('authModalTitle');
    const authModalSubtitle = document.getElementById('authModalSubtitle');
    const btnAuthSubmit = document.getElementById('btnAuthSubmit');
    const btnAuthText = document.getElementById('btnAuthText');
    const btnAuthSpinner = document.getElementById('btnAuthSpinner');
    const authAlert = document.getElementById('authAlert');
    const authAlertIcon = document.getElementById('authAlertIcon');
    const authAlertText = document.getElementById('authAlertText');
    const btnCloseAuthModal = document.getElementById('btnCloseAuthModal');
    const authFooterText = document.getElementById('authFooterText');
    const authFooterBtn = document.getElementById('authFooterBtn');
    
    // Password Strength Elements
    const pwStrengthBox = document.getElementById('pwStrengthBox');
    const strengthBarFill = document.getElementById('strengthBarFill');
    const strengthStatusLabel = document.getElementById('strengthStatusLabel');
    const reqLen = document.getElementById('reqLen');
    const reqUpper = document.getElementById('reqUpper');
    const reqLower = document.getElementById('reqLower');
    const reqNum = document.getElementById('reqNum');
    const reqSym = document.getElementById('reqSym');

    let currentAuthMode = 'login'; // 'login' | 'register'

    // Tampilkan / Sembunyikan Alert
    function showAuthAlert(type, message) {
        if (!authAlert) return;
        authAlert.style.display = 'flex';
        authAlert.className = 'auth-alert ' + type;
        if (authAlertText) authAlertText.textContent = message;

        if (authAlertIcon) {
            if (type === 'success') {
                authAlertIcon.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                `;
            } else {
                authAlertIcon.innerHTML = `
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                `;
            }
        }
    }

    function hideAuthAlert() {
        if (authAlert) authAlert.style.display = 'none';
    }

    // Toggle Tampilkan / Sembunyikan Password (Show Password)
    if (btnTogglePassword && authPasswordInput) {
        btnTogglePassword.addEventListener('click', (e) => {
            e.preventDefault();
            const isPassword = authPasswordInput.getAttribute('type') === 'password';
            authPasswordInput.setAttribute('type', isPassword ? 'text' : 'password');

            const eyeIcon = btnTogglePassword.querySelector('.icon-eye');
            const eyeOffIcon = btnTogglePassword.querySelector('.icon-eye-off');
            if (eyeIcon && eyeOffIcon) {
                eyeIcon.style.display = isPassword ? 'none' : 'block';
                eyeOffIcon.style.display = isPassword ? 'block' : 'none';
            }
            btnTogglePassword.setAttribute('title', isPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            btnTogglePassword.setAttribute('aria-label', isPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            authPasswordInput.focus();
        });
    }

    // Evaluator Kekuatan Password Real-time
    function evaluatePassword(pwd) {
        const hasLen = pwd.length >= 8;
        const hasUpper = /[A-Z]/.test(pwd);
        const hasLower = /[a-z]/.test(pwd);
        const hasNum = /[0-9]/.test(pwd);
        const hasSym = /[^a-zA-Z0-9]/.test(pwd);

        function updateReq(elem, isValid) {
            if (!elem) return;
            elem.classList.toggle('valid', isValid);
            const badge = elem.querySelector('.pw-req-badge');
            if (badge) badge.textContent = isValid ? '✓' : '✕';
        }

        updateReq(reqLen, hasLen);
        updateReq(reqUpper, hasUpper);
        updateReq(reqLower, hasLower);
        updateReq(reqNum, hasNum);
        updateReq(reqSym, hasSym);

        let score = 0;
        if (hasLen) score++;
        if (hasUpper) score++;
        if (hasLower) score++;
        if (hasNum) score++;
        if (hasSym) score++;

        if (!strengthBarFill || !strengthStatusLabel) return { score, isValid: score === 5 };

        if (pwd.length === 0) {
            strengthBarFill.style.width = '0%';
            strengthBarFill.style.backgroundColor = '#ef4444';
            strengthStatusLabel.textContent = 'Belum diisi';
            strengthStatusLabel.style.color = 'var(--text-muted)';
        } else if (score <= 1) {
            strengthBarFill.style.width = '20%';
            strengthBarFill.style.backgroundColor = '#ef4444';
            strengthStatusLabel.textContent = 'Sangat Lemah';
            strengthStatusLabel.style.color = '#ef4444';
        } else if (score === 2) {
            strengthBarFill.style.width = '40%';
            strengthBarFill.style.backgroundColor = '#f97316';
            strengthStatusLabel.textContent = 'Lemah';
            strengthStatusLabel.style.color = '#f97316';
        } else if (score === 3) {
            strengthBarFill.style.width = '60%';
            strengthBarFill.style.backgroundColor = '#eab308';
            strengthStatusLabel.textContent = 'Cukup';
            strengthStatusLabel.style.color = '#eab308';
        } else if (score === 4) {
            strengthBarFill.style.width = '80%';
            strengthBarFill.style.backgroundColor = '#3b82f6';
            strengthStatusLabel.textContent = 'Kuat';
            strengthStatusLabel.style.color = '#3b82f6';
        } else if (score === 5) {
            strengthBarFill.style.width = '100%';
            strengthBarFill.style.backgroundColor = '#10b981';
            strengthStatusLabel.textContent = 'Sangat Kuat & Aman';
            strengthStatusLabel.style.color = '#10b981';
        }

        return { score, isValid: score === 5 };
    }

    if (authPasswordInput) {
        authPasswordInput.addEventListener('input', function() {
            if (currentAuthMode === 'register') {
                evaluatePassword(this.value);
            }
        });
    }

    // Set Mode Auth (Login vs Register)
    function setAuthMode(mode) {
        currentAuthMode = mode;
        hideAuthAlert();

        if (mode === 'register') {
            tabRegisterBtn?.classList.add('active');
            tabLoginBtn?.classList.remove('active');
            tabRegisterBtn?.setAttribute('aria-selected', 'true');
            tabLoginBtn?.setAttribute('aria-selected', 'false');

            if (authNameGroup) authNameGroup.style.display = 'block';
            if (authModalTitle) authModalTitle.textContent = 'Daftar Akun Dipta';
            if (authModalSubtitle) authModalSubtitle.textContent = 'Simpan riwayat obrolan & akses di semua perangkat';
            if (btnAuthText) btnAuthText.textContent = 'Daftar Sekarang';
            if (authFooterText) authFooterText.textContent = 'Sudah punya akun?';
            if (authFooterBtn) authFooterBtn.textContent = 'Masuk di sini';
            if (pwStrengthBox) pwStrengthBox.style.display = 'block';

            if (authPasswordInput) {
                authPasswordInput.placeholder = 'Minimal 8 karakter unik...';
                evaluatePassword(authPasswordInput.value);
            }
        } else {
            tabLoginBtn?.classList.add('active');
            tabRegisterBtn?.classList.remove('active');
            tabLoginBtn?.setAttribute('aria-selected', 'true');
            tabRegisterBtn?.setAttribute('aria-selected', 'false');

            if (authNameGroup) authNameGroup.style.display = 'none';
            if (authModalTitle) authModalTitle.textContent = 'Selamat Datang';
            if (authModalSubtitle) authModalSubtitle.textContent = 'Masuk untuk melanjutkan riwayat obrolan Anda';
            if (btnAuthText) btnAuthText.textContent = 'Masuk ke Akun';
            if (authFooterText) authFooterText.textContent = 'Belum punya akun?';
            if (authFooterBtn) authFooterBtn.textContent = 'Daftar sekarang';
            if (pwStrengthBox) pwStrengthBox.style.display = 'none';

            if (authPasswordInput) {
                authPasswordInput.placeholder = 'Masukkan kata sandi Anda...';
            }
        }
    }

    if (tabLoginBtn) tabLoginBtn.addEventListener('click', () => setAuthMode('login'));
    if (tabRegisterBtn) tabRegisterBtn.addEventListener('click', () => setAuthMode('register'));
    if (authFooterBtn) {
        authFooterBtn.addEventListener('click', () => {
            setAuthMode(currentAuthMode === 'login' ? 'register' : 'login');
        });
    }

    // Trigger Buka Modal Auth
    document.querySelectorAll('.trigger-open-auth').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            authModalOverlay?.classList.add('show');
            setAuthMode('login');
            setTimeout(() => authEmailInput?.focus(), 100);
        });
    });

    // Tutup Modal Auth
    if (btnCloseAuthModal) {
        btnCloseAuthModal.addEventListener('click', () => {
            authModalOverlay?.classList.remove('show');
        });
    }

    // Form Submit Handler
    const authForm = document.getElementById('authForm');
    if (authForm) {
        authForm.addEventListener('submit', function(e) {
            e.preventDefault();
            hideAuthAlert();

            const email = (authEmailInput?.value || '').trim();
            const password = authPasswordInput?.value || '';

            if (currentAuthMode === 'register') {
                const nama = (authNamaInput?.value || '').trim();
                if (!nama) {
                    showAuthAlert('error', 'Silakan masukkan nama lengkap Anda.');
                    authNamaInput?.focus();
                    return;
                }
                if (!email) {
                    showAuthAlert('error', 'Silakan masukkan alamat email yang valid.');
                    authEmailInput?.focus();
                    return;
                }

                const pwResult = evaluatePassword(password);
                if (!pwResult.isValid) {
                    showAuthAlert('error', 'Kata sandi belum memenuhi kriteria keamanan (minimal 8 karakter, huruf kapital, huruf kecil, angka, dan simbol khusus).');
                    authPasswordInput?.focus();
                    return;
                }
            } else {
                if (!email || !password) {
                    showAuthAlert('error', 'Email dan kata sandi wajib diisi.');
                    return;
                }
            }

            const fd = new FormData(this);
            fd.set('action', currentAuthMode);
            fd.set('csrf_token', csrfToken);

            // Tampilkan status loading spinner
            if (btnAuthSubmit) btnAuthSubmit.setAttribute('disabled', 'true');
            if (btnAuthSpinner) btnAuthSpinner.style.display = 'inline-flex';
            if (btnAuthText) btnAuthText.textContent = 'Memproses...';

            fetch('auth-ajax.php', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: fd
            })
            .then(res => res.json().then(j => ({ ok: res.ok, data: j })))
            .then(({ ok, data }) => {
                if (btnAuthSubmit) btnAuthSubmit.removeAttribute('disabled');
                if (btnAuthSpinner) btnAuthSpinner.style.display = 'none';
                if (btnAuthText) {
                    btnAuthText.textContent = (currentAuthMode === 'register' ? 'Daftar Sekarang' : 'Masuk ke Akun');
                }

                if (ok && data.success) {
                    showAuthAlert('success', data.message + ' Mengalihkan halaman...');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    showAuthAlert('error', data.error || 'Terjadi kesalahan pada server.');
                }
            })
            .catch(err => {
                if (btnAuthSubmit) btnAuthSubmit.removeAttribute('disabled');
                if (btnAuthSpinner) btnAuthSpinner.style.display = 'none';
                if (btnAuthText) {
                    btnAuthText.textContent = (currentAuthMode === 'register' ? 'Daftar Sekarang' : 'Masuk ke Akun');
                }
                showAuthAlert('error', 'Kendala jaringan: ' + err.message);
            });
        });
    }

    // Tombol Logout
    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', (e) => {
            if (!confirm('Yakin ingin keluar dari akun?')) {
                e.preventDefault();
                return;
            }
            // Langsung arahkan ke endpoint pembersihan cookie untuk keandalan maksimal
            window.location.href = 'index.php?action=logout';
        });
    }

    // Tutup Modal jika klik backdrop
    [deleteModalOverlay, renameModalOverlay, authModalOverlay].forEach(overlay => {
        if (overlay) {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    overlay.classList.remove('show');
                }
            });
        }
    });

    // Tutup Modal dengan tombol ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            deleteModalOverlay?.classList.remove('show');
            renameModalOverlay?.classList.remove('show');
            authModalOverlay?.classList.remove('show');
        }
    });
});
