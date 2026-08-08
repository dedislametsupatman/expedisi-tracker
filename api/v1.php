<?php
/**
 * Public API - Tracking & Cost Estimation
 * For third-party integration
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../config.php';

// ─── API Key Auth ────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$keyData = null;
if (!empty($apiKey)) {
    $keyData = validateApiKey($apiKey);
}

// Allow /track with courier=spx without API key (SPX API is public, no CORS issues from backend)
$isSpxTrack = preg_match('#/api/v1/track#', $_SERVER['REQUEST_URI'] ?? '')
    && strtolower($_GET['courier'] ?? '') === 'spx';

// Debug: log all requests to a file (append)
@file_put_contents('/tmp/v1_debug.log', date('Y-m-d H:i:s') . ' URI=' . ($_SERVER['REQUEST_URI'] ?? '') . ' courier=' . ($_GET['courier'] ?? 'null') . ' isSpxTrack=' . ($isSpxTrack ? 1 : 0) . ' hasKey=' . (!empty($keyData) ? 1 : 0) . PHP_EOL);

if (!$keyData && !$isSpxTrack) {
    json_error('API Key tidak valid atau tidak aktif. Pastikan Anda sudah registrasi dan memiliki API key yang aktif.', 401);
}

// ─── Route ──────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/v1', '', $path) ?: '/';

switch ($path) {
    case '/cost':
        getCost();
        break;
    case '/track':
        track();
        break;
    case '/couriers':
        getCouriers();
        break;
    case '/cities':
        getCities();
        break;
    case '/':
        json_response([
            'name' => 'Expedisi Tracker API',
            'version' => '1.0',
            'description' => 'API untuk cek ongkir dan lacak paket expedisi Indonesia'
        ]);
        break;
    default:
        json_error('Endpoint tidak ditemukan', 404);
}

// ─── Endpoints ──────────────────────────────────────────────────

/**
 * GET /api/v1/cost?origin=bekasi&destination=jakartatimur&weight=1000&courier=jne
 */
function getCost() {
    $origin = $_GET['origin'] ?? '';
    $destination = $_GET['destination'] ?? '';
    $weight = intval($_GET['weight'] ?? 1000);
    $courier = strtolower($_GET['courier'] ?? '');
    
    if (empty($origin) || empty($destination)) {
        json_error('Parameter origin dan destination wajib diisi', 400);
    }
    if ($weight < 1 || $weight > 30000) {
        json_error('Weight harus antara 1-30000 gram', 400);
    }
    
    $cityToVillageCode = getCityToVillageCode();
    $originCode = $cityToVillageCode[$origin] ?? null;
    $destCode = $cityToVillageCode[$destination] ?? null;
    
    if (!$originCode) {
        json_error("Kode kota asal '$origin' tidak ditemukan. Gunakan kode kota yang valid.", 400);
    }
    if (!$destCode) {
        json_error("Kode kota tujuan '$destination' tidak ditemukan. Gunakan kode kota yang valid.", 400);
    }
    
    // Call api.co.id
    $apiKey = API_CO_ID_KEY;
    $params = http_build_query([
        'origin_village_code' => $originCode,
        'destination_village_code' => $destCode,
        'weight' => $weight
    ]);
    
    $ch = curl_init('https://use.api.co.id/expedition/shipping-cost?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => [
            'x-api-co-id: ' . $apiKey,
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logApiUsage($GLOBALS['keyData']['id'] ?? null, '/cost', 'GET', 500);
        json_error('Gagal mengambil data dari server: ' . $error, 502);
    }
    
    $data = json_decode($response, true);
    logApiUsage($GLOBALS['keyData']['id'] ?? null, '/cost', 'GET', $httpCode);
    
    if (!$data['is_success']) {
        json_response([
            'success' => true,
            'origin' => ['city' => $origin, 'code' => $originCode],
            'destination' => ['city' => $destination, 'code' => $destCode],
            'weight' => $weight,
            'couriers' => []
        ]);
    }
    
    $couriers = $data['data']['couriers'] ?? [];
    $result = array_map(function($c) {
        return [
            'courier_code' => strtolower($c['courier_code']),
            'courier_name' => $c['courier_name'],
            'services' => array_map(function($s) {
                return [
                    'service_code' => $s['service_code'] ?? $s['courier_code'] ?? '',
                    'service_name' => $s['courier_name'] ?? '',
                    'price' => intval($s['price'] ?? 0),
                    'etd' => $s['estimation'] ?? ''
                ];
            }, $c['services'] ?? [])
        ];
    }, $couriers);
    
    // Filter by courier if specified
    if (!empty($courier)) {
        $result = array_filter($result, fn($c) => $c['courier_code'] === $courier);
        $result = array_values($result);
    }
    
    json_response([
        'success' => true,
        'origin' => ['city' => $origin, 'code' => $originCode],
        'destination' => ['city' => $destination, 'code' => $destCode],
        'weight' => $weight,
        'couriers' => $result
    ]);
}

/**
 * GET /api/v1/track?awb=JP1234567890&courier=jne
 */
function track() {
    $awb = $_GET['awb'] ?? '';
    $courier = strtolower($_GET['courier'] ?? '');

    if (empty($awb)) {
        json_error('Parameter AWB/Resi wajib diisi', 400);
    }

    // Shopee Xpress — call SPX API from backend (bypasses CORS)
    if ($courier === 'spx') {
        $spxResponse = @file_get_contents(
            'https://spx.co.id/shipment/order/open/order/get_order_info?spx_tn=' . urlencode($awb) . '&language_code=id'
        );

        if ($spxResponse === false) {
            json_error('Gagal terhubung ke server Shopee. Coba lagi nanti.', 502);
        }

        $data = json_decode($spxResponse, true);

        if (!isset($data['retcode']) || $data['retcode'] !== 0 || !isset($data['data']['sls_tracking_info']['records'])) {
            $msg = $data['message'] ?? 'Resi Shopee Xpress tidak ditemukan';
            json_error($msg, 404);
        }

        $records = $data['data']['sls_tracking_info']['records'];
        $history = [];
        foreach ($records as $r) {
            $history[] = [
                'date' => isset($r['actual_time']) ? date('d M Y, H:i', $r['actual_time']) : '',
                'status' => $r['tracking_name'] ?? $r['milestone_name'] ?? 'Update',
                'location' => $r['current_location']['location_name'] ?? ''
            ];
        }

        logApiUsage($GLOBALS['keyData']['id'] ?? null, '/track', 'GET', 200);
        json_response([
            'success' => true,
            'awb' => strtoupper($awb),
            'courier' => 'spx',
            'status' => $history[0]['status'] ?? 'UNKNOWN',
            'history' => $history
        ]);
        return;
    }

    // Other couriers — return mock data (replace with real API calls)
    $mockHistory = [
        ['date' => date('d M Y, H:i', strtotime('-2 days')), 'status' => 'Paket diterima di gudang asal', 'location' => 'Jakarta'],
        ['date' => date('d M Y, H:i', strtotime('-1 days')), 'status' => 'Dalam perjalanan ke kota tujuan', 'location' => 'Surabaya Hub'],
        ['date' => date('d M Y, H:i'), 'status' => 'Paket tiba di kota tujuan', 'location' => 'Surabaya'],
    ];

    logApiUsage($GLOBALS['keyData']['id'] ?? null, '/track', 'GET', 200);

    json_response([
        'success' => true,
        'awb' => strtoupper($awb),
        'courier' => $courier ?: 'jne',
        'status' => 'in_transit',
        'history' => $mockHistory
    ]);
}

/**
 * GET /api/v1/couriers
 */
function getCouriers() {
    $couriers = [
        ['code' => 'jne', 'name' => 'JNE', 'logo' => 'https://asset.kriya.com/logo/jne-600x258.png'],
        ['code' => 'jnt', 'name' => 'J&T Express', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/b/b7/J%26T_Express_Logo.svg'],
        ['code' => 'sicepat', 'name' => 'SiCepat', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/95/Logo_SiCepat.svg/512px-Logo_SiCepat.svg.png'],
        ['code' => 'anteraja', 'name' => 'AnterAja', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/6/6a/Anteraja_Logo.svg'],
        ['code' => 'ninja', 'name' => 'Ninja Xpress', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/0/0d/Ninja_Xpress_Logo.svg'],
        ['code' => 'pos', 'name' => 'Pos Indonesia', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/5/55/Logo_Pos_Indonesia.svg'],
        ['code' => 'tiki', 'name' => 'TIKI', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/4/4a/Logo_TIKI.svg'],
        ['code' => 'spx', 'name' => 'Shopee Xpress', 'logo' => 'https://upload.wikimedia.org/wikipedia/commons/0/0e/Shopee_logo.svg'],
    ];
    
    logApiUsage($GLOBALS['keyData']['id'] ?? null, '/couriers', 'GET', 200);
    json_response(['success' => true, 'couriers' => $couriers]);
}

/**
 * GET /api/v1/cities
 */
function getCities() {
    $cities = [
        ['id' => 'bekasi', 'name' => 'Bekasi', 'province' => 'Jawa Barat'],
        ['id' => 'jakartatimur', 'name' => 'Jakarta Timur', 'province' => 'DKI Jakarta'],
        ['id' => 'jakartabarat', 'name' => 'Jakarta Barat', 'province' => 'DKI Jakarta'],
        ['id' => 'jakartapusat', 'name' => 'Jakarta Pusat', 'province' => 'DKI Jakarta'],
        ['id' => 'jakartaselatan', 'name' => 'Jakarta Selatan', 'province' => 'DKI Jakarta'],
        ['id' => 'jakartautara', 'name' => 'Jakarta Utara', 'province' => 'DKI Jakarta'],
        ['id' => 'bandung', 'name' => 'Bandung', 'province' => 'Jawa Barat'],
        ['id' => 'bogor', 'name' => 'Bogor', 'province' => 'Jawa Barat'],
        ['id' => 'depok', 'name' => 'Depok', 'province' => 'Jawa Barat'],
        ['id' => 'tangerang', 'name' => 'Tangerang', 'province' => 'Banten'],
        ['id' => 'tangerangselatan', 'name' => 'Tangerang Selatan', 'province' => 'Banten'],
        ['id' => 'semarang', 'name' => 'Semarang', 'province' => 'Jawa Tengah'],
        ['id' => 'solo', 'name' => 'Solo', 'province' => 'Jawa Tengah'],
        ['id' => 'yogyakarta', 'name' => 'Yogyakarta', 'province' => 'DI Yogyakarta'],
        ['id' => 'surabaya', 'name' => 'Surabaya', 'province' => 'Jawa Timur'],
        ['id' => 'malang', 'name' => 'Malang', 'province' => 'Jawa Timur'],
        ['id' => 'denpasar', 'name' => 'Denpasar', 'province' => 'Bali'],
        ['id' => 'makassar', 'name' => 'Makassar', 'province' => 'Sulawesi Selatan'],
        ['id' => 'medan', 'name' => 'Medan', 'province' => 'Sumatera Utara'],
        ['id' => 'palembang', 'name' => 'Palembang', 'province' => 'Sumatera Selatan'],
    ];
    
    logApiUsage($GLOBALS['keyData']['id'] ?? null, '/cities', 'GET', 200);
    json_response(['success' => true, 'cities' => $cities]);
}

// ─── City to Village Code Mapping ────────────────────────────────
function getCityToVillageCode(): array {
    return [
        'bekasi' => '3275011001',
        'jakartatimur' => '3175061007',
        'jakartabarat' => '3173021001',
        'jakartapusat' => '3171051002',
        'jakartaselatan' => '3174051006',
        'jakartautara' => '3172031007',
        'bandung' => '3273011003',
        'bogor' => '3271031004',
        'depok' => '3276061001',
        'semarang' => '3374111005',
        'solo' => '3372011002',
        'yogyakarta' => '3471131002',
        'surabaya' => '3578201001',
        'malang' => '3573051003',
        'denpasar' => '5171011003',
        'makassar' => '7371011003',
        'medan' => '1271011001',
        'palembang' => '1671011003',
    ];
}
