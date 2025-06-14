<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

// Récupération de patientId depuis GET ou POST
$patientIdFromQuery = isset($_GET['patientId']) ? (int)$_GET['patientId'] : null;
$patientIdFromBody = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    debugLog('Received JSON data: ' . print_r($input, true));
    $patientIdFromBody = $input['patientId'] ?? null;
}
$patientId = $patientIdFromQuery ?: $patientIdFromBody;

if ($patientId === null || $patientId <= 0) {
    debugLog('Invalid patientId: ' . ($patientId ?? 'null'));
    sendResponse(400, ['error' => 'ID patient invalide']);
}

// Vérification des droits d'accès
$queryCheck = "SELECT COUNT(*) FROM dossier WHERE patient_id = :patientId";
$stmtCheck = $pdo->prepare($queryCheck);
$stmtCheck->execute(['patientId' => $patientId]);
if ($stmtCheck->fetchColumn() == 0) {
    debugLog('No records found or user does not have access to patientId: ' . $patientId);
    sendResponse(403, ['error' => 'Accès non autorisé à ce patient']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $query = "
            SELECT 
                description AS descriptions,
                etat_sante,
                allergies,
                antecedents_familiaux,
                last_updated,
                groupe_sanguin,
                NULL AS nom_medecin
            FROM dossier
            WHERE patient_id = :patientId
            AND (description IS NOT NULL AND description != '' 
                 OR etat_sante IS NOT NULL AND etat_sante != '' 
                 OR allergies IS NOT NULL AND allergies != '' 
                 OR antecedents_familiaux IS NOT NULL AND antecedents_familiaux != '' 
                 OR groupe_sanguin IS NOT NULL AND groupe_sanguin != '')
            ORDER BY last_updated DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['patientId' => $patientId]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            // Si aucune entrée avec données n'est trouvée, prendre la dernière entrée
            $fallbackQuery = "
                SELECT 
                    description AS descriptions,
                    etat_sante,
                    allergies,
                    antecedents_familiaux,
                    last_updated,
                    groupe_sanguin,
                    NULL AS nom_medecin
                FROM dossier
                WHERE patient_id = :patientId
                ORDER BY last_updated DESC
                LIMIT 1
            ";
            $stmtFallback = $pdo->prepare($fallbackQuery);
            $stmtFallback->execute(['patientId' => $patientId]);
            $records = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);

            if (empty($records)) {
                debugLog('No records found for patientId: ' . $patientId);
                sendResponse(404, ['error' => 'Aucun dossier trouvé pour ce patient']);
            }
        }

        debugLog('Records found for patientId: ' . $patientId . ' - Data: ' . print_r($records[0], true));
        sendResponse(200, $records[0]);
    } catch (PDOException $e) {
        debugLog('Query failed: ' . $e->getMessage() . ' - Query: ' . $query);
        sendResponse(500, ['error' => 'Requête échouée : ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        debugLog('Received JSON data: ' . print_r($input, true));

        if (!$input || !isset($input['patientId']) || $input['patientId'] != $patientId) {
            debugLog('Invalid or mismatched patientId in POST data: ' . ($input['patientId'] ?? 'null'));
            sendResponse(400, ['error' => 'ID patient invalide']);
        }

        $descriptions = $input['descriptions'] ?? '';
        $etat_sante = $input['etat_sante'] ?? '';
        $allergies = $input['allergies'] ?? '';
        $antecedents_familiaux = $input['antecedents_familiaux'] ?? '';
        $groupe_sanguin = $input['groupe_sanguin'] ?? '';

        $query = "
            INSERT INTO dossier (patient_id, description, etat_sante, allergies, antecedents_familiaux, groupe_sanguin, last_updated)
            VALUES (:patientId, :descriptions, :etat_sante, :allergies, :antecedents_familiaux, :groupe_sanguin, NOW())
            ON DUPLICATE KEY UPDATE 
                description = VALUES(description),
                etat_sante = VALUES(etat_sante),
                allergies = VALUES(allergies),
                antecedents_familiaux = VALUES(antecedents_familiaux),
                groupe_sanguin = VALUES(groupe_sanguin),
                last_updated = VALUES(last_updated)
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'patientId' => $patientId,
            'descriptions' => $descriptions,
            'etat_sante' => $etat_sante,
            'allergies' => $allergies,
            'antecedents_familiaux' => $antecedents_familiaux,
            'groupe_sanguin' => $groupe_sanguin
        ]);

        debugLog('Update/Insert successful for patientId: ' . $patientId . ' - Data: ' . print_r($input, true));
        sendResponse(200, [
            'success' => true,
            'descriptions' => $descriptions,
            'etat_sante' => $etat_sante,
            'allergies' => $allergies,
            'antecedents_familiaux' => $antecedents_familiaux,
            'groupe_sanguin' => $groupe_sanguin
        ]);
    } catch (PDOException $e) {
        debugLog('Query failed: ' . $e->getMessage() . ' - Query: ' . $query);
        sendResponse(500, ['error' => 'Requête échouée : ' . $e->getMessage()]);
    } catch (Exception $e) {
        debugLog('General error: ' . $e->getMessage());
        sendResponse(400, ['error' => 'Données invalides : ' . $e->getMessage()]);
    }
}
?>