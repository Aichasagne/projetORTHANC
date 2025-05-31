<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// Authentication
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing or invalid Authorization header']);
    exit;
}

function decodeJWT($token) {
    try {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;
        $payload = $parts[1];
        $padding = strlen($payload) % 4;
        if ($padding) $payload .= str_repeat('=', 4 - $padding);
        $decodedPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload), true);
        if ($decodedPayload === false) return false;
        return json_decode($decodedPayload, true);
    } catch (Exception $e) {
        return false;
    }
}

$decoded = decodeJWT($token);
if ($decoded === false || !isset($decoded['exp']) || $decoded['exp'] < time()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token']);
    exit;
}

$userId = $decoded['data']['id'] ?? null;
$role = $decoded['data']['role'] ?? null;
if (!$userId || $role !== 'medecin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized user']);
    exit;
}

// Database connection
$host = 'localhost';
$dbname = 'projet_medical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $method = $_SERVER['REQUEST_METHOD'];
    $consultationId = isset($_GET['consultationId']) ? $_GET['consultationId'] : null;

    if ($method === 'GET' && $consultationId) {
        // Verify user access to the consultation
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations c WHERE c.id = ? AND c.medecin_id = ?');
        $stmt->execute([$consultationId, $userId]);
        $hasAccess = $stmt->fetchColumn();

        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this consultation']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM prescriptions WHERE consultation_id = ?');
        $stmt->execute([$consultationId]);
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['prescriptions' => $prescriptions]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>