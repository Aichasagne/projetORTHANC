<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

$headers = getallheaders();
error_log("Headers reçus: " . json_encode($headers), 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
$authHeader = $headers['Authorization'] ?? '';
error_log("Authorization header brut: " . $authHeader, 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
$token = str_replace('Bearer ', '', $authHeader);
error_log("Token extrait: " . $token, 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing or invalid Authorization header']);
    exit;
}

function decodeJWT($token) {
    try {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("Invalid JWT structure: " . json_encode($parts), 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
            return false;
        }
        $payload = $parts[1];
        $padding = strlen($payload) % 4;
        if ($padding) $payload .= str_repeat('=', 4 - $padding);
        $decodedPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload), true);
        if ($decodedPayload === false) {
            error_log("Base64 decoding failed for payload: " . $payload, 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
            return false;
        }
        $jsonPayload = json_decode($decodedPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decoding failed: " . json_last_error_msg(), 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
            return false;
        }
        return $jsonPayload;
    } catch (Exception $e) {
        error_log("JWT decoding error: " . $e->getMessage(), 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
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
error_log("Utilisateur authentifié: userId=$userId, role=$role", 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');

$host = 'localhost';
$dbname = 'projet_medical';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("Database connection established", 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage(), 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$patientId = isset($_GET['patientId']) ? $_GET['patientId'] : null;
$consultationId = isset($_GET['consultationId']) ? $_GET['consultationId'] : null;

if ($method === 'GET') {
    if ($patientId || $consultationId) {
        if ($patientId) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND medecin_id = ?');
            $stmt->execute([$patientId, $userId]);
            $hasAccess = $stmt->fetchColumn();
            error_log("Accès patient: patientId=$patientId, userId=$userId, hasAccess=$hasAccess", 3, 'C:/xampp/htdocs/projet-medical/logs/debug_dicom_instances.log');

            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this patient']);
                exit;
            }
        }
        if ($consultationId) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE id = ? AND medecin_id = ?');
            $stmt->execute([$consultationId, $userId]);
            $hasAccess = $stmt->fetchColumn();
            if (!$hasAccess) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this consultation']);
                exit;
            }
        }

        $query = 'SELECT * FROM dicom_instances WHERE 1=1';
        $params = [];
        if ($patientId) {
            $query .= ' AND patient_id = ?';
            $params[] = $patientId;
        }
        if ($consultationId) {
            $query .= ' AND consultation_id = ?';
            $params[] = $consultationId;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $dicomInstances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extract StudyInstanceUID from description
        foreach ($dicomInstances as &$instance) {
            if (preg_match('/StudyInstanceUID:\s*([^\s]+)/', $instance['description'], $matches)) {
                $instance['study_instance_uid'] = $matches[1];
            }
        }

        echo json_encode(['dicom_instances' => $dicomInstances]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing patientId or consultationId']);
    }
} elseif ($method === 'POST') {
    if (!isset($_FILES['file']) || !isset($_POST['patient_id']) || !isset($_POST['instance_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing file or patient_id/instance_id']);
        exit;
    }

    $file = $_FILES['file'];
    $patientId = $_POST['patient_id'];
    $instanceId = $_POST['instance_id'];
    $consultationId = $_POST['consultation_id'] ?? null;
    $uploadDate = date('Y-m-d H:i:s');

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'File upload error']);
        exit;
    }

    $targetDir = 'C:/xampp/htdocs/projet-medical/uploads/';
    $targetFile = $targetDir . basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO dicom_instances (orthanc_instance_id, patient_id, consultation_id, file_path, upload_date, description) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$instanceId, $patientId, $consultationId, $targetFile, $uploadDate, 'Uploaded DICOM file']);

    $studyInstanceUID = 'TEMP_' . uniqid();
    echo json_encode(['studyInstanceUID' => $studyInstanceUID, 'message' => 'DICOM instance uploaded successfully']);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>