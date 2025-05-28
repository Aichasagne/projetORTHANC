<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once '../database.php';

// Charger la bibliothèque Firebase JWT
require '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

$secret_key = "votre_clé_secrète";

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

function logMessage($message) {
    file_put_contents('C:/xampp/htdocs/projet-medical/logs/debug_rendezvous.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

logMessage('Début de la requête /rendezvous');

$method = $_SERVER['REQUEST_METHOD'];
$headers = apache_request_headers();

if (!isset($headers['Authorization'])) {
    logMessage('Erreur: Token manquant');
    http_response_code(401);
    echo json_encode(['error' => 'Token manquant']);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
logMessage('Token reçu: ' . $token);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $user_id = $decoded->sub;
    logMessage("Utilisateur authentifié: user_id=$user_id");
} catch (Exception $e) {
    logMessage('Erreur: Token invalide - ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'Token invalide']);
    exit;
}

$db = new Database();
$conn = $db->connect();

if ($conn === null) {
    logMessage('Erreur: Impossible de se connecter à la base de données');
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données']);
    exit;
}

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    logMessage('Erreur: Utilisateur non trouvé');
    http_response_code(404);
    echo json_encode(['error' => 'Utilisateur non trouvé']);
    exit;
}

$role = $user['role'];
logMessage("Rôle de l'utilisateur: role=$role");

if (!in_array($role, ['medecin', 'admin', 'radiologue'])) {
    logMessage('Erreur: Accès refusé. Rôle non autorisé');
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé. Rôle non autorisé']);
    exit;
}

$medecin_id = null;
if ($role === 'medecin') {
    $stmt = $conn->prepare("SELECT id FROM medecins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $medecin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($medecin) {
        $medecin_id = $medecin['id'];
        logMessage("Médecin identifié: medecin_id=$medecin_id");
    }
}

try {
    if ($method === 'GET') {
        logMessage("Requête GET pour la liste des rendez-vous");

        $sql = "
            SELECT r.id, r.patient_id, r.date_rdv, r.motif, r.statut, 
                   p.user_id, u.nom, u.prenom
            FROM rendezvous r
            INNER JOIN patients p ON r.patient_id = p.id
            INNER JOIN users u ON p.user_id = u.id
        ";
        $params = [];
        if ($role === 'medecin' && $medecin_id) {
            $sql .= " WHERE r.medecin_id = :medecin_id AND r.statut = 'planifie'";
            $params[':medecin_id'] = $medecin_id;
        } else {
            $sql .= " WHERE r.statut = 'planifie'";
        }

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rendezvous = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'patient_id' => (int)$r['patient_id'],
                'user_id' => (int)$r['user_id'],
                'nom' => $r['nom'] ?? 'N/A',
                'prenom' => $r['prenom'] ?? 'N/A',
                'date_rdv' => $r['date_rdv'] ?? 'N/A',
                'motif' => $r['motif'] ?? 'N/A',
                'statut' => $r['statut'] ?? 'N/A'
            ];
        }, $rendezvous);

        logMessage("Rendez-vous trouvés: " . json_encode($result, JSON_PRETTY_PRINT));
        http_response_code(200);
        echo json_encode($result);
    } elseif ($method === 'POST') {
        logMessage("Requête POST pour planifier un rendez-vous");
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['patient_id']) || !isset($input['date_rdv']) || !isset($input['motif'])) {
            logMessage('Erreur: Données manquantes pour le rendez-vous');
            http_response_code(400);
            echo json_encode(['error' => 'Données manquantes (patient_id, date_rdv, motif requis)']);
            exit;
        }

        $patient_id = $input['patient_id'];
        $date_rdv = $input['date_rdv'];
        $motif = $input['motif'];
        $medecin_id = $role === 'medecin' ? $medecin_id : null;
        $statut = 'planifie';
        $created_at = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("INSERT INTO rendezvous (patient_id, medecin_id, date_rdv, motif, statut, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$patient_id, $medecin_id, $date_rdv, $motif, $statut, $created_at]);

        $rendezvous_id = $conn->lastInsertId();
        logMessage("Rendez-vous planifié avec ID: $rendezvous_id");
        http_response_code(201);
        echo json_encode(['id' => (int)$rendezvous_id, 'message' => 'Rendez-vous planifié avec succès']);
    } else {
        logMessage('Erreur: Méthode non prise en charge');
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non prise en charge']);
        exit;
    }
} catch (PDOException $e) {
    logMessage('Erreur PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de l\'exécution de la requête : ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    logMessage('Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : ' . $e->getMessage()]);
    exit;
} finally {
    $conn = null;
}