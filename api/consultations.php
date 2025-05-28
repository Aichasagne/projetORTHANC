<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

// Activer le débogage dans les logs, mais pas dans la réponse
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

function debugLog($message) {
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_consultations.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function sendResponse($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
debugLog('Request method: ' . $method);

if ($method !== 'GET') {
    debugLog('Invalid request method: ' . $method);
    sendResponse(405, ['error' => 'Method Not Allowed']);
}

$patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : null;
debugLog('Patient ID extracted from query: ' . $patientId);
if (!$patientId) {
    debugLog('Missing patientId in query parameters');
    sendResponse(400, ['error' => 'Missing patientId']);
}

$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant']);
}

$token = $tokenMatches[1];
require_once 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwtSecret = 'your_jwt_secret_key'; // Remplacez par votre vraie clé secrète
try {
    $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
    if ($decoded->exp < time()) {
        debugLog('Token has expired');
        sendResponse(401, ['error' => 'Session expirée']);
    }
} catch (Exception $e) {
    debugLog('JWT validation failed: ' . $e->getMessage());
    sendResponse(401, ['error' => 'Token invalide']);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=projet_medical', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
    ]);
} catch (PDOException $e) {
    debugLog('Database connection failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Database connection failed']);
}

// Vérifier si le patient existe
try {
    $stmt = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
    $stmt->execute([$patientId]);
    if (!$stmt->fetch()) {
        debugLog('Patient not found: ' . $patientId);
        sendResponse(404, ['error' => 'Patient not found']);
    }
} catch (PDOException $e) {
    debugLog('Error checking patient existence: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to verify patient']);
}

try {
    // Requête corrigée : remplacer 'date' par 'date_consultation' et 'diagnosis' par 'notes'
    $stmt = $pdo->prepare('SELECT id, date_consultation, notes FROM consultations WHERE patient_id = ?');
    $stmt->execute([$patientId]);
    $consultations = $stmt->fetchAll();
    debugLog('Consultations fetched for patient ' . $patientId . ': ' . json_encode($consultations));

    if (empty($consultations)) {
        debugLog('No consultations found for patient ' . $patientId);
        sendResponse(200, []);
    }

    sendResponse(200, $consultations);
} catch (PDOException $e) {
    debugLog('Error fetching consultations: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to fetch consultations']);
}
?>