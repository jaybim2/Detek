<?php
// test_helper.php
require_once __DIR__ . '/helpers.php';

$testCases = [
    // [input, expected_normalized, expected_valid]
    ['081234567890', '+6281234567890', true],       // Format lokal Indonesia biasa
    ['+6281234567890', '+6281234567890', true],      // Format internasional Indonesia dengan '+'
    ['6281234567890', '+6281234567890', true],       // Format internasional Indonesia tanpa '+'
    ['+258841234567', '+258841234567', true],        // Format internasional Mozambik (+258)
    ['258841234567', '+258841234567', true],         // Format internasional Mozambik tanpa '+'
    ['081-2345-6789', '+628123456789', true],        // Input dengan tanda hubung
    [' +62 812 3456 7890 ', '+6281234567890', true], // Input dengan spasi dan '+'
    ['081', '+6281', false],                         // Nomor terlalu pendek setelah normalisasi
    ['12345', '+12345', false],                       // Nomor terlalu pendek dan tidak diawali kode negara valid
    ['0081234567890', '+0081234567890', false],      // Diawali dengan '00' (tidak valid setelah normalisasi karena diawali 0)
    ['abcdef', '', false],                           // Karakter non-digit saja
];

$passed = 0;
$failed = 0;

echo "Menjalankan Pengujian Logika Normalisasi dan Validasi...\n";
echo "--------------------------------------------------------\n";

foreach ($testCases as $index => $case) {
    $input = $case[0];
    $expectedNorm = $case[1];
    $expectedValid = $case[2];
    
    $normalized = normalizePhone($input);
    $valid = isValidPhone($normalized);
    
    $normCheck = ($normalized === $expectedNorm);
    $validCheck = ($valid === $expectedValid);
    
    if ($normCheck && $validCheck) {
        echo "✓ Test Case #" . ($index + 1) . " PASSED (Input: '$input' -> Norm: '$normalized', Valid: " . ($valid ? 'true' : 'false') . ")\n";
        $passed++;
    } else {
        echo "✗ Test Case #" . ($index + 1) . " FAILED!\n";
        echo "  Input:       '$input'\n";
        echo "  Expected:    Norm: '$expectedNorm', Valid: " . ($expectedValid ? 'true' : 'false') . "\n";
        echo "  Got:         Norm: '$normalized', Valid: " . ($valid ? 'true' : 'false') . "\n";
        $failed++;
    }
}

echo "--------------------------------------------------------\n";
echo "Hasil Pengujian: $passed PASSED, $failed FAILED.\n";

exit($failed > 0 ? 1 : 0);
