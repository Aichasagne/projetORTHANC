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
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_study_instance.log';
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

// Extract patient ID from the URL
$requestUri = $_SERVER['REQUEST_URI'];
$pattern = '#^/projet-medical/api/get-study-instance/(\d+)$#';
if (!preg_match($pattern, $requestUri, $matches)) {
    debugLog('Invalid URL format: ' . $requestUri);
    sendResponse(400, ['error' => 'Invalid patient ID']);
}
$patientId = (int)$matches[1];
debugLog('Patient ID extracted: ' . $patientId);

// Load dependencies
try {
    require_once 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
    debugLog('Autoload file loaded successfully');
} catch (Exception $e) {
    debugLog('Failed to load autoload.php: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Server error: Failed to load dependencies']);
}

// Validate JWT token
$authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
debugLog('Authorization header: ' . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Missing or invalid Authorization header']);
}

$token = $tokenMatches[1];
debugLog('Token extracted: ' . $token);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwtSecret = 'your_jwt_secret_key'; // Replace with your actual secret key
try {
    $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
    debugLog('JWT decoded successfully: ' . json_encode($decoded));
} catch (Exception $e) {
    debugLog('JWT validation failed: ' . $e->getMessage());
    sendResponse(401, ['error' => 'Invalid token']);
}

// Database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=projet_medical', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    debugLog('Database connection established');
} catch (PDOException $e) {
    debugLog('Database connection failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Database connection failed']);
}

// Fetch the StudyInstanceUID for the patient
try {
    $stmt = $pdo->prepare('SELECT description FROM dicom_instances WHERE patient_id = ? LIMIT 1');
    $stmt->execute([$patientId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    debugLog('Query result for patient ' . $patientId . ': ' . json_encode($result));

    if (!$result || empty($result['description'])) {
        debugLog('No DICOM instance found for patient ' . $patientId);
        sendResponse(404, ['studyInstanceUID' => null]);
    }

    // Extract StudyInstanceUID from the description
    $description = $result['description'];
    if (preg_match('/StudyInstanceUID: (.*)/', $description, $matches)) {
        $studyInstanceUID = $matches[1];
        debugLog('StudyInstanceUID extracted: ' . $studyInstanceUID);
        sendResponse(200, ['studyInstanceUID' => $studyInstanceUID]);
    } else {
        debugLog('StudyInstanceUID not found in description: ' . $description);
        sendResponse(404, ['studyInstanceUID' => null]);
    }
} catch (Exception $e) {
    debugLog('Error fetching StudyInstanceUID: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to fetch StudyInstanceUID']);
}
?>