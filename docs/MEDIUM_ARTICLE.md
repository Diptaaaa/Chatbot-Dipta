# Dari Skrip Lokal ke Cloud: Bagaimana Saya Membangun Chatbot AI Modern dengan PHP Native, TiDB, dan Vercel

*Pelajaran nyata seputar refactoring keamanan, migrasi database serverless gratis, sampai perburuan bug CSS kecil yang sempat bikin pusing.*

---

Di tengah ramainya ekosistem web modern yang didominasi oleh Next.js, React, Python, atau framework serba canggih lainnya, saya memutuskan untuk mengambil jalan yang sedikit tidak biasa: membangun aplikasi asisten kecerdasan buatan (AI) bernama **Dipta AI** menggunakan pondasi **PHP native** dan JavaScript murni.

Sebagian orang mungkin bertanya, kenapa masih pakai PHP native di tahun sekarang?

Alasannya sederhana. Pertama, saya ingin membuktikan bahwa PHP klasik sama sekali belum ketinggalan zaman jika dipadukan dengan desain antarmuka modern dan arsitektur cloud masa kini. Kedua, pengerjaan proyek dari tingkat dasar (vanilla) memaksa kita memahami secara utuh setiap alur data, mulai dari penanganan sesi, keamanan HTTP, komunikasi API ke model bahasa (LLM), hingga manipulasi Document Object Model (DOM) di sisi peramban.

Namun, perjalanannya jelas tidak semulus membalikkan telapak tangan. Mulai dari audit keamanan yang membongkar banyak celah, tantangan migrasi database lokal ke cloud tanpa biaya langganan, hingga bug tampilan visual yang cukup menggelitik.

Berikut adalah rangkuman perjalanan teknik dan cerita di balik layar pembuatan Dipta AI.

---

## 1. Titik Awal: Antara Laragon dan Kode yang Rentan

Pada mulanya, proyek ini berjalan secara lokal di laptop saya menggunakan Laragon dan database MySQL standar. Antarmuka awalnya masih kaku, fiturnya sederhana, dan yang paling krusial: kodenya memiliki beberapa celah keamanan klasik yang cukup berisiko jika langsung dilepas ke publik.

Sebelum memikirkan deployment atau fitur tambahan, langkah pertama yang saya lakukan adalah menggelar audit kode secara menyeluruh. Dari audit tersebut, ditemukan beberapa titik kritis:

1. **Kredensial Bocor di Kode Sumber**: Kunci API Groq dan kata sandi database sebelumnya tertulis langsung di dalam skrip PHP. Jika kode ini diunggah ke GitHub publik, siapa pun bisa menyalahgunakannya.
2. **Bypass Verifikasi SSL pada cURL**: Pemanggilan API Groq sempat menonaktifkan pengecekan sertifikat SSL (`CURLOPT_SSL_VERIFYPEER = false`). Hal ini membuka celah serangan Man-in-the-Middle.
3. **Ketiadaan Proteksi CSRF**: Tindakan penting seperti menghapus riwayat obrolan (room) atau mengirim pesan belum dilengkapi validasi token CSRF.
4. **Potensi Kerentanan XSS**: Pesan balasan dari bot belum disanitasi secara ketat sebelum ditampilkan ke layar.

Saya langsung merombak struktur kode. Kredensial dipindahkan seluruhnya ke berkas `.env` dan dibuatkan berkas contoh `config.example.php`. Pengecekan sertifikat SSL diaktifkan kembali secara ketat. Di sisi frontend, pustaka DOMPurify dipasang untuk menyaring seluruh keluaran Markdown dari AI sebelum dirender ke HTML.

---

## 2. Arsitektur Cloud Tanpa Biaya: Serverless PHP dan TiDB

Salah satu target utama saya dalam proyek ini adalah membuat Dipta AI bisa diakses publik secara online tanpa perlu mengeluarkan biaya sewa VPS bulanan (zero infrastructure cost).

Pilihan saya jatuh pada kombinasi dua platform gratis yang sangat bertenaga:

* **Vercel**: Untuk hosting aplikasi web PHP menggunakan runtime komunitas `vercel-php`.
* **TiDB Cloud**: Untuk database MySQL Serverless gratis (wilayah Singapura) dengan kuota penyimpanan yang sangat lega untuk proyek personal.

### Menjinakkan Sesi di Lingkungan Serverless

Lingkungan komputasi serverless seperti Vercel bersifat *stateless*. Artinya, server yang menangani permintaan pertama belum tentu server yang sama pada permintaan berikutnya. Hal ini menjadi masalah bagi mekanisme `$_SESSION` bawaan PHP yang biasa mengandalkan penyimpanan berkas lokal di server.

Jika kita hanya mengandalkan session PHP biasa untuk token CSRF, pengguna akan sering mendapati galat "Token keamanan tidak valid" saat me-refresh halaman atau mengirim pesan.

Solusinya, saya menerapkan pola **Double-Submit Cookie CSRF**:
1. Server membuat token acak menggunakan `bin2hex(random_bytes(32))`.
2. Token tersebut disimpan ke dalam cookie peramban dengan atribut `SameSite=Lax` dan flag `Secure`.
3. Saat pengguna mengirim pesan melalui AJAX (Fetch API), JavaScript membaca cookie tersebut dan mengirimkannya kembali melalui header HTTP khusus (`X-CSRF-TOKEN`).
4. Server tinggal mencocokkan nilai pada cookie dengan nilai pada header menggunakan fungsi `hash_equals()`. Dengan cara ini, validasi keamanan tetap berjalan kokoh meski di lingkungan tanpa session terpusat.

### Drama PHP 8.5 dan cURL di Vercel

Saat pertama kali dideploy ke Vercel, muncul kendala tak terduga. Halaman antarmuka berhasil terbuka, namun setiap kali pengguna mengirim pesan, muncul pesan galat:

```text
Unexpected token '<', "Deprecated: curl_close()..." is not valid JSON
```

Setelah ditelusuri, runtime Vercel menggunakan versi PHP 8.5 terbaru. Pada versi ini, fungsi `curl_close()` sudah dinyatakan *deprecated* (usang) karena PHP modern secara otomatis menutup resource cURL saat variabel keluar dari ruang lingkupnya.

Pesan peringatan PHP tersebut tercetak ke buffer output HTTP, sehingga merusak struktur respons JSON yang dikirimkan ke JavaScript. Masalah ini segera beres setelah pemanggilan `curl_close()` disesuaikan dan penanganan output diperketat.

---

## 3. Merancang Antarmuka: Gelap, Bersih, dan Interaktif

Saya ingin Dipta AI terasa premium saat pertama kali dibuka, bukan sekadar formulir web biasa. Saya mengadopsi bahasa desain modern yang terinspirasi dari Claude dan ChatGPT:

* **Palet Warna Gelap Elegan**: Menggunakan warna dasar latar belakang gelap (`#0d0d11` dan `#1a1a22`) yang dipadukan dengan aksen ungu-indigo lembut.
* **Optimistic UI**: Ketika tombol kirim ditekan, pesan pengguna langsung muncul seketika di layar tanpa menunggu balasan jaringan, lengkap dengan animasi tiga titik mengetik (*typing indicator*).
* **Sidebar Riwayat Percakapan**: Panel samping yang dapat disembunyikan (collapsible) di desktop dan berubah menjadi laci sentuh (drawer) di layar ponsel.
* **Input Bar Melayang**: Area pengetikan pesan otomatis menyesuaikan tinggi teks (*auto-resizing textarea*) dan tombol kirim yang berubah aktif saat ada karakter yang diketik.
* **Modal Konfirmasi Custom**: Tombol hapus obrolan tidak lagi menggunakan `confirm()` bawaan browser yang kaku, melainkan dialog modal berbasis efek kaca (*glassmorphism*) yang menyatu dengan tema.

---

## 4. Perburuan Bug Visual: Misteri Bubble Chat Raksasa

Ketika aplikasi sudah berjalan lancar di cloud, sebuah masalah visual yang unik muncul saat saya mencoba mengajukan pertanyaan panjang seputar tips pengasuhan anak.

Tampilannya terlihat janggal di dua titik:
1. Jawaban dari AI tiba-tiba terhenti di tengah kalimat pada poin ketujuh.
2. Ketika saya mengetikkan kata pendek *"Lanjutkan"*, kotak pesan pengguna muncul sebagai balok raksasa yang sangat lebar dan tinggi, dengan kata "Lanjutkan" yang mengambang canggung di tengah ruang kosong.

Kenapa hal itu bisa terjadi?

### Penyebab 1: Indentasi Template PHP dan `white-space: pre-wrap`

Di dalam berkas CSS, saya memasang aturan `white-space: pre-wrap;` pada bubble pengguna agar spasi dan baris baru yang diketikkan pengguna tetap dipertahankan.

Namun, di dalam berkas `index.php`, kode awalnya ditulis dengan indentasi rapi seperti ini:

```php
<div class="message-bubble">
    <?php if ($msg['sender'] === 'user'): ?>
        <?= nl2br(htmlspecialchars($msg['text'])) ?>
    <?php endif; ?>
</div>
```

Tanpa disadari, indentasi sekitar 30 spasi dan baris baru di dalam tag `<div>` tersebut ikut dianggap sebagai konten teks oleh browser. Karena ada aturan `pre-wrap`, browser merender seluruh spasi kosong dan baris baru bawaan kode PHP ke layar. Kata sembilan huruf "Lanjutkan" pun akhirnya tampak berenang di dalam kotak raksasa yang kosong.

Solusinya adalah merapatkan penulisan template PHP dan memangkas spasi liar:

```php
<div class="message-bubble"><?= nl2br(htmlspecialchars(trim($msg['text']))) ?></div>
```

### Penyebab 2: Bubble Belum Mengadopsi `fit-content`

Selain masalah spasi, aturan CSS lama belum membatasi lebar bubble pengguna secara dinamis. Saya memperbarui gayanya menjadi bentuk kapsul (*pill*) asimetris modern:

```css
.message-row.user .message-bubble {
    background: linear-gradient(135deg, #2b2b3b 0%, #20202c 100%);
    color: #f3f3f8;
    border-radius: 18px 18px 4px 18px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 10px 18px;
    width: fit-content;
    max-width: min(680px, 78%);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.22);
    white-space: pre-wrap;
    word-break: break-word;
    text-align: left;
    display: inline-block;
}
```

Dengan `width: fit-content;`, bubble hanya akan selebar teks di dalamnya, namun tetap dibatasi maksimal 78 persen layar jika pesannya berupa paragraf panjang.

### Penyebab 3: Jawaban Terpotong Akibat `max_tokens`

Alasan AI berhenti di tengah kalimat adalah parameter `max_tokens` yang sebelumnya dipatok pada angka 2048 dengan timeout koneksi 9 detik. Untuk topik yang membutuhkan penjelasan panjang dan berpoin, kuota token tersebut habis sebelum AI sempat menutup kalimat.

Saya menaikkan batasannya menjadi **4096 tokens**, memperpanjang batas waktu koneksi cURL menjadi 20 detik, dan menyetel konfigurasi Vercel `maxDuration: 30` detik. Hasilnya, AI sekarang mampu memberikan jawaban yang tuntas dan terstruktur tanpa terputus di tengah jalan.

---

## 5. Ringkasan Pembelajaran Penting

Membangun proyek ini dari awal memberikan beberapa catatan berharga bagi saya:

1. **Fundamental Web Itu Abadi**: Framework frontend dan backend boleh silih berganti setiap tahun, namun pemahaman mendalam tentang HTTP headers, cookies, manipulasi DOM, dan CSS box model akan selalu terpakai dalam situasi apa pun.
2. **Perhatikan Efek Samping CSS Text Wrapping**: Properti seperti `white-space: pre-wrap` sangat bermanfaat, namun kita harus ekstra hati-hati terhadap indentasi template di sisi backend agar spasi kode tidak bocor ke antarmuka pengguna.
3. **Ekosistem Serverless Ramah Kantong**: Dengan arsitektur yang tepat, kita bisa membangun aplikasi bertenaga AI yang cepat, aman, dan berpenampilan kelas dunia tanpa perlu mengeluarkan biaya langganan bulanan di awal.

---

## Penutup

Proyek Dipta AI ini membuktikan bahwa PHP native dan vanilla JavaScript, jika dipadukan dengan standar desain modern dan infrastruktur cloud yang tepat, mampu menghasilkan pengalaman pengguna yang tidak kalah mulus dibanding aplikasi berbasis framework besar.

Bagi teman-teman yang ingin mencoba langsung atau melihat kode sumbernya, silakan kunjungi tautan berikut:

* **Live Demo**: [https://chatbot-dipta-smoky.vercel.app/](https://chatbot-dipta-smoky.vercel.app/)
* **Repository GitHub**: [https://github.com/Diptaaaa/Chatbot-Dipta](https://github.com/Diptaaaa/Chatbot-Dipta)

Semoga catatan perjalanan teknik ini bermanfaat bagi teman-teman yang sedang bereksperimen menggabungkan teknologi web klasik dengan ekosistem AI modern. Selamat berkarya dan terus menulis kode yang rapi!
