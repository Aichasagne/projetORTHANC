<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once '../config/database.php';

// Vérifier si l'utilisateur est authentifié (via le token JWT)
require_once '../middleware/auth.php';
$user = verifyToken();

if (!$user) {
    echo json_encode(['error' => 'Non autorisé']);
    http_response_code(401);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? $_GET['path'] : '';
$pathParts = explode('/', $path);

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'PUT' && count($pathParts) === 2 && $pathParts[1] === 'archive') {
    $patientId = $pathParts[0];
    $data = json_decode(file_get_contents('php://input'), true);
    $archived = $data['archived'] ?? null;

    if ($archived === null || !in_array($archived, [0, 1])) {
        echo json_encode(['error' => 'Valeur archived invalide']);
        http_response_code(400);
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE patients SET archived = ? WHERE id = ? AND medecin_id = ?');
    $stmt->execute([$archived, $patientId, $user['id']]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['message' => 'Patient mis à jour']);
        http_response_code(200);
    } else {
        echo json_encode(['error' => 'Patient non trouvé ou non autorisé']);
        http_response_code(404);
    }
    exit;
}

echo json_encode(['error' => 'Requête invalide']);
http_response_code(400);
?>