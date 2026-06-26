<?php
// config.php

// Tentukan apakah menggunakan mock API lokal atau API pihak ketiga sesungguhnya
define('USE_MOCK_API', filter_var(getenv('USE_MOCK_API') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// Endpoint API
// Menggunakan deteksi host otomatis untuk localhost mock API
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$currentHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$currentDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

// Jika dijalankan dari CLI (misal saat testing), deteksi manual
if (php_sapi_name() === 'cli') {
    define('MOCK_API_URL', 'http://localhost:8000/api_mock.php');
} else {
    define('MOCK_API_URL', $protocol . $currentHost . $currentDir . '/api_mock.php');
}

define('REAL_API_URL', 'https://api.fonnte.com/validate');
define('API_KEY', getenv('API_KEY') ?: 'GNXRfKNLRkXZh91u8CgV'); // Masukkan token API Fonnte Anda di sini

// Durasi delay antara setiap request (dalam detik) jika memeriksa lebih dari satu nomor
define('BULK_CHECK_DELAY', (int)(getenv('BULK_CHECK_DELAY') ?: 2));

// Pengaturan HLR Lookup (Pilih provider: 'veriphone' atau 'numverify')
// Veriphone direkomendasikan karena memberikan limit gratis 1.000 kueri/bulan (Numverify hanya 100/bulan)
define('HLR_PROVIDER', getenv('HLR_PROVIDER') ?: 'veriphone'); 
define('USE_HLR_LOOKUP', filter_var(getenv('USE_HLR_LOOKUP') ?: 'true', FILTER_VALIDATE_BOOLEAN)); // Setel ke true untuk mengaktifkan kueri HLR
define('HLR_API_KEY', getenv('HLR_API_KEY') ?: '83FD160DD817450587B1BECCE464C877'); // Masukkan API Access Key Veriphone atau Numverify Anda di sini


// Path file log
define('LOG_FILE_PATH', __DIR__ . '/logs/checker.log');
