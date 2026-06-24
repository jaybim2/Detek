<?php
// api_mock.php
header('Content-Type: application/json');

// Dapatkan nomor dari parameter query 'number'
$number = $_GET['number'] ?? $_POST['number'] ?? '';

// Hapus karakter non-digit untuk pemrosesan lokal
$cleanNumber = preg_replace('/[^0-9]/', '', $number);

if (empty($cleanNumber)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Parameter number tidak ditemukan atau kosong'
    ]);
    exit;
}

// Simulasi logika registrasi:
// Jika digit terakhir adalah angka genap, maka terdaftar (true).
// Jika digit terakhir adalah angka ganjil, maka tidak terdaftar (false).
$lastDigit = (int) substr($cleanNumber, -1);
$isRegistered = ($lastDigit % 2 === 0);

// Tambahkan sedikit delay simulasi pemrosesan (opsional, misal 100-300ms)
usleep(150000); 

$response = [
    'number' => $cleanNumber,
    'registered' => $isRegistered
];

echo json_encode($response);
