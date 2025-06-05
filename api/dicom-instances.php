<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Désactiver l'affichage des erreurs pour éviter les sorties HTML
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

function debugLog($message) {
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function sendResponse($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Vérifier l'en-tête d'autorisation
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
debugLog('Authorization header brut: ' . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant ou invalide']);
}

$token = $tokenMatches[1];
debugLog('Token extrait: ' . $token);

// Charger la bibliothèque JWT
$autoloadPath = 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    debugLog('Autoload file not found at: ' . $autoloadPath);
    sendResponse(500, ['error' => 'Server error: Autoload file not found']);
}

require_once $autoloadPath;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwtSecret = 'your_jwt_secret_key'; // Remplacez par votre clé secrète réelle
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

$userId = $decoded->data->id ?? null;
$role = $decoded->data->role ?? null;
if (!$userId || $role !== 'medecin') {
    debugLog('Unauthorized user: userId=' . $userId . ', role=' . $role);
    sendResponse(401, ['error' => 'Utilisateur non autorisé']);
}
debugLog("Utilisateur authentifié: userId=$userId, role=$role");

// Connexion à la base de données
$host = 'localhost';
$dbname = 'projet_medical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    debugLog('Database connection established');
} catch (PDOException $e) {
    debugLog('Database connection failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Erreur de connexion à la base de données']);
}

// Gestion des requêtes
$method = $_SERVER['REQUEST_METHOD'];
$patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : null;
$consultationId = isset($_GET['consultationId']) ? (int)$_GET['consultationId'] : null;

if ($method === 'GET') {
    if (!$patientId && !$consultationId) {
        debugLog('Missing patientId or consultationId');
        sendResponse(400, ['error' => 'Missing patientId or consultationId']);
    }

    // Vérification des permissions
    if ($patientId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND medecin_id = ?');
        $stmt->execute([$patientId, $userId]);
        if ($stmt->fetchColumn() == 0) {
            debugLog('Access denied to patientId=' . $patientId);
            sendResponse(403, ['error' => 'Accès refusé à ce patient']);
        }
    } elseif ($consultationId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE id = ? AND medecin_id = ?');
        $stmt->execute([$consultationId, $userId]);
        if ($stmt->fetchColumn() == 0) {
            debugLog('Access denied to consultationId=' . $consultationId);
            sendResponse(403, ['error' => 'Accès refusé à cette consultation']);
        }
    }

    // Récupérer les instances DICOM
    $query = 'SELECT dicom_instance_id, orthanc_instance_id, patient_id, consultation_id, upload_date, study_date, description FROM dicom_instances WHERE 1=1';
    $params = [];
    if ($patientId) {
        $query .= ' AND patient_id = ?';
        $params[] = $patientId;
    }
    if ($consultationId) {
        $query .= ' AND consultation_id = ?';
        $params[] = $consultationId;
    }

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $dicomInstances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extraire StudyInstanceUID de description
        foreach ($dicomInstances as &$instance) {
            if (preg_match('/StudyInstanceUID:\s*([^\s]+)/', $instance['description'], $matches)) {
                $instance['study_instance_uid'] = $matches[1];
            } else {
                $instance['study_instance_uid'] = null;
            }
        }

        sendResponse(200, ['dicom_instances' => $dicomInstances]);
    } catch (PDOException $e) {
        debugLog('SQL error: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Erreur lors de la récupération des instances DICOM: ' . $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    debugLog('POST method not supported directly in dicom_instances.php');
    sendResponse(405, ['error' => 'Method not supported. Use consultations.php for DICOM uploads']);
} else {
    debugLog('Invalid request method: ' . $method);
    sendResponse(405, ['error' => 'Method not allowed']);
}
?>