<?php
// Setting headers to ensure JSON response and prevent CORS issues
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

// Enable error reporting for debugging, but prevent errors from being displayed in the response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log errors to a file instead of displaying them
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

// Custom logging function for debugging
function debugLog($message) {
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Function to send a JSON response and exit
function sendResponse($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Check if the request method is GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    debugLog('Invalid request method: ' . $_SERVER['REQUEST_METHOD']);
    sendResponse(405, ['error' => 'Method Not Allowed']);
}

// Extract patient ID from query parameters
$patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : null;
debugLog('Patient ID extracted from query: ' . $patientId);
if (!$patientId) {
    debugLog('Missing patientId in query parameters');
    sendResponse(400, ['error' => 'Missing patientId']);
}

// Check log file writability
$logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log';
if (!is_writable(dirname($logFile))) {
    error_log("Log directory is not writable: " . dirname($logFile));
    sendResponse(500, ['error' => 'Server error: Log directory is not writable']);
}

// Log all headers for debugging
$headers = getallheaders();
debugLog('All request headers: ' . json_encode($headers));

// Load dependencies
$autoloadPath = 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    debugLog('Autoload file not found at: ' . $autoloadPath);
    sendResponse(500, ['error' => 'Server error: Autoload file not found']);
}

try {
    require_once $autoloadPath;
    debugLog('Autoload file loaded successfully');
} catch (Exception $e) {
    debugLog('Failed to load autoload.php: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Server error: Failed to load dependencies']);
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Validate JWT token (aligned with index.php)
$authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
debugLog('Authorization header from $_SERVER: ' . $authHeader);

// Fallback to getallheaders() if $_SERVER['HTTP_AUTHORIZATION'] is empty
if (empty($authHeader) && isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    debugLog('Authorization header from getallheaders(): ' . $authHeader);
}

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant']);
}

$token = $tokenMatches[1];
debugLog('Token extracted: ' . $token);

// Log server time for comparison
$serverTime = time();
$serverTimeFormatted = date('Y-m-d H:i:s', $serverTime);
debugLog("Server time: $serverTimeFormatted (timestamp: $serverTime)");

$jwtSecret = 'your_jwt_secret_key'; // Must match JWT_SECRET in index.php
try {
    $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
    $exp = $decoded->exp;
    $expFormatted = date('Y-m-d H:i:s', $exp);
    debugLog("JWT decoded successfully: " . json_encode($decoded));
    debugLog("Token expiration: $expFormatted (timestamp: $exp)");
    
    if ($exp < $serverTime) {
        debugLog("Token has expired: Expiration $exp is less than server time $serverTime");
        sendResponse(401, ['error' => 'Session expirée. Veuillez vous reconnecter.']);
    }

    // Verify token structure (matching index.php expectations)
    if (!isset($decoded->data->id) || !isset($decoded->data->role)) {
        debugLog("Invalid token structure: Missing id or role in data");
        sendResponse(401, ['error' => 'Token invalide: Données utilisateur manquantes']);
    }

    $user_id = $decoded->data->id;
    $role = $decoded->data->role;
    debugLog("User ID: $user_id, Role: $role");
} catch (Exception $e) {
    debugLog('JWT validation failed: ' . $e->getMessage());
    sendResponse(401, ['error' => 'Token invalide: ' . $e->getMessage()]);
}

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=projet_medical', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
    ]);
    debugLog('Database connection established');
} catch (PDOException $e) {
    debugLog('Database connection failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Database connection failed: ' . $e->getMessage()]);
}

// Validate database schema
try {
    $stmt = $pdo->query("DESCRIBE dicom_instances");
    $columns = $stmt->fetchAll();
    $requiredColumns = ['orthanc_instance_id', 'description', 'study_date', 'patient_id'];
    $missingColumns = array_diff($requiredColumns, array_column($columns, 'Field'));
    if (!empty($missingColumns)) {
        debugLog('Missing required columns in dicom_instances table: ' . implode(', ', $missingColumns));
        sendResponse(500, ['error' => 'Database schema error: Missing columns - ' . implode(', ', $missingColumns)]);
    }
    debugLog('Database schema validated successfully');
} catch (PDOException $e) {
    debugLog('Failed to validate database schema: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to validate database schema: ' . $e->getMessage()]);
}

// Fetch DICOM instances for the patient
try {
    $stmt = $pdo->prepare('SELECT orthanc_instance_id, description, study_date FROM dicom_instances WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $dicomInstances = $stmt->fetchAll();
    debugLog('DICOM instances fetched for patient ' . $patientId . ': ' . json_encode($dicomInstances));

    if (empty($dicomInstances)) {
        debugLog('No DICOM instances found for patient ' . $patientId);
        sendResponse(200, ['dicom_instances' => []]);
    }

    // Format the response to match frontend expectations
    $formattedInstances = array_map(function($instance) {
        return [
            'orthanc_instance_id' => $instance['orthanc_instance_id'],
            'upload_date' => $instance['study_date'],
            'description' => $instance['description'] ?? 'N/A'
        ];
    }, $dicomInstances);

    sendResponse(200, ['dicom_instances' => $formattedInstances]);
} catch (PDOException $e) {
    debugLog('Error fetching DICOM instances: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to fetch DICOM instances: ' . $e->getMessage()]);
}
?>