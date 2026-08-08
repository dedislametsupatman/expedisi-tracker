<?php
/**
 * SPX Track - Public endpoint for Shopee Xpress tracking
 * No API key required - calls SPX API from backend
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$awb = $_GET['awb'] ?? '';

if (empty($awb)) {
    echo json_encode(['success' => false, 'error' => 'Parameter AWB wajib diisi']);
    exit;
}

$spxResponse = @file_get_contents(
    'https://spx.co.id/shipment/order/open/order/get_order_info?spx_tn=' . urlencode($awb) . '&language_code=id'
);

if ($spxResponse === false) {
    echo json_encode(['success' => false, 'error' => 'Gagal terhubung ke server Shopee. Coba lagi nanti.']);
    exit;
}

$data = json_decode($spxResponse, true);

if (!isset($data['retcode']) || $data['retcode'] !== 0 || !isset($data['data']['sls_tracking_info']['records'])) {
    $msg = $data['message'] ?? 'Resi Shopee Xpress tidak ditemukan';
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
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

echo json_encode([
    'success' => true,
    'awb' => strtoupper($awb),
    'courier' => 'Shopee Xpress',
    'status' => $history[0]['status'] ?? 'UNKNOWN',
    'history' => $history
]);
