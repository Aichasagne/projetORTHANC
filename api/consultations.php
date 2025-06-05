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

// Gestion des requêtes OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    debugLog('Requête OPTIONS reçue, réponse 204');
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
debugLog('Request method: ' . $method);

// Vérifier les headers d'autorisation pour toutes les méthodes sauf OPTIONS
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');
debugLog('Authorization header: ' . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant']);
}

$token = $tokenMatches[1];
debugLog('Token extracted: ' . $token);

$autoloadPath = 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    debugLog('Autoload file not found at: ' . $autoloadPath);
    sendResponse(500, ['error' => 'Server error: Autoload file not found']);
}

require_once $autoloadPath;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwtSecret = 'your_jwt_secret_key';
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

if ($method === 'GET') {
    $patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : null;
    if (!$patientId) {
        debugLog('Missing patientId for GET request');
        sendResponse(400, ['error' => 'Patient ID manquant']);
    }

    try {
        // Récupérer les consultations
        $stmt = $pdo->prepare('
            SELECT c.id, c.patient_id, c.medecin_id, c.date_consultation, c.diagnostic, 
                   p.id AS prescription_id, p.details AS prescription_details, p.created_at AS prescription_date
            FROM consultations c
            LEFT JOIN prescriptions p ON c.id = p.consultation_id
            WHERE c.patient_id = ?
            ORDER BY c.date_consultation DESC
        ');
        $stmt->execute([$patientId]);
        $consultations = $stmt->fetchAll();

        // Récupérer les fichiers DICOM associés
        $stmt = $pdo->prepare('
            SELECT di.dicom_instance_id AS id, di.consultation_id, di.orthanc_instance_id, di.upload_date, di.study_date, di.description
            FROM dicom_instances di
            JOIN consultations c ON di.consultation_id = c.id
            WHERE c.patient_id = ?
            ORDER BY di.upload_date DESC
        ');
        $stmt->execute([$patientId]);
        $dicomFiles = $stmt->fetchAll();

        // Structurer les données
        $result = [
            'consultations' => [],
            'dicom_files' => $dicomFiles
        ];

        // Regrouper les prescriptions par consultation
        if (!empty($consultations)) {
            foreach ($consultations as $consultation) {
                $consultationId = $consultation['id'];
                if (!isset($result['consultations'][$consultationId])) {
                    $result['consultations'][$consultationId] = [
                        'id' => $consultation['id'],
                        'patient_id' => $consultation['patient_id'],
                        'medecin_id' => $consultation['medecin_id'],
                        'date_consultation' => $consultation['date_consultation'],
                        'diagnostic' => $consultation['diagnostic'],
                        'prescriptions' => []
                    ];
                }
                if ($consultation['prescription_id']) {
                    $result['consultations'][$consultationId]['prescriptions'][] = [
                        'id' => $consultation['prescription_id'],
                        'details' => $consultation['prescription_details'],
                        'created_at' => $consultation['prescription_date']
                    ];
                }
            }
            $result['consultations'] = array_values($result['consultations']);
        }

        sendResponse(200, $result);
    } catch (PDOException $e) {
        debugLog('Error fetching consultations: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to fetch consultations: ' . $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    $patientId = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
    $dateConsultation = isset($_POST['date_consultation']) ? $_POST['date_consultation'] : null;
    $diagnostic = isset($_POST['diagnostic']) ? $_POST['diagnostic'] : null;
    $prescription = isset($_POST['prescription']) ? $_POST['prescription'] : null;

    if (!$patientId || !$dateConsultation || !$diagnostic) {
        debugLog('Missing required fields: patient_id=' . $patientId . ', date_consultation=' . $dateConsultation . ', diagnostic=' . $diagnostic);
        sendResponse(400, ['error' => 'Missing required fields']);
    }

    try {
        $pdo->beginTransaction();

        // Insérer la consultation
        $stmt = $pdo->prepare('INSERT INTO consultations (patient_id, medecin_id, date_consultation, diagnostic) VALUES (?, ?, ?, ?)');
        $stmt->execute([$patientId, 1, $dateConsultation, $diagnostic]);
        $consultationId = $pdo->lastInsertId();
        debugLog('Consultation ajoutée avec ID: ' . $consultationId);

        // Insérer la prescription si fournie
        if ($prescription) {
            $stmt = $pdo->prepare('INSERT INTO prescriptions (consultation_id, details, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$consultationId, $prescription]);
            debugLog('Prescription ajoutée pour consultation ID: ' . $consultationId);
        }

        // Gérer l'upload du fichier DICOM si présent
      if (isset($_FILES['file'])) {
    debugLog('$_FILES content: ' . json_encode($_FILES));
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        debugLog('Upload error code: ' . $_FILES['file']['error']);
        throw new Exception('Erreur lors de l\'upload du fichier: ' . $_FILES['file']['error']);
    }
    $fileTmpPath = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];
    $timestamp = date('YmdHis');
    $instanceId = "PATIENT-{$patientId}-{$timestamp}";

    // Vérifier si le fichier est un DICOM valide (extension .dcm)
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExt !== 'dcm') {
        throw new Exception('Type de fichier invalide. Seuls les fichiers .dcm sont acceptés.');
    }

    // Envoyer à proxy-orthanc.php avec CURLFile
    $ch = curl_init('http://localhost/projet-medical/api/proxy-orthanc.php?path=instances');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token
    ]);

    $cfile = new CURLFile($fileTmpPath, 'application/dicom', $fileName);
    $postData = ['file' => $cfile, 'patient_id' => $patientId, 'instance_id' => $instanceId];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    debugLog('Réponse de proxy-orthanc.php: HTTP ' . $httpCode . ' - ' . $response);
    if ($httpCode !== 200) {
        throw new Exception('Erreur lors de l\'upload DICOM via proxy-orthanc.php: HTTP ' . $httpCode . ' - ' . ($curlError ?: $response));
    }

    $dicomResult = json_decode($response, true);
    if (isset($dicomResult['ID']) && isset($dicomResult['studyInstanceUID'])) {
        // Stocker le StudyInstanceUID dans la colonne description
        $description = "StudyInstanceUID: {$dicomResult['studyInstanceUID']}";
        $stmt = $pdo->prepare('INSERT INTO dicom_instances (consultation_id, orthanc_instance_id, patient_id, upload_date, description) VALUES (?, ?, ?, NOW(), ?)');
        $stmt->execute([$consultationId, $dicomResult['ID'], $patientId, $description]);
        debugLog('DICOM ajouté avec orthanc_instance_id: ' . $dicomResult['ID']);
    } else {
        throw new Exception('Réponse Orthanc invalide, ID ou StudyInstanceUID manquant: ' . $response);
    }
}

        $pdo->commit();
        sendResponse(200, ['id' => $consultationId, 'message' => 'Consultation ajoutée avec succès']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        debugLog('Error inserting consultation: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to add consultation: ' . $e->getMessage()]);
    } catch (Exception $e) {
        $pdo->rollBack();
        debugLog('Error uploading DICOM: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to add consultation: ' . $e->getMessage()]);
    }
} else {
    debugLog('Invalid request method: ' . $method);
    sendResponse(405, ['error' => 'Method Not Allowed']);
}
?>