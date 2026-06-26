<?php
// index.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Deteksi request AJAX untuk kueri batch
$inputData = json_decode(file_get_contents('php://input'), true);
$action = $inputData['action'] ?? $_POST['action'] ?? '';

if ($action === 'check_batch') {
    header('Content-Type: application/json');
    // Mencegah PHP timeout untuk proses batch yang berjalan lama di server
    @set_time_limit(0);
    
    $phones = $inputData['phones'] ?? [];
    $batchResults = [];
    $batchStats = [
        'total' => 0,
        'registered' => 0,
        'unregistered' => 0,
        'failed' => 0,
        'otp_ready' => 0,
        'otp_limited' => 0,
        'otp_delay' => 0,
        'otp_error' => 0
    ];
    
    $checkedCount = 0;
    foreach ($phones as $rawPhone) {
        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') continue;
        
        $normalized = normalizePhone($rawPhone);
        
        if (!isValidPhone($normalized)) {
            $batchResults[] = [
                'raw' => $rawPhone,
                'normalized' => $normalized !== '' ? $normalized : '-',
                'valid' => false,
                'registered' => null,
                'error' => 'Format nomor tidak valid',
                'hlr_carrier' => '-',
                'hlr_line_type' => '-',
                'hlr_otp_status' => 'disabled',
                'hlr_otp_delay_time' => 0,
                'hlr_error' => null
            ];
            $batchStats['total']++;
            $batchStats['failed']++;
            writeLog($rawPhone, $normalized, 'INVALID_FORMAT', 'Format nomor tidak valid');
            continue;
        }
        
        // Jeda delay antar nomor di backend dinonaktifkan untuk pemrosesan real-time di frontend
        
        $apiResult = checkWhatsAppNumber($normalized);
        $checkedCount++;
        
        if ($apiResult && isset($apiResult['registered'])) {
            $isRegistered = (bool) $apiResult['registered'];
            
            $hlrCarrier = '-';
            $hlrLineType = '-';
            $hlrOtpStatus = 'disabled';
            $hlrOtpDelayTime = 0;
            $hlrError = null;
            
            if ($isRegistered) {
                $countryCode = extractCountryCode($normalized);
                $hlrResult = checkHLRStatus($normalized, $countryCode);
                
                $hlrCarrier = $hlrResult['carrier'];
                $hlrLineType = $hlrResult['line_type'];
                $hlrOtpStatus = $hlrResult['otp_status'];
                $hlrOtpDelayTime = $hlrResult['otp_delay_time'] ?? 0;
                $hlrError = $hlrResult['error'];
                
                if ($hlrOtpStatus === 'ready') {
                    $batchStats['otp_ready']++;
                } elseif ($hlrOtpStatus === 'limited') {
                    $batchStats['otp_limited']++;
                } elseif ($hlrOtpStatus === 'delay') {
                    $batchStats['otp_delay']++;
                } elseif ($hlrOtpStatus === 'error') {
                    $batchStats['otp_error']++;
                }
            }
            
            $batchResults[] = [
                'raw' => $rawPhone,
                'normalized' => $normalized,
                'valid' => true,
                'registered' => $isRegistered,
                'error' => null,
                'hlr_carrier' => $hlrCarrier,
                'hlr_line_type' => $hlrLineType,
                'hlr_otp_status' => $hlrOtpStatus,
                'hlr_otp_delay_time' => $hlrOtpDelayTime,
                'hlr_error' => $hlrError
            ];
            
            $batchStats['total']++;
            if ($isRegistered) {
                $batchStats['registered']++;
                $logMsg = sprintf("Terdaftar di WhatsApp | Carrier: %s | Line: %s | OTP: %s", $hlrCarrier, $hlrLineType, $hlrOtpStatus);
                if ($hlrError) {
                    $logMsg .= " (HLR Error: " . $hlrError . ")";
                }
                writeLog($rawPhone, $normalized, 'REGISTERED', $logMsg);
            } else {
                $batchStats['unregistered']++;
                writeLog($rawPhone, $normalized, 'UNREGISTERED', 'Tidak terdaftar di WhatsApp');
            }
        } else {
            $errMsg = ($apiResult && isset($apiResult['error'])) ? $apiResult['error'] : 'Gagal menghubungi API WhatsApp';
            $batchResults[] = [
                'raw' => $rawPhone,
                'normalized' => $normalized,
                'valid' => true,
                'registered' => null,
                'error' => $errMsg,
                'hlr_carrier' => '-',
                'hlr_line_type' => '-',
                'hlr_otp_status' => 'disabled',
                'hlr_otp_delay_time' => 0,
                'hlr_error' => null
            ];
            $batchStats['total']++;
            $batchStats['failed']++;
            writeLog($rawPhone, $normalized, 'API_ERROR', $errMsg);
        }
    }
    
    echo json_encode([
        'success' => true,
        'results' => $batchResults,
        'stats' => $batchStats
    ]);
    exit;
}

$rawInput = '';
$results = [];
$stats = [
    'total' => 0,
    'registered' => 0,
    'unregistered' => 0,
    'failed' => 0,
    'otp_ready' => 0,
    'otp_limited' => 0,
    'otp_delay' => 0,
    'otp_error' => 0
];
$hasSearched = false;

// Fungsi untuk mencatat log aktivitas
function writeLog($raw, $normalized, $status, $message) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $time = date('Y-m-d H:i:s');
    $logLine = sprintf("[%s] [%s] Raw: '%s' | Normalized: '%s' | Status: %s | Message: %s\n", $time, $ip, $raw, $normalized, $status, $message);
    
    $dir = dirname(LOG_FILE_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(LOG_FILE_PATH, $logLine, FILE_APPEND);
}

// Fungsi untuk memanggil API validasi nomor WhatsApp
function checkWhatsAppNumber($phone) {
    if (USE_MOCK_API) {
        // Jika mode mock aktif, jalankan logika simulasi secara lokal 
        // untuk menghindari deadlock pada server PHP single-threaded (localhost).
        $cleanNumber = preg_replace('/[^0-9]/', '', $phone);
        $lastDigit = (int) substr($cleanNumber, -1);
        $isRegistered = ($lastDigit % 2 === 0);
        
        // Simulasikan delay network singkat (150ms)
        usleep(150000); 
        
        return [
            'number' => $cleanNumber,
            'registered' => $isRegistered
        ];
    }

    $apiUrl = REAL_API_URL;
    $countryCode = extractCountryCode($phone);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'target' => $phone,
        'countryCode' => $countryCode
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    // Tambahkan token/key jika dikonfigurasi
    if (defined('API_KEY') && API_KEY !== 'YOUR_FONNTE_TOKEN_HERE' && API_KEY !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . API_KEY
        ]);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response) {
        return [
            'number' => $phone,
            'registered' => null,
            'error' => 'Koneksi ke API terputus atau timeout'
        ];
    }
    
    $responseData = json_decode($response, true);
    if ($responseData && isset($responseData['status'])) {
        if ($responseData['status'] === true) {
            // Nomor terdaftar jika ada di dalam array 'registered'
            $isRegistered = false;
            if (!empty($responseData['registered'])) {
                $isRegistered = true;
            }
            return [
                'number' => $phone,
                'registered' => $isRegistered
            ];
        } else {
            // Fonnte mengembalikan status false (misal token invalid atau device disconnect)
            $reason = $responseData['reason'] ?? 'Gagal memproses nomor';
            return [
                'number' => $phone,
                'registered' => null,
                'error' => $reason
            ];
        }
    }
    
    return [
        'number' => $phone,
        'registered' => null,
        'error' => 'Format respons API tidak dikenali (HTTP ' . $httpCode . ')'
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = $_POST['phones'] ?? '';
    
    // Pecah input berdasarkan baris baru, koma, titik koma, atau spasi ganda
    $rawPhones = preg_split('/[\n\r,;]+/', $rawInput);
    $phonesToCheck = [];
    
    foreach ($rawPhones as $phone) {
        $trimmed = trim($phone);
        if ($trimmed !== '') {
            $phonesToCheck[] = $trimmed;
        }
    }
    
    if (!empty($phonesToCheck)) {
        $hasSearched = true;
        $checkedCount = 0;
        
        foreach ($phonesToCheck as $rawPhone) {
            $normalized = normalizePhone($rawPhone);
            
            // Periksa format nomor internasional
            if (!isValidPhone($normalized)) {
                $results[] = [
                    'raw' => $rawPhone,
                    'normalized' => $normalized !== '' ? $normalized : '-',
                    'valid' => false,
                    'registered' => null,
                    'error' => 'Format nomor tidak valid',
                    'hlr_carrier' => '-',
                    'hlr_line_type' => '-',
                    'hlr_otp_status' => 'disabled',
                    'hlr_otp_delay_time' => 0,
                    'hlr_error' => null
                ];
                $stats['total']++;
                $stats['failed']++;
                writeLog($rawPhone, $normalized, 'INVALID_FORMAT', 'Format nomor tidak valid');
                continue;
            }
            
            // Jeda 3 detik di antara panggilan API jika memeriksa lebih dari satu nomor
            if ($checkedCount > 0 && BULK_CHECK_DELAY > 0) {
                sleep(BULK_CHECK_DELAY);
            }
            
            // Panggil API validasi
            $apiResult = checkWhatsAppNumber($normalized);
            $checkedCount++;
            
            if ($apiResult && isset($apiResult['registered'])) {
                $isRegistered = (bool) $apiResult['registered'];
                
                $hlrCarrier = '-';
                $hlrLineType = '-';
                $hlrOtpStatus = 'disabled';
                $hlrOtpDelayTime = 0;
                $hlrError = null;
                
                if ($isRegistered) {
                    // Panggil HLR Lookup jika WA terdaftar
                    $countryCode = extractCountryCode($normalized);
                    $hlrResult = checkHLRStatus($normalized, $countryCode);
                    
                    $hlrCarrier = $hlrResult['carrier'];
                    $hlrLineType = $hlrResult['line_type'];
                    $hlrOtpStatus = $hlrResult['otp_status'];
                    $hlrOtpDelayTime = $hlrResult['otp_delay_time'] ?? 0;
                    $hlrError = $hlrResult['error'];
                    
                    // Hitung statistik OTP
                    if ($hlrOtpStatus === 'ready') {
                        $stats['otp_ready']++;
                    } elseif ($hlrOtpStatus === 'limited') {
                        $stats['otp_limited']++;
                    } elseif ($hlrOtpStatus === 'delay') {
                        $stats['otp_delay']++;
                    } elseif ($hlrOtpStatus === 'error') {
                        $stats['otp_error']++;
                    }
                }
                
                $results[] = [
                    'raw' => $rawPhone,
                    'normalized' => $normalized,
                    'valid' => true,
                    'registered' => $isRegistered,
                    'error' => null,
                    'hlr_carrier' => $hlrCarrier,
                    'hlr_line_type' => $hlrLineType,
                    'hlr_otp_status' => $hlrOtpStatus,
                    'hlr_otp_delay_time' => $hlrOtpDelayTime,
                    'hlr_error' => $hlrError
                ];
                
                $stats['total']++;
                if ($isRegistered) {
                    $stats['registered']++;
                    $logMsg = sprintf("Terdaftar di WhatsApp | Carrier: %s | Line: %s | OTP: %s", $hlrCarrier, $hlrLineType, $hlrOtpStatus);
                    if ($hlrError) {
                        $logMsg .= " (HLR Error: " . $hlrError . ")";
                    }
                    writeLog($rawPhone, $normalized, 'REGISTERED', $logMsg);
                } else {
                    $stats['unregistered']++;
                    writeLog($rawPhone, $normalized, 'UNREGISTERED', 'Tidak terdaftar di WhatsApp');
                }
            } else {
                $errMsg = ($apiResult && isset($apiResult['error'])) ? $apiResult['error'] : 'Gagal menghubungi API WhatsApp';
                $results[] = [
                    'raw' => $rawPhone,
                    'normalized' => $normalized,
                    'valid' => true,
                    'registered' => null,
                    'error' => $errMsg,
                    'hlr_carrier' => '-',
                    'hlr_line_type' => '-',
                    'hlr_otp_status' => 'disabled',
                    'hlr_otp_delay_time' => 0,
                    'hlr_error' => null
                ];
                $stats['total']++;
                $stats['failed']++;
                writeLog($rawPhone, $normalized, 'API_ERROR', $errMsg);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Number Checker - Deteksi Akun WhatsApp Cepat</title>
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --primary: #0f766e;
            --primary-dark: #115e59;
            --primary-light: #e6fffb;
            --accent: #2563eb;
            --accent-light: #eff6ff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e2e8f0;
            --success: #16a34a;
            --success-bg: #f0fdf4;
            --success-border: #bbf7d0;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #eef4f8 0%, #f8fafc 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        .container {
            width: 100%;
            max-width: 800px;
        }

        .card {
            background: var(--card);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: 0 30px 50px -20px rgba(15, 23, 42, 0.12);
        }

        .hero {
            padding: 40px;
            background: linear-gradient(135deg, rgba(15, 118, 110, 0.08) 0%, rgba(37, 99, 235, 0.05) 100%);
            border-bottom: 1px solid var(--border);
            position: relative;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 99px;
            background: var(--primary-light);
            color: var(--primary-dark);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            margin-bottom: 12px;
        }

        .lead {
            color: var(--muted);
            font-size: 16px;
            line-height: 1.6;
            font-weight: 400;
            max-width: 650px;
        }

        .content {
            padding: 40px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .input-wrapper {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }

        textarea {
            width: 100%;
            height: 120px;
            padding: 16px 20px;
            border: 1px solid var(--border);
            border-radius: 16px;
            font-family: inherit;
            font-size: 16px;
            outline: none;
            background: #fff;
            transition: var(--transition);
            resize: vertical;
            line-height: 1.6;
        }

        textarea:focus {
            border-color: rgba(15, 118, 110, 0.6);
            box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.08);
        }

        .helper {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .helper svg {
            flex-shrink: 0;
            color: var(--primary);
        }

        button {
            padding: 16px 28px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, #14b8a6 100%);
            color: #fff;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 10px 25px -5px rgba(15, 118, 110, 0.25);
            transition: var(--transition);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(15, 118, 110, 0.35);
        }

        button:active {
            transform: translateY(0);
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Stats Panel */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            padding: 12px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-val {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Results section */
        .results-section {
            margin-top: 32px;
            border-top: 2px dashed var(--border);
            padding-top: 32px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .results-table-wrapper {
            width: 100%;
            overflow-x: auto;
            border-radius: 16px;
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 15px;
        }

        th {
            background: #f8fafc;
            padding: 14px 18px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            color: #334155;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-badge.registered {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .status-badge.unregistered {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger-border);
        }

        .status-badge.error {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .status-badge.otp-ready {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .status-badge.otp-limited {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .status-badge.otp-delay {
            background: #fff5f5;
            color: #e53e3e;
            border: 1px solid #fed7d7;
        }

        .status-badge.otp-disabled {
            background: #f8fafc;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }

        .footer-note {
            margin-top: 24px;
            font-size: 13px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
            justify-content: center;
        }

        /* Tabs Nav */
        .tabs-nav {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 24px;
            padding-bottom: 8px;
        }
        .tab-btn {
            background: none;
            border: none;
            box-shadow: none;
            color: var(--muted);
            padding: 10px 16px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px;
            transition: var(--transition);
        }
        .tab-btn:hover {
            color: var(--primary);
            background: rgba(15, 118, 110, 0.05);
            transform: none;
            box-shadow: none;
        }
        .tab-btn.active {
            color: var(--primary);
            background: var(--primary-light);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }

        /* File Options Grid */
        .file-options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            background: #f8fafc;
            border: 1px solid var(--border);
            padding: 20px;
            border-radius: 16px;
            margin-top: 16px;
        }
        .option-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .option-group label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-align: left;
        }
        .option-group input, .option-group select {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            background: #fff;
            transition: var(--transition);
        }
        .option-group input:focus, .option-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.08);
        }

        /* Progress Panel */
        .progress-panel {
            display: none;
            background: linear-gradient(135deg, rgba(15, 118, 110, 0.04) 0%, rgba(37, 99, 235, 0.03) 100%);
            border: 1.5px solid var(--primary);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 28px;
            animation: fadeIn 0.4s ease-out;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .progress-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        .progress-percentage {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
        }
        .progress-bar-container {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--primary) 0%, #14b8a6 100%);
            border-radius: 99px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .progress-status-text {
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 18px;
            font-weight: 500;
            text-align: left;
        }
        .queue-controls {
            display: flex;
            gap: 10px;
        }
        .btn-control {
            padding: 10px 20px;
            font-size: 14px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: none;
            transform: none !important;
            transition: var(--transition);
        }
        .btn-control.pause {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .btn-control.pause:hover {
            background: #fef3c7;
        }
        .btn-control.resume {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        .btn-control.resume:hover {
            background: #dcfce7;
        }
        .btn-control.cancel {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .btn-control.cancel:hover {
            background: #fee2e2;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 640px) {
            body {
                padding: 20px 10px;
            }

            .hero, .content {
                padding: 24px;
            }

            h1 {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .file-options-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <!-- Hero Header -->
            <div class="hero">
                <span class="badge">WhatsApp Number Checker</span>
                <h1>Periksa Status Nomor WhatsApp</h1>
                <p class="lead">
                    Masukkan nomor atau unggah berkas teks untuk memvalidasi status WhatsApp secara real-time. Proses berjalan dalam antrean bertahap agar aman dari batas timeout.
                </p>
            </div>

            <!-- Content Area -->
            <div class="content">
                
                <!-- Panel Progress Antrean AJAX -->
                <div id="progressPanel" class="progress-panel">
                    <div class="progress-header">
                        <span class="progress-title" id="progressTitle">Sedang Mempersiapkan Antrean...</span>
                        <span class="progress-percentage" id="progressPercentage">0%</span>
                    </div>
                    <div class="progress-bar-container">
                        <div id="progressBarFill" class="progress-bar-fill"></div>
                    </div>
                    <div class="progress-status-text" id="progressStatusText">Menunggu pemrosesan batch pertama...</div>
                    <div class="queue-controls">
                        <button type="button" id="btnPause" class="btn-control pause" onclick="pauseQueue()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M5.5 3.5A1.5 1.5 0 0 1 7 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5zm5 0A1.5 1.5 0 0 1 12 5v6a1.5 1.5 0 0 1-3 0V5a1.5 1.5 0 0 1 1.5-1.5z"/>
                            </svg>
                            Jeda (Pause)
                        </button>
                        <button type="button" id="btnResume" class="btn-control resume" onclick="resumeQueue()" style="display: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M11.596 8.697l-6.363 3.692c-.54.313-1.233-.066-1.233-.697V4.308c0-.63.692-1.01 1.233-.696l6.363 3.692a.802.802 0 0 1 0 1.393z"/>
                            </svg>
                            Lanjutkan
                        </button>
                        <button type="button" id="btnCancel" class="btn-control cancel" onclick="cancelQueue()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                            </svg>
                            Batalkan
                        </button>
                    </div>
                </div>

                <!-- Form Masukan -->
                <form id="checkerForm" action="" method="POST" onsubmit="startQueue(event)">
                    
                    <div class="tabs-nav">
                        <button type="button" id="tabManualBtn" class="tab-btn active" onclick="switchTab('manual')">Input Manual</button>
                        <button type="button" id="tabFileBtn" class="tab-btn" onclick="switchTab('file')">Unggah File .txt</button>
                    </div>

                    <!-- Content Tab: Manual -->
                    <div id="tabManual" class="tab-content active">
                        <div class="input-wrapper">
                            <label for="phones">Nomor Telepon</label>
                            <textarea 
                                id="phones" 
                                name="phones" 
                                placeholder="Contoh:&#10;081234567890&#10;+6281298765432&#10;+258841234567"></textarea>
                            
                            <div class="helper">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                    <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                                </svg>
                                Gunakan baris baru, koma, atau spasi sebagai pemisah.
                            </div>
                        </div>
                    </div>

                    <!-- Content Tab: File -->
                    <div id="tabFile" class="tab-content">
                        <div class="input-wrapper">
                            <label for="fileInput">Pilih File .txt</label>
                            <input type="file" id="fileInput" accept=".txt" style="padding: 12px; border: 1.5px dashed var(--border); border-radius: 16px; background: #fff; width: 100%; outline: none;" onchange="handleFileSelect()">
                            <div class="helper" id="fileInfoText">
                                Format file harus berupa teks polos (.txt) dengan satu nomor per baris.
                            </div>
                        </div>
                        
                    </div>

                    <!-- Opsi Pemrosesan -->
                    <div class="file-options-grid">
                        <div class="option-group" id="optStartOffset" style="display: none;">
                            <label for="startOffset">Mulai dari Baris ke-</label>
                            <input type="number" id="startOffset" min="1" value="1">
                        </div>
                        <div class="option-group" id="optMaxLimit" style="display: none;">
                            <label for="maxLimit">Jumlah Nomor Maksimum (Maks 1000)</label>
                            <input type="number" id="maxLimit" min="1" max="1000" value="1000">
                        </div>
                        <div class="option-group">
                            <label for="batchDelay">Jeda Pengiriman Antar Nomor</label>
                            <select id="batchDelay">
                                <option value="0">Tanpa Jeda</option>
                                <option value="2" selected>2 Detik (Bawaan)</option>
                                <option value="5">5 Detik</option>
                                <option value="10">10 Detik (Disarankan)</option>
                                <option value="30">30 Detik</option>
                            </select>
                        </div>
                        <div class="option-group">
                            <label>Mode Pemrosesan</label>
                            <input type="text" value="Satu Per Satu (Real-Time)" disabled style="background: #e2e8f0; color: #64748b; font-weight: 500;">
                        </div>
                    </div>

                    <button type="submit" id="btnSubmit" style="margin-top: 24px; width: 100%;">
                        <span id="btnText">Mulai Periksa Nomor</span>
                    </button>
                </form>

                <!-- Menampilkan hasil pengecekan secara asinkron AJAX -->
                <div id="ajaxResultsSection" class="results-section" style="display: none;">
                    <h2 class="section-title">Hasil Pengecekan</h2>

                    <!-- Panel Ringkasan / Stats AJAX -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-val" id="ajaxStatTotal">0</div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-card" style="border-left: 3px solid var(--success);">
                            <div class="stat-val" style="color: var(--success);" id="ajaxStatRegistered">0</div>
                            <div class="stat-label">Terdaftar</div>
                        </div>
                        <div class="stat-card" style="border-left: 3px solid var(--danger);">
                            <div class="stat-val" style="color: var(--danger);" id="ajaxStatUnregistered">0</div>
                            <div class="stat-label">Tidak Terdaftar</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-val" style="color: #64748b;" id="ajaxStatFailed">0</div>
                            <div class="stat-label">Gagal / Error</div>
                        </div>
                    </div>

                    <!-- Panel Ringkasan OTP AJAX jika HLR aktif -->
                    <?php if (defined('USE_HLR_LOOKUP') && USE_HLR_LOOKUP): ?>
                        <div class="stats-grid" style="margin-top: -12px; margin-bottom: 24px;">
                            <div class="stat-card" style="border-left: 3px solid #16a34a;">
                                <div class="stat-val" style="color: #16a34a;" id="ajaxStatOtpReady">0</div>
                                <div class="stat-label">Siap OTP</div>
                            </div>
                            <div class="stat-card" style="border-left: 3px solid #d97706;">
                                <div class="stat-val" style="color: #d97706;" id="ajaxStatOtpLimited">0</div>
                                <div class="stat-label">Limit OTP</div>
                            </div>
                            <div class="stat-card" style="border-left: 3px solid #e53e3e;">
                                <div class="stat-val" style="color: #e53e3e;" id="ajaxStatOtpDelay">0</div>
                                <div class="stat-label">Delay OTP</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-val" style="color: #94a3b8;" id="ajaxStatOtpError">0</div>
                                <div class="stat-label">HLR Gagal</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tombol Aksi Unduh Hasil Pengecekan -->
                    <div id="ajaxDownloadActions" class="queue-controls" style="margin-top: -8px; margin-bottom: 24px; justify-content: flex-start; display: none;">
                        <button type="button" class="btn-control resume" onclick="downloadRegistered()" style="background: #e6fffb; color: #0f766e; border: 1.5px solid #0f766e;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="margin-right: 4px;">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                            </svg>
                            Unduh Terdaftar (<span id="countRegDownload">0</span>)
                        </button>
                        <?php if (defined('USE_HLR_LOOKUP') && USE_HLR_LOOKUP): ?>
                            <button type="button" class="btn-control resume" onclick="downloadOtpReady()" style="background: #eff6ff; color: #2563eb; border: 1.5px solid #2563eb;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="margin-right: 4px;">
                                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                    <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                                </svg>
                                Unduh Siap OTP (<span id="countOtpDownload">0</span>)
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Tabel Hasil AJAX -->
                    <div class="results-table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Input</th>
                                    <th>Hasil Status</th>
                                    <?php if (defined('USE_HLR_LOOKUP') && USE_HLR_LOOKUP): ?>
                                        <th>Tipe Line</th>
                                        <th>Status OTP</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="ajaxResultsTableBody">
                                <!-- Baris hasil diisi dinamis oleh JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="footer-note">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="color: var(--muted);">
                        <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                    </svg>
                    Sistem ini tidak memerlukan scan QR code, login WhatsApp, ataupun membaca chat Anda.
                </div>
            </div>
        </div>
    </div>

    <script>
        // Konfigurasi HLR dari PHP
        const useHlrLookup = <?php echo (defined('USE_HLR_LOOKUP') && USE_HLR_LOOKUP) ? 'true' : 'false'; ?>;
        const bulkCheckDelay = <?php echo BULK_CHECK_DELAY; ?>;
        
        let activeTab = 'manual';
        let fileContent = '';
        
        // State Antrean
        let queueBatches = [];
        let currentBatchIndex = 0;
        let isPaused = false;
        let isCancelled = false;
        let cooldownInterval = null;
        let cooldownTimeLeft = 0;
        let cooldownType = 'none'; // 'none', 'batch', 'otp_delay'
        
        // Statistik Global
        let stats = {
            total: 0,
            registered: 0,
            unregistered: 0,
            failed: 0,
            otp_ready: 0,
            otp_limited: 0,
            otp_delay: 0,
            otp_error: 0
        };

        // List Unduhan
        let registeredNumbers = [];
        let otpReadyNumbers = [];

        function switchTab(tab) {
            activeTab = tab;
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            if (tab === 'manual') {
                document.getElementById('tabManualBtn').classList.add('active');
                document.getElementById('tabManual').classList.add('active');
                document.getElementById('phones').setAttribute('required', 'required');
                
                // Sembunyikan offset & limit untuk manual input
                document.getElementById('optStartOffset').style.display = 'none';
                document.getElementById('optMaxLimit').style.display = 'none';
            } else {
                document.getElementById('tabFileBtn').classList.add('active');
                document.getElementById('tabFile').classList.add('active');
                document.getElementById('phones').removeAttribute('required');
                
                // Tampilkan offset & limit untuk unggah file
                document.getElementById('optStartOffset').style.display = 'flex';
                document.getElementById('optMaxLimit').style.display = 'flex';
            }
        }

        // Jalankan inisialisasi awal saat dokumen selesai dimuat
        document.addEventListener('DOMContentLoaded', () => {
            const delaySelect = document.getElementById('batchDelay');
            if (delaySelect) {
                let foundOption = false;
                for (let i = 0; i < delaySelect.options.length; i++) {
                    if (parseInt(delaySelect.options[i].value, 10) === bulkCheckDelay) {
                        delaySelect.selectedIndex = i;
                        foundOption = true;
                        break;
                    }
                }
                if (!foundOption) {
                    const newOpt = document.createElement('option');
                    newOpt.value = bulkCheckDelay;
                    newOpt.textContent = `${bulkCheckDelay} Detik (Bawaan)`;
                    newOpt.selected = true;
                    delaySelect.appendChild(newOpt);
                }
            }
            
            switchTab('manual');
        });

        function handleFileSelect() {
            const fileInput = document.getElementById('fileInput');
            const fileInfoText = document.getElementById('fileInfoText');
            
            if (fileInput.files.length === 0) {
                fileContent = '';
                fileInfoText.textContent = "Format file harus berupa teks polos (.txt) dengan satu nomor per baris.";
                return;
            }
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                fileContent = e.target.result;
                const lineCount = fileContent.split(/[\n\r]+/).filter(line => line.trim() !== '').length;
                fileInfoText.innerHTML = `<strong>File terpilih:</strong> ${file.name} (Terditifikasi ${lineCount.toLocaleString('id-ID')} nomor).`;
            };
            
            reader.onerror = function() {
                alert('Gagal membaca file!');
                fileContent = '';
            };
            
            reader.readAsText(file);
        }

        function startQueue(event) {
            event.preventDefault();
            
            let rawNumbers = [];
            
            if (activeTab === 'manual') {
                const manualText = document.getElementById('phones').value;
                if (!manualText.trim()) {
                    alert('Silakan masukkan nomor telepon terlebih dahulu!');
                    return;
                }
                rawNumbers = manualText.split(/[\n\r,;]+/);
            } else {
                if (!fileContent.trim()) {
                    alert('Silakan pilih file .txt yang valid terlebih dahulu!');
                    return;
                }
                rawNumbers = fileContent.split(/[\n\r,;]+/);
            }
            
            // Bersihkan nomor kosong
            const cleanNumbers = rawNumbers.map(n => n.trim()).filter(n => n !== '');
            if (cleanNumbers.length === 0) {
                alert('Tidak ada nomor telepon valid yang ditemukan untuk diproses!');
                return;
            }
            
            // Pengaturan Offset & Limit (Hanya berlaku untuk upload file, atau kita terapkan universal)
            let startOffset = 1;
            let maxLimit = 1000;
            
            if (activeTab === 'file') {
                startOffset = parseInt(document.getElementById('startOffset').value, 10) || 1;
                maxLimit = parseInt(document.getElementById('maxLimit').value, 10) || 1000;
                
                // Cegah batas limit melebihi 1000 demi keamanan resource
                if (maxLimit > 1000) {
                    maxLimit = 1000;
                    document.getElementById('maxLimit').value = 1000;
                }
            } else {
                // Untuk manual input, limit bebas tetapi disesuaikan dengan input
                maxLimit = cleanNumbers.length;
            }
            
            // Filter nomor berdasarkan offset dan limit
            const startIndex = Math.max(0, startOffset - 1);
            const endIndex = Math.min(cleanNumbers.length, startIndex + maxLimit);
            const numbersToProcess = cleanNumbers.slice(startIndex, endIndex);
            
            if (numbersToProcess.length === 0) {
                alert('Baris mulai (offset) yang Anda masukkan melebihi jumlah total nomor yang tersedia!');
                return;
            }
            
            // Bagi menjadi batch-batch berisi maksimal 1 nomor (real-time)
            const batchSize = 1;
            queueBatches = [];
            for (let i = 0; i < numbersToProcess.length; i += batchSize) {
                queueBatches.push(numbersToProcess.slice(i, i + batchSize));
            }
            
            // Inisialisasi ulang state antrean
            currentBatchIndex = 0;
            isPaused = false;
            isCancelled = false;
            cooldownType = 'none';
            cooldownTimeLeft = 0;
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            
            // Reset statistik global
            stats = {
                total: 0,
                registered: 0,
                unregistered: 0,
                failed: 0,
                otp_ready: 0,
                otp_limited: 0,
                otp_delay: 0,
                otp_error: 0
            };
            updateStatsUI();
            
            // Inisialisasi ulang list unduhan
            registeredNumbers = [];
            otpReadyNumbers = [];
            document.getElementById('countRegDownload').textContent = '0';
            if (useHlrLookup) {
                document.getElementById('countOtpDownload').textContent = '0';
            }
            document.getElementById('ajaxDownloadActions').style.display = 'flex';
            
            // Kosongkan tabel hasil
            document.getElementById('ajaxResultsTableBody').innerHTML = '';
            
            // Sembunyikan form input dan tampilkan panel progress & hasil
            document.getElementById('checkerForm').style.display = 'none';
            document.getElementById('progressPanel').style.display = 'block';
            document.getElementById('ajaxResultsSection').style.display = 'block';
            
            // Reset kontrol tombol jeda
            document.getElementById('btnPause').style.display = 'inline-flex';
            document.getElementById('btnResume').style.display = 'none';
            
            // Jalankan antrean
            processNextBatch();
        }

        function processNextBatch() {
            if (isCancelled) {
                document.getElementById('progressTitle').textContent = "Pengecekan Dibatalkan";
                document.getElementById('progressStatusText').textContent = "Proses dibatalkan oleh pengguna.";
                resetSubmitFormButton();
                return;
            }
            
            if (isPaused) {
                document.getElementById('progressTitle').textContent = "Pengecekan Dijeda";
                document.getElementById('progressStatusText').textContent = "Antrean dihentikan sementara. Klik 'Lanjutkan' untuk memproses.";
                return;
            }
            
            if (currentBatchIndex >= queueBatches.length) {
                // Semua batch selesai
                document.getElementById('progressTitle').textContent = "Pengecekan Selesai!";
                document.getElementById('progressBarFill').style.width = '100%';
                document.getElementById('progressPercentage').textContent = '100%';
                document.getElementById('progressStatusText').innerHTML = `<strong>Selesai!</strong> Berhasil memproses total ${stats.total} nomor.`;
                
                document.getElementById('btnPause').style.display = 'none';
                document.getElementById('btnResume').style.display = 'none';
                document.getElementById('btnCancel').textContent = "Periksa Nomor Lain";
                return;
            }
            
            const currentBatch = queueBatches[currentBatchIndex];
            const batchNum = currentBatchIndex + 1;
            const totalBatches = queueBatches.length;
            
            // Hitung persentase progress
            const progressPct = Math.round((currentBatchIndex / totalBatches) * 100);
            document.getElementById('progressBarFill').style.width = `${progressPct}%`;
            document.getElementById('progressPercentage').textContent = `${progressPct}%`;
            
            document.getElementById('progressTitle').textContent = `Memproses Batch ${batchNum} dari ${totalBatches}...`;
            document.getElementById('progressStatusText').textContent = `Sedang memproses ${currentBatch.length} nomor (Batch ${batchNum}/${totalBatches})...`;
            
            // Kirim request AJAX ke backend
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'check_batch',
                    phones: currentBatch
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP Error status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Update statistik global
                    stats.total += data.stats.total;
                    stats.registered += data.stats.registered;
                    stats.unregistered += data.stats.unregistered;
                    stats.failed += data.stats.failed;
                    stats.otp_ready += data.stats.otp_ready;
                    stats.otp_limited += data.stats.otp_limited;
                    stats.otp_delay += data.stats.otp_delay;
                    stats.otp_error += data.stats.otp_error;
                    
                    updateStatsUI();
                    
                    // Render baris hasil ke tabel
                    renderResultsToTable(data.results);
                    
                    // Saring nomor ke list unduhan
                    data.results.forEach(res => {
                        if (res.valid && res.registered === true) {
                            registeredNumbers.push(res.normalized);
                            if (useHlrLookup && res.hlr_otp_status === 'ready') {
                                otpReadyNumbers.push(res.normalized);
                            }
                        }
                    });
                    
                    // Update jumlah pada tombol unduh
                    document.getElementById('countRegDownload').textContent = registeredNumbers.length;
                    if (useHlrLookup) {
                        document.getElementById('countOtpDownload').textContent = otpReadyNumbers.length;
                    }
                    
                    currentBatchIndex++;
                    
                    // Hitung jika ada nomor yang mengalami Delay OTP
                    let maxOtpDelayTime = 0;
                    data.results.forEach(res => {
                        if (res.hlr_otp_status === 'delay' && res.hlr_otp_delay_time > maxOtpDelayTime) {
                            maxOtpDelayTime = res.hlr_otp_delay_time;
                        }
                    });
                    
                    if (maxOtpDelayTime > 0 && !isCancelled && !isPaused) {
                        startOtpDelayCooldown(maxOtpDelayTime);
                    } else if (currentBatchIndex < totalBatches) {
                        const batchDelay = parseInt(document.getElementById('batchDelay').value, 10) || 0;
                        if (batchDelay > 0 && !isCancelled && !isPaused) {
                            startCooldown(batchDelay);
                        } else {
                            processNextBatch();
                        }
                    } else {
                        processNextBatch();
                    }
                } else {
                    alert('Gagal memproses batch: ' + (data.error || 'Terjadi kesalahan sistem.'));
                    pauseQueue();
                }
            })
            .catch(error => {
                console.error('Error fetching batch:', error);
                alert('Koneksi terputus atau server timeout. Antrean dijeda secara otomatis.');
                pauseQueue();
            });
        }

        function startCooldown(seconds) {
            cooldownType = 'batch';
            cooldownTimeLeft = seconds;
            runCooldownInterval(seconds);
        }

        function runCooldownInterval(seconds) {
            document.getElementById('progressTitle').textContent = "Jeda Istirahat (Cooldown)...";
            document.getElementById('progressStatusText').innerHTML = `Menunggu selama <strong>${cooldownTimeLeft} detik</strong> sebelum memproses batch berikutnya untuk mencegah batas API/Banned...`;
            
            if (cooldownInterval) clearInterval(cooldownInterval);
            
            cooldownInterval = setInterval(() => {
                cooldownTimeLeft--;
                if (cooldownTimeLeft <= 0) {
                    clearInterval(cooldownInterval);
                    cooldownInterval = null;
                    cooldownType = 'none';
                    processNextBatch();
                } else {
                    document.getElementById('progressStatusText').innerHTML = `Menunggu selama <strong>${cooldownTimeLeft} detik</strong> sebelum memproses batch berikutnya untuk mencegah batas API/Banned...`;
                }
            }, 1000);
        }

        function startOtpDelayCooldown(seconds) {
            cooldownType = 'otp_delay';
            cooldownTimeLeft = seconds;
            runOtpDelayCooldownInterval(seconds);
        }

        function runOtpDelayCooldownInterval(seconds) {
            document.getElementById('progressTitle').textContent = "Terdeteksi Delay OTP (Jeda 2 Menit)...";
            document.getElementById('progressStatusText').innerHTML = `Menangguhkan antrean selama <strong>${cooldownTimeLeft} detik</strong> karena terdeteksi nomor dengan status Delay OTP...`;
            
            if (cooldownInterval) clearInterval(cooldownInterval);
            
            cooldownInterval = setInterval(() => {
                cooldownTimeLeft--;
                if (cooldownTimeLeft <= 0) {
                    clearInterval(cooldownInterval);
                    cooldownInterval = null;
                    cooldownType = 'none';
                    processNextBatch();
                } else {
                    document.getElementById('progressStatusText').innerHTML = `Menangguhkan antrean selama <strong>${cooldownTimeLeft} detik</strong> karena terdeteksi nomor dengan status Delay OTP...`;
                }
            }, 1000);
        }

        function pauseQueue() {
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            isPaused = true;
            document.getElementById('btnPause').style.display = 'none';
            document.getElementById('btnResume').style.display = 'inline-flex';
            
            if (cooldownType !== 'none') {
                document.getElementById('progressTitle').textContent = "Pengecekan Dijeda (Jeda Waktu)";
                document.getElementById('progressStatusText').innerHTML = `Antrean dijeda. Sisa waktu jeda: <strong>${cooldownTimeLeft} detik</strong>.`;
            } else {
                document.getElementById('progressTitle').textContent = "Pengecekan Dijeda";
                document.getElementById('progressStatusText').textContent = "Antrean dihentikan sementara oleh pengguna.";
            }
        }

        function resumeQueue() {
            isPaused = false;
            document.getElementById('btnPause').style.display = 'inline-flex';
            document.getElementById('btnResume').style.display = 'none';
            
            if (cooldownType === 'batch') {
                runCooldownInterval(cooldownTimeLeft);
            } else if (cooldownType === 'otp_delay') {
                runOtpDelayCooldownInterval(cooldownTimeLeft);
            } else {
                processNextBatch();
            }
        }

        function cancelQueue() {
            if (cooldownInterval) {
                clearInterval(cooldownInterval);
                cooldownInterval = null;
            }
            cooldownType = 'none';
            cooldownTimeLeft = 0;
            
            isCancelled = true;
            
            // Jika antrean sudah selesai, klik tombol ini akan mereset form kembali
            if (currentBatchIndex >= queueBatches.length) {
                resetSubmitFormButton();
                return;
            }
            
            if (confirm('Apakah Anda yakin ingin membatalkan seluruh proses antrean yang tersisa?')) {
                document.getElementById('progressTitle').textContent = "Pengecekan Dibatalkan";
                document.getElementById('progressStatusText').textContent = "Proses dibatalkan oleh pengguna.";
                resetSubmitFormButton();
            } else {
                isCancelled = false;
                if (!isPaused) {
                    if (cooldownType === 'batch') {
                        runCooldownInterval(cooldownTimeLeft);
                    } else if (cooldownType === 'otp_delay') {
                        runOtpDelayCooldownInterval(cooldownTimeLeft);
                    } else {
                        processNextBatch();
                    }
                }
            }
        }

        function resetSubmitFormButton() {
            document.getElementById('checkerForm').style.display = 'flex';
            document.getElementById('progressPanel').style.display = 'none';
            document.getElementById('btnCancel').textContent = "Batalkan";
            document.getElementById('ajaxDownloadActions').style.display = 'none';
            
            // Bersihkan file input jika terunggah
            if (activeTab === 'file') {
                document.getElementById('fileInput').value = '';
                document.getElementById('fileInfoText').textContent = "Format file harus berupa teks polos (.txt) dengan satu nomor per baris.";
                fileContent = '';
            }
        }

        // Fungsi Download File Hasil Pengecekan
        function downloadTxtFile(numbersArray, filename) {
            if (numbersArray.length === 0) {
                alert('Belum ada nomor dalam daftar ini untuk diunduh!');
                return;
            }
            const fileContent = numbersArray.join('\r\n');
            const blob = new Blob([fileContent], { type: 'text/plain;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function downloadRegistered() {
            const dateStr = new Date().toISOString().slice(0, 10);
            downloadTxtFile(registeredNumbers, `wa_terdaftar_${dateStr}.txt`);
        }

        function downloadOtpReady() {
            const dateStr = new Date().toISOString().slice(0, 10);
            downloadTxtFile(otpReadyNumbers, `wa_siap_otp_${dateStr}.txt`);
        }

        function updateStatsUI() {
            document.getElementById('ajaxStatTotal').textContent = stats.total;
            document.getElementById('ajaxStatRegistered').textContent = stats.registered;
            document.getElementById('ajaxStatUnregistered').textContent = stats.unregistered;
            document.getElementById('ajaxStatFailed').textContent = stats.failed;
            
            if (useHlrLookup) {
                const readyEl = document.getElementById('ajaxStatOtpReady');
                const limitedEl = document.getElementById('ajaxStatOtpLimited');
                const delayEl = document.getElementById('ajaxStatOtpDelay');
                const errorEl = document.getElementById('ajaxStatOtpError');
                
                if (readyEl) readyEl.textContent = stats.otp_ready;
                if (limitedEl) limitedEl.textContent = stats.otp_limited;
                if (delayEl) delayEl.textContent = stats.otp_delay;
                if (errorEl) errorEl.textContent = stats.otp_error;
            }
        }

        function renderResultsToTable(results) {
            const tableBody = document.getElementById('ajaxResultsTableBody');
            
            results.forEach(res => {
                const tr = document.createElement('tr');
                
                // Kolom Input
                const tdRaw = document.createElement('td');
                tdRaw.style.fontWeight = '500';
                tdRaw.textContent = res.raw;
                tr.appendChild(tdRaw);
                
                // Kolom Hasil Status
                const tdStatus = document.createElement('td');
                let statusHtml = '';
                if (!res.valid) {
                    statusHtml = `<span class="status-badge error">✗ Format Tidak Valid</span>`;
                } else if (res.registered === true) {
                    statusHtml = `<span class="status-badge registered">✓ Nomor terdaftar di WhatsApp</span>`;
                } else if (res.registered === false) {
                    statusHtml = `<span class="status-badge unregistered">✗ Nomor tidak terdaftar di WhatsApp</span>`;
                } else {
                    statusHtml = `<span class="status-badge error" title="${res.error || ''}">! Error: ${res.error || 'Gagal'}</span>`;
                }
                tdStatus.innerHTML = statusHtml;
                tr.appendChild(tdStatus);
                
                // Kolom HLR (jika HLR aktif)
                if (useHlrLookup) {
                    // Tipe Line
                    const tdLine = document.createElement('td');
                    tdLine.style.color = '#64748b';
                    tdLine.style.fontSize = '14px';
                    tdLine.style.textTransform = 'capitalize';
                    tdLine.textContent = res.hlr_line_type || '-';
                    tr.appendChild(tdLine);
                    
                    // Status OTP
                    const tdOtp = document.createElement('td');
                    let otpHtml = '';
                    if (res.hlr_otp_status === 'ready') {
                        otpHtml = `<span class="status-badge otp-ready">✓ Siap Menerima OTP</span>`;
                    } else if (res.hlr_otp_status === 'limited') {
                        otpHtml = `<span class="status-badge otp-limited">⚠ Terkena Limit</span>`;
                    } else if (res.hlr_otp_status === 'delay') {
                        otpHtml = `<span class="status-badge otp-delay">✗ Delay / Offline</span>`;
                    } else if (res.hlr_otp_status === 'error') {
                        otpHtml = `<span class="status-badge error" title="${res.hlr_error || ''}">! Error HLR</span>`;
                    } else {
                        otpHtml = `<span class="status-badge otp-disabled">-</span>`;
                    }
                    tdOtp.innerHTML = otpHtml;
                    tr.appendChild(tdOtp);
                }
                
                tableBody.appendChild(tr);
            });
        }
    </script>
</body>
</html>
