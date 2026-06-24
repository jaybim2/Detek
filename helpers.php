<?php
// helpers.php

/**
 * Menormalkan nomor telepon ke format internasional (dengan tanda plus).
 * Contoh:
 * - 081234567890 -> +6281234567890
 * - +6281234567890 -> +6281234567890
 * - +258841234567 -> +258841234567
 *
 * @param string $phone
 * @return string
 */
function normalizePhone($phone) {
    // Bersihkan spasi di awal dan akhir
    $phone = trim($phone);
    
    // Deteksi tanda '+' di awal
    $startsWithPlus = (strpos($phone, '+') === 0);
    
    // Hapus semua karakter non-angka
    $clean = preg_replace('/[^0-9]/', '', $phone);
    
    if ($clean === '') {
        return '';
    }
    
    if ($startsWithPlus) {
        return '+' . $clean;
    }
    
    // Untuk Indonesia: Jika diawali '08', konversi menjadi '+628'
    if (strpos($clean, '08') === 0) {
        return '+62' . substr($clean, 1);
    }
    
    // Tambahkan tanda '+' di depan untuk kode negara
    return '+' . $clean;
}

/**
 * Memvalidasi apakah nomor telepon hasil normalisasi memiliki format internasional yang valid.
 * Format internasional yang valid:
 * - Diawali tanda plus (+)
 * - Diikuti digit 1-9 (kode negara tidak diawali angka 0)
 * - Panjang total angka berkisar antara 7 hingga 15 digit (standar ITU-T E.164)
 *
 * @param string $phone
 * @return bool
 */
function isValidPhone($phone) {
    return (bool) preg_match('/^\+[1-9][0-9]{6,14}$/', $phone);
}

/**
 * Mengekstrak kode negara dari nomor telepon berformat internasional (misal +261343291300 -> 261).
 *
 * @param string $phone
 * @return string
 */
function extractCountryCode($phone) {
    // Hapus tanda plus jika ada
    $clean = ltrim($phone, '+');
    
    // Daftar kode negara 3 digit umum
    $threeDigitCodes = ['258', '261', '355', '370', '371', '372', '380', '389', '501', '502', '503', '504', '505', '506', '507', '508', '509', '590', '591', '592', '593', '594', '595', '596', '597', '598', '670', '672', '673', '674', '675', '676', '677', '678', '679', '680', '681', '682', '683', '684', '685', '686', '687', '688', '689', '690', '691', '692', '850', '852', '853', '855', '856', '880', '886', '960', '961', '962', '963', '964', '965', '966', '967', '968', '970', '971', '972', '973', '974', '975', '976', '977', '992', '993', '994', '995', '996', '998'];
    
    // Cek 3 digit pertama
    $firstThree = substr($clean, 0, 3);
    if (in_array($firstThree, $threeDigitCodes)) {
        return $firstThree;
    }
    
    // Cek 1 digit pertama (USA/Canada)
    if (substr($clean, 0, 1) === '1') {
        return '1';
    }
    
    // Default ke 2 digit pertama (seperti Indonesia 62, Malaysia 60, dll)
    return substr($clean, 0, 2);
}

/**
 * Melakukan pengecekan HLR (Home Location Register) secara riil.
 *
 * @param string $phone
 * @param string $countryCode
 * @return array
 */
function checkHLRStatus($phone, $countryCode = '') {
    // Jika HLR dinonaktifkan
    if (!defined('USE_HLR_LOOKUP') || !USE_HLR_LOOKUP) {
        return [
            'valid' => false,
            'carrier' => '-',
            'line_type' => '-',
            'otp_status' => 'disabled',
            'error' => 'HLR Lookup Dinonaktifkan'
        ];
    }
    
    // Jika API Key kosong atau belum disesuaikan
    if (!defined('HLR_API_KEY') || HLR_API_KEY === '') {
        return [
            'valid' => false,
            'carrier' => '-',
            'line_type' => '-',
            'otp_status' => 'error',
            'error' => 'API Key HLR Belum Dikonfigurasi'
        ];
    }
    
    $provider = defined('HLR_PROVIDER') ? HLR_PROVIDER : 'numverify';
    
    if ($provider === 'veriphone') {
        // --- PROVIDER VERIPHONE (1000 Free Limit/Bulan) ---
        $url = 'https://api.veriphone.io/v2/verify?phone=' . urlencode($phone) . '&key=' . urlencode(HLR_API_KEY);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) {
            return [
                'valid' => false,
                'carrier' => '-',
                'line_type' => '-',
                'otp_status' => 'error',
                'error' => 'Koneksi ke Veriphone API terputus atau timeout'
            ];
        }
        
        $data = json_decode($response, true);
        
        // Penanganan error respon dari Veriphone API
        if ($data && isset($data['status']) && $data['status'] === 'error') {
            $errorMsg = $data['message'] ?? 'Error API Veriphone';
            return [
                'valid' => false,
                'carrier' => '-',
                'line_type' => '-',
                'otp_status' => 'error',
                'error' => $errorMsg
            ];
        }
        
        if ($data && isset($data['phone_valid'])) {
            $isValid = (bool) $data['phone_valid'];
            $lineType = $data['phone_type'] ?? '';
            $carrier = $data['carrier'] ?? '-';
            if (empty($carrier)) {
                $carrier = '-';
            }
            
            $otpStatus = 'ready'; // default
            if (!$isValid) {
                $otpStatus = 'delay'; // Kartu tidak aktif / hangus / offline
            } elseif ($lineType !== 'mobile') {
                $otpStatus = 'limited'; // Bukan tipe mobile (landline/fixed_line/voip)
            }
            
            return [
                'valid' => $isValid,
                'carrier' => $carrier,
                'line_type' => $lineType,
                'otp_status' => $otpStatus,
                'error' => null
            ];
        }
    } else {
        // --- PROVIDER NUMVERIFY (100 Free Limit/Bulan) ---
        $cleanPhone = ltrim($phone, '+');
        $url = 'http://apilayer.net/api/validate?access_key=' . HLR_API_KEY . '&number=' . urlencode($cleanPhone);
        if ($countryCode !== '') {
            $url .= '&country_code=' . urlencode($countryCode);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) {
            return [
                'valid' => false,
                'carrier' => '-',
                'line_type' => '-',
                'otp_status' => 'error',
                'error' => 'Koneksi ke Numverify API terputus atau timeout'
            ];
        }
        
        $data = json_decode($response, true);
        
        // Penanganan error respon dari Numverify API
        if ($data && isset($data['success']) && $data['success'] === false) {
            $errorMsg = $data['error']['info'] ?? 'Error API Numverify';
            return [
                'valid' => false,
                'carrier' => '-',
                'line_type' => '-',
                'otp_status' => 'error',
                'error' => $errorMsg
            ];
        }
        
        if ($data && isset($data['valid'])) {
            $isValid = (bool) $data['valid'];
            $lineType = $data['line_type'] ?? '';
            $carrier = $data['carrier'] ?? '-';
            if (empty($carrier)) {
                $carrier = '-';
            }
            
            $otpStatus = 'ready'; // default
            if (!$isValid) {
                $otpStatus = 'delay';
            } elseif ($lineType !== 'mobile') {
                $otpStatus = 'limited';
            }
            
            return [
                'valid' => $isValid,
                'carrier' => $carrier,
                'line_type' => $lineType,
                'otp_status' => $otpStatus,
                'error' => null
            ];
        }
    }
    
    return [
        'valid' => false,
        'carrier' => '-',
        'line_type' => '-',
        'otp_status' => 'error',
        'error' => 'Format respons HLR tidak dikenali'
    ];
}
