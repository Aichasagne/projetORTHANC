<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

// Fonction pour logger les messages
function debugLog($message) {
    $logFile = 'C:/xampp/htdocs/projet-medical/logs/debug_addPatient.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Fonction pour envoyer une réponse JSON standardisée
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

// Vérifier les headers d'autorisation
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');
debugLog('Authorization header: ' . $authHeader);

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $tokenMatches)) {
    debugLog('Missing or invalid Authorization header');
    sendResponse(401, ['error' => 'Token manquant']);
}

$token = $tokenMatches[1];
debugLog('Token extracted: ' . substr($token, 0, 20) . '...');

// Charger la bibliothèque Firebase JWT
$autoloadPath = 'C:/xampp/htdocs/projet-medical/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    debugLog('Autoload file not found at: ' . $autoloadPath);
    sendResponse(500, ['error' => 'Server error: Autoload file not found']);
}

require_once $autoloadPath;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Clé secrète pour JWT
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
if (!$userId) {
    debugLog('User ID not found in token');
    sendResponse(401, ['error' => 'Utilisateur non authentifié']);
}
debugLog("Utilisateur authentifié: userId=$userId");

// Connexion à la base de données avec PDO
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

// Vérifier le rôle de l'utilisateur
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        debugLog('Utilisateur non trouvé: userId=' . $userId);
        sendResponse(404, ['error' => 'Utilisateur non trouvé']);
    }

    $role = $user['role'];
    debugLog("Rôle de l'utilisateur: role=$role");

    if ($role !== 'medecin' && $role !== 'admin') {
        debugLog('Accès interdit: Rôle utilisateur = ' . $role);
        sendResponse(403, ['error' => 'Accès interdit: seuls les médecins et admins peuvent ajouter des patients']);
    }
} catch (PDOException $e) {
    debugLog('Error fetching user role: ' . $e->getMessage());
    sendResponse(500, ['error' => 'Failed to fetch user role']);
}

// Si l'utilisateur est un médecin, récupérer son medecin_id
$medecin_id = null;
if ($role === 'medecin') {
    try {
        $stmt = $pdo->prepare("SELECT id FROM medecins WHERE user_id = ?");
        $stmt->execute([$userId]);
        $medecin = $stmt->fetch();
        if (!$medecin) {
            debugLog('Médecin non trouvé pour user_id: ' . $userId);
            sendResponse(404, ['error' => 'Médecin non trouvé']);
        }
        $medecin_id = $medecin['id'];
        debugLog("Médecin identifié: medecin_id=$medecin_id");
    } catch (PDOException $e) {
        debugLog('Error fetching medecin_id: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to fetch medecin_id']);
    }
} else {
    debugLog("Utilisateur non médecin, aucun filtrage par medecin_id");
}

if ($method === 'POST') {
    // Récupérer les données JSON
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['nom'], $data['prenom'], $data['email'], $data['date_naissance'], $data['sexe'])) {
        debugLog('Données manquantes: ' . json_encode($data));
        sendResponse(400, ['error' => 'Données manquantes']);
    }

    // Validation des données
    $nom = $data['nom'];
    $prenom = $data['prenom'];
    $email = $data['email'];
    $date_naissance = $data['date_naissance'];
    $sexe = $data['sexe'];
    $telephone = isset($data['telephone']) ? $data['telephone'] : null;
    $adresse = isset($data['adresse']) ? $data['adresse'] : null;

    // Validation de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        debugLog('Email invalide: ' . htmlspecialchars($email));
        sendResponse(400, ['error' => 'Email invalide']);
    }

    // Vérifier si l'email existe déjà
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            debugLog('Email déjà utilisé: ' . htmlspecialchars($email));
            sendResponse(400, ['error' => 'Cet email est déjà utilisé']);
        }
    } catch (PDOException $e) {
        debugLog('Error checking email: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to check email']);
    }

    // Générer un mot de passe par défaut
    $defaultPassword = 'defaultPassword123'; // À modifier ou rendre configurable
    $hashedPassword = password_hash($defaultPassword, PASSWORD_BCRYPT);

    // Transaction pour garantir la cohérence
    try {
        $pdo->beginTransaction();

        // Insérer l'utilisateur dans la table users
        $stmt = $pdo->prepare("INSERT INTO users (nom, prenom, email, password, role, created_at) VALUES (?, ?, ?, ?, 'patient', NOW())");
        $stmt->execute([$nom, $prenom, $email, $hashedPassword]);
        $userId = $pdo->lastInsertId();
        debugLog("Utilisateur créé: userId=$userId");

        // Insérer le patient dans la table patients, en utilisant medecin_id directement
        $stmt = $pdo->prepare("INSERT INTO patients (user_id, date_naissance, sexe, telephone, adresse, medecin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $date_naissance, $sexe, $telephone, $adresse, $medecin_id]);
        $patientId = $pdo->lastInsertId();
        debugLog("Patient créé: patientId=$patientId, medecin_id=$medecin_id");

        // Récupérer les données du patient créé pour la réponse
        $stmt = $pdo->prepare("
            SELECT u.id, u.nom, u.prenom, u.email, p.date_naissance, p.sexe, p.telephone, p.adresse, p.id as patient_id 
            FROM users u 
            JOIN patients p ON p.user_id = u.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$patientId]);
        $newPatient = $stmt->fetch();

        $pdo->commit();
        debugLog('Transaction validée');

        sendResponse(201, [
            'id' => (int)$newPatient['id'],
            'patient_id' => (int)$newPatient['patient_id'],
            'nom' => $newPatient['nom'],
            'prenom' => $newPatient['prenom'],
            'email' => $newPatient['email'],
            'date_naissance' => $newPatient['date_naissance'],
            'sexe' => $newPatient['sexe'],
            'telephone' => $newPatient['telephone'],
            'adresse' => $newPatient['adresse'],
            'message' => 'Patient ajouté avec succès'
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        debugLog('Error adding patient: ' . $e->getMessage());
        sendResponse(500, ['error' => 'Failed to add patient: ' . $e->getMessage()]);
    }
} else {
    debugLog('Invalid request method: ' . $method);
    sendResponse(405, ['error' => 'Method Not Allowed']);
}