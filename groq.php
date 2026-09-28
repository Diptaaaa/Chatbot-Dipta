<?php
/**
 * Helper Integrasi Groq Cloud API
 * Menyediakan fungsi komunikasi ke LLM dengan context memory (riwayat pesan)
 */

require_once __DIR__ . '/config.php';

// Daftar model resmi yang didukung pada akun Groq
const ALLOWED_GROQ_MODELS = [
    'openai/gpt-oss-120b' => 'Dipta 120B (Flagship)',
    'openai/gpt-oss-20b' => 'Dipta 20B (Instant)',
    'qwen/qwen3.8-27b' => 'Qwen 27B (Multilingual)'
];

function resolve_groq_model($requestedModel = null) {
    if ($requestedModel && array_key_exists($requestedModel, ALLOWED_GROQ_MODELS)) {
        return $requestedModel;
    }
    return defined('GROQ_MODEL') ? GROQ_MODEL : 'openai/gpt-oss-120b';
}

function build_groq_messages($prompt, $history = []) {
    $messages = [
        [
            'role' => 'system',
            'content' => 'Anda adalah Dipta, asisten kecerdasan buatan (AI) yang cerdas, ramah, dan profesional. ' .
                         'Jawablah pertanyaan dalam bahasa Indonesia yang baik, lugas, terstruktur, dan tuntas hingga selesai tanpa terpotong di tengah kalimat. ' .
                         'Gunakan format Markdown yang rapi (seperti **tebal**, daftar list angka/poin, tabel, atau blok kode dengan penanda bahasa ```php, ```js dsb) agar mudah dipahami.'
        ]
    ];

    if (!empty($history) && is_array($history)) {
        foreach ($history as $msg) {
            $role = ($msg['sender'] === 'user') ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => $msg['text']
            ];
        }
    }

    $messages[] = [
        'role' => 'user',
        'content' => $prompt
    ];

    return $messages;
}

function get_groq_reply($prompt, $history = [], $requestedModel = null) {
    $apiKey = GROQ_API_KEY;
    $url = GROQ_API_URL;
    $model = resolve_groq_model($requestedModel);

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

    $data = [
        'model' => $model,
        'messages' => build_groq_messages($prompt, $history),
        'temperature' => 0.7,
        'max_tokens' => 4096
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
        'reply' => $result['choices'][0]['message']['content'],
        'model' => $model
    ];
}

/**
 * Streaming respons kata demi kata via Server-Sent Events (SSE)
 */
function stream_groq_reply($prompt, $history = [], $requestedModel = null, callable $onChunk = null) {
    $apiKey = GROQ_API_KEY;
    $url = GROQ_API_URL;
    $model = resolve_groq_model($requestedModel);

    if (empty($apiKey)) {
        return [
            'success' => false,
            'error' => 'API Key Groq belum dikonfigurasi.'
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: ' . 'Bearer ' . $apiKey
    ];

    $data = [
        'model' => $model,
        'messages' => build_groq_messages($prompt, $history),
        'temperature' => 0.7,
        'max_tokens' => 4096,
        'stream' => true
    ];

    $fullReply = '';
    $rawBuffer = '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$fullReply, &$rawBuffer, $onChunk) {
        $rawBuffer .= $chunk;
        $lines = explode("\n", $rawBuffer);
        $rawBuffer = array_pop($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ':')) continue;
            if (str_starts_with($line, 'data: ')) {
                $payload = trim(substr($line, 6));
                if ($payload === '[DONE]') continue;
                $decoded = json_decode($payload, true);
                if (isset($decoded['choices'][0]['delta']['content'])) {
                    $piece = $decoded['choices'][0]['delta']['content'];
                    $fullReply .= $piece;
                    if ($onChunk) {
                        $onChunk($piece);
                    }
                }
            }
        }
        return strlen($chunk);
    });

    $success = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        return [
            'success' => false,
            'error' => 'Koneksi streaming terputus: ' . $error_msg,
            'reply' => $fullReply
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200 && empty($fullReply)) {
        return [
            'success' => false,
            'error' => "Server AI mengembalikan kode HTTP $httpCode",
            'reply' => ''
        ];
    }

    return [
        'success' => true,
        'reply' => $fullReply,
        'model' => $model
    ];
}
