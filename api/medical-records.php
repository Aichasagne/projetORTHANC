<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    debugLog('Requête OPTIONS reçue, réponse 204');
    http_response_code(204);
    exit;
}

$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');
debugLog('Authorization header: ' . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant']);
}

$token = $tokenMatches[1];
debugLog('Token extracted: ' . substr($token, 0, 20) . '...');

$autoloadPath = 'C:/xampp/htdocs/projet-medical/api/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    debugLog('Autoload file not found at: ' . $autoloadPath);
    sendResponse(500, ['error' => 'Server error: Autoload file not found']);
}

require_once $autoloadPath;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Clé secrète alignée avec addPatient.php
$jwtSecret = 'your_jwt_secret_key'; // Mettre à jour avec la même clé
try {
    $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
    if ($decoded->exp < time()) {
        debugLog('Token has expired');
        sendResponse(401, ['error' => 'Session expirée']);
    }
} catch (Exception $e) {
    debugLog('JWT validation failed: ' . $e->getMessage() . ' - Token (partial): ' . substr($token, 0, 20) . '...');
    sendResponse(401, ['error' => 'Token invalide: ' . $e->getMessage()]);
}

$userId = $decoded->data->id ?? null;
if (!$userId) {
    debugLog('User ID not found in token');
    sendResponse(401, ['error' => 'Utilisateur non authentifié']);
}
debugLog("Utilisateur authentifié: userId=$userId");

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

$patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : 0;

if ($patientId <= 0) {
    debugLog('Invalid patientId: ' . $patientId);
    sendResponse(400, ['error' => 'ID patient invalide']);
}

try {
    $query = "
        SELECT 
            vdp.descriptions,
            vdp.etat_sante,
            vdp.allergies,
            vdp.antecedents_familiaux,
            vdp.last_updated,
            vdp.groupe_sanguin,
            CONCAT(u.nom, ' ', u.prenom) AS nom_medecin
        FROM vue_dossier_patient vdp
        LEFT JOIN users u ON u.id = (SELECT medecin_id FROM medical_records WHERE patient_id = :patientId LIMIT 1)
        WHERE vdp.patient_id = :patientId
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['patientId' => $patientId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        debugLog('No records found for patientId: ' . $patientId);
        sendResponse(404, ['error' => 'Aucun dossier trouvé pour ce patient']);
    } else {
        debugLog('Records found for patientId: ' . $patientId);
        sendResponse(200, $records[0]);
    }
} catch (PDOException $e) {
    debugLog('Query failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Requête échouée : ' . $e->getMessage()]);
}
?>