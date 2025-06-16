<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

function debugLog($message) {
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_rendezvous.log';
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

$jwtSecret = 'your_jwt_secret_key'; // Aligner avec la clé utilisée pour générer le token
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

try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        debugLog('Utilisateur non trouvé dans la base de données');
        sendResponse(404, ['error' => 'Utilisateur non trouvé']);
    }

    $role = $user['role'];
    debugLog("Rôle de l'utilisateur: role=$role");

    if (!in_array($role, ['medecin', 'admin', 'radiologue'])) {
        debugLog('Accès refusé. Rôle non autorisé');
        sendResponse(403, ['error' => 'Accès refusé. Rôle non autorisé']);
    }

    $medecinId = null;
    if ($role === 'medecin') {
        $stmt = $pdo->prepare("SELECT id FROM medecins WHERE user_id = ?");
        $stmt->execute([$userId]);
        $medecin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($medecin) {
            $medecinId = $medecin['id'];
            debugLog("Médecin identifié: medecinId=$medecinId");
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        debugLog("Requête GET pour la liste ou un rendez-vous spécifique");
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $stmt = $pdo->prepare("SELECT r.id, r.patient_id, r.medecin_id, r.date_rdv, r.motif, r.statut, r.lieu, r.duree, r.notes, r.type_rdv,
                                  p.user_id, u.nom, u.prenom
                                  FROM rendezvous r
                                  INNER JOIN patients p ON r.patient_id = p.id
                                  INNER JOIN users u ON p.user_id = u.id
                                  WHERE r.id = ?");
            $stmt->execute([$id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($appointment) {
                $result = [
                    'id' => (int)$appointment['id'],
                    'patient_id' => (int)$appointment['patient_id'],
                    'medecin_id' => (int)$appointment['medecin_id'],
                    'nom' => $appointment['nom'] ?? 'N/A',
                    'prenom' => $appointment['prenom'] ?? 'N/A',
                    'date_rdv' => $appointment['date_rdv'] ?? 'N/A',
                    'motif' => $appointment['motif'] ?? 'N/A',
                    'statut' => $appointment['statut'] ?? 'N/A',
                    'lieu' => $appointment['lieu'] ?? 'N/A',
                    'duree' => (int)$appointment['duree'],
                    'notes' => $appointment['notes'] ?? 'N/A',
                    'type_rdv' => $appointment['type_rdv'] ?? 'N/A'
                ];
                debugLog("Rendez-vous trouvé avec ID: $id");
                sendResponse(200, $result);
            } else {
                debugLog("Rendez-vous non trouvé avec ID: $id");
                sendResponse(404, ['error' => 'Rendez-vous non trouvé']);
            }
        } elseif (isset($_GET['checkConflict']) && isset($_GET['date_rdv']) && isset($_GET['patient_id'])) {
            $dateRdv = $_GET['date_rdv'];
            $patientId = $_GET['patient_id'];
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rendezvous WHERE date_rdv = ? AND patient_id != ? AND medecin_id = ?");
            $stmt->execute([$dateRdv, $patientId, $medecinId]);
            $conflict = $stmt->fetchColumn() > 0;
            debugLog("Vérification de conflit pour date $dateRdv, patient $patientId: " . ($conflict ? 'Conflit' : 'Pas de conflit'));
            sendResponse(200, ['conflict' => $conflict]);
        } else {
            $sql = "
                SELECT r.id, r.patient_id, r.medecin_id, r.date_rdv, r.motif, r.statut, r.lieu, r.duree, r.notes, r.type_rdv,
                       p.user_id, u.nom, u.prenom
                FROM rendezvous r
                INNER JOIN patients p ON r.patient_id = p.id
                INNER JOIN users u ON p.user_id = u.id
            ";
            $params = [];
            if ($role === 'medecin' && $medecinId) {
                $sql .= " WHERE r.medecin_id = :medecin_id";
                $params[':medecin_id'] = $medecinId;
            }
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();

            $rendezvous = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'patient_id' => (int)$r['patient_id'],
                    'medecin_id' => (int)$r['medecin_id'],
                    'nom' => $r['nom'] ?? 'N/A',
                    'prenom' => $r['prenom'] ?? 'N/A',
                    'date_rdv' => $r['date_rdv'] ?? 'N/A',
                    'motif' => $r['motif'] ?? 'N/A',
                    'statut' => $r['statut'] ?? 'N/A',
                    'lieu' => $r['lieu'] ?? 'N/A',
                    'duree' => (int)$r['duree'],
                    'notes' => $r['notes'] ?? 'N/A',
                    'type_rdv' => $r['type_rdv'] ?? 'N/A'
                ];
            }, $rendezvous);

            debugLog("Rendez-vous trouvés: " . json_encode($result, JSON_PRETTY_PRINT));
            sendResponse(200, $result);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        debugLog("Requête POST pour planifier un rendez-vous");
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['patient_id']) || !isset($input['date_rdv']) || !isset($input['motif'])) {
            debugLog('Données manquantes pour le rendez-vous');
            sendResponse(400, ['error' => 'Données manquantes (patient_id, date_rdv, motif requis)']);
        }

        $patientId = $input['patient_id'];
        $dateRdv = $input['date_rdv'];
        $motif = $input['motif'];
        $medecinId = $role === 'medecin' ? $medecinId : null;
        $statut = 'planifie';
        $lieu = $input['lieu'] ?? null;
        $duree = $input['duree'] ?? 30;
        $notes = $input['notes'] ?? null;
        $typeRdv = $input['type_rdv'] ?? 'consultation';
        $createdAt = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("INSERT INTO rendezvous (patient_id, medecin_id, date_rdv, motif, statut, lieu, duree, notes, type_rdv, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$patientId, $medecinId, $dateRdv, $motif, $statut, $lieu, $duree, $notes, $typeRdv, $createdAt]);

        $rendezvousId = $pdo->lastInsertId();
        debugLog("Rendez-vous planifié avec ID: $rendezvousId");
        sendResponse(201, ['id' => (int)$rendezvousId, 'message' => 'Rendez-vous planifié avec succès']);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        debugLog("Requête PUT pour mettre à jour un rendez-vous");
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $_GET['id'];

        if (!isset($input['patient_id']) || !isset($input['date_rdv']) || !isset($input['motif']) || !$id) {
            debugLog('Données manquantes pour la mise à jour');
            sendResponse(400, ['error' => 'Données manquantes (id, patient_id, date_rdv, motif requis)']);
        }

        $patientId = $input['patient_id'];
        $dateRdv = $input['date_rdv'];
        $motif = $input['motif'];
        $medecinId = $role === 'medecin' ? $medecinId : null;
        $statut = $input['statut'] ?? 'planifie'; // Add statut from input with default
        $lieu = $input['lieu'] ?? null;
        $duree = $input['duree'] ?? 30;
        $notes = $input['notes'] ?? null;
        $typeRdv = $input['type_rdv'] ?? 'consultation';

        $stmt = $pdo->prepare("UPDATE rendezvous SET patient_id = ?, medecin_id = ?, date_rdv = ?, motif = ?, statut = ?, lieu = ?, duree = ?, notes = ?, type_rdv = ? WHERE id = ?");
        $stmt->execute([$patientId, $medecinId, $dateRdv, $motif, $statut, $lieu, $duree, $notes, $typeRdv, $id]);

        if ($stmt->rowCount() > 0) {
            debugLog("Rendez-vous mis à jour avec ID: $id, new statut: $statut");
            sendResponse(200, ['message' => 'Rendez-vous mis à jour avec succès']);
        } else {
            debugLog("Aucun rendez-vous trouvé pour mise à jour avec ID: $id");
            sendResponse(404, ['error' => 'Rendez-vous non trouvé']);
        }
    } else {
        debugLog('Méthode non prise en charge');
        sendResponse(405, ['error' => 'Méthode non prise en charge']);
    }
} catch (PDOException $e) {
    debugLog('Query failed: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Requête échouée : ' . $e->getMessage()]);
} catch (Exception $e) {
    debugLog('General error: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Erreur serveur : ' . $e->getMessage()]);
}
?>