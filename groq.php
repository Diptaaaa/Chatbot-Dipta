<?php
/**
 * Helper Integrasi Groq Cloud API
 * Menyediakan fungsi komunikasi ke LLM dengan context memory (riwayat pesan)
 */

require_once __DIR__ . '/config.php';

function get_groq_reply($prompt, $history = []) {
    $apiKey = GROQ_API_KEY;
    $url = GROQ_API_URL;
    $model = GROQ_MODEL;

    if (empty($apiKey)) {
        return [
            'success' => false,
            'error' => 'API Key Groq belum dikonfigurasi. Harap isi GROQ_API_KEY pada file .env Anda.'
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: ' . 'Bearer ' . $apiKey
    ];

    // System prompt untuk memandu persona AI Dipta
    $messages = [
        [
            'role' => 'system',
            'content' => 'Anda adalah Dipta, asisten kecerdasan buatan (AI) yang cerdas, ramah, dan profesional. ' .
                         'Jawablah pertanyaan dalam bahasa Indonesia yang baik, lugas, dan terstruktur. ' .
                         'Gunakan format Markdown (seperti **tebal**, daftar list, tabel, atau blok kode dengan penanda bahasa ```php, ```js, ```python dsb) jika diperlukan agar mudah dibaca.'
        ]
    ];

    // Masukkan riwayat percakapan sebelumnya jika ada
    if (!empty($history) && is_array($history)) {
        foreach ($history as $msg) {
            $role = ($msg['sender'] === 'user') ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => $msg['text']
            ];
        }
    }

    // Masukkan prompt user saat ini
    $messages[] = [
        'role' => 'user',
        'content' => $prompt
    ];

    $data = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2048
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 9); // Disesuaikan dengan batas eksekusi serverless Vercel (10 detik)
    // Verifikasi SSL/TLS aktif sesuai standar keamanan CWE-295
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("Groq cURL Error: " . $error_msg);
        return [
            'success' => false,
            'error' => 'Terjadi gangguan koneksi jaringan ke server AI: ' . $error_msg
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $result = json_decode($response, true);

    if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
        $apiError = $result['error']['message'] ?? ('Respons HTTP ' . $httpCode . ' dari server AI.');
        error_log("Groq API Error [HTTP $httpCode]: " . $apiError);
        return [
            'success' => false,
            'error' => 'Kendala memproses permintaan ke AI: ' . htmlspecialchars($apiError, ENT_QUOTES, 'UTF-8')
        ];
    }

    return [
        'success' => true,
        'reply' => $result['choices'][0]['message']['content']
    ];
}
