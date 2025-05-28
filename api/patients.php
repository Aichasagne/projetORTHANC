<?php
ob_start(); // Empêche toute sortie avant la réponse JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Inclure la classe Database et les classes User et Patient
require_once '../database.php';
require_once '../user.php';
require_once '../patient.php';

// Charger la bibliothèque Firebase JWT
require '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// Clé secrète pour JWT
$secret_key = "votre_clé_secrète";

// Désactiver l'affichage des erreurs dans la réponse (les erreurs seront loguées)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log errors to a file instead of displaying them
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/htdocs/projet-medical/logs/php_errors.log');

// Fonction pour logger les messages
function logMessage($message) {
    file_put_contents('C:/xampp/htdocs/projet-medical/logs/debug_patients.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

logMessage('Début de la requête /patients');
logMessage('Version de patients.php: 2025-05-07 avec corrections pour la méthode PUT');

// Vérification de la méthode HTTP
$method = $_SERVER['REQUEST_METHOD'];

// Récupérer l'URL pour extraire l'ID du patient (si applicable)
$request = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));

// Vérification du token
$headers = apache_request_headers();
if (!isset($headers['Authorization'])) {
    logMessage('Erreur: Token manquant');
    http_response_code(401);
    echo json_encode(['error' => 'Token manquant']);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
logMessage('Token reçu: ' . $token);

// Décoder le token pour obtenir l'user_id
try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $user_id = $decoded->data->id;
    logMessage("Utilisateur authentifié: user_id=$user_id");
} catch (Exception $e) {
    logMessage('Erreur: Token invalide - ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'Token invalide']);
    exit;
}

// Connexion à la base de données
$db = new Database();
$conn = $db->connect();

if ($conn === null) {
    logMessage('Erreur: Impossible de se connecter à la base de données');
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données']);
    exit;
}

// Vérifier le rôle de l'utilisateur
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

// Si l'utilisateur est un médecin, récupérer son medecin_id
$medecin_id = null;
if ($role === 'medecin') {
    $stmt = $conn->prepare("SELECT id FROM medecins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $medecin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$medecin) {
        logMessage('Erreur: Médecin non trouvé pour user_id: ' . $user_id);
        http_response_code(404);
        echo json_encode(['error' => 'Médecin non trouvé']);
        exit;
    }
    $medecin_id = $medecin['id'];
    logMessage("Médecin identifié: medecin_id=$medecin_id");
} else {
    logMessage("Utilisateur non médecin, aucun filtrage par medecin_id");
}

try {
    if ($method === 'GET' && !isset($request[1])) {
        // GET /api/patients - Liste des patients
        logMessage("Requête GET pour la liste des patients");

        // Construire la requête SQL
        $sql = "
            SELECT p.id, p.user_id, p.date_naissance, p.sexe, p.telephone, p.adresse, p.created_at, 
                   u.nom, u.prenom, u.email
            FROM patients p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.archived = 0 AND u.role = 'patient'
        ";

        // Ajouter un filtrage pour les médecins uniquement si nécessaire
        $params = [];
        if ($role === 'medecin') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM medecin_patient mp 
                WHERE mp.patient_id = p.id AND mp.medecin_id = :medecin_id
            )";
            $params[':medecin_id'] = $medecin_id;
        }

        logMessage("Requête SQL préparée: $sql");
        logMessage("Paramètres: " . json_encode($params));

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logMessage("Résultats bruts de la requête: " . json_encode($patients, JSON_PRETTY_PRINT));

        // Construire la réponse
        $result = [];
        foreach ($patients as $patient) {
            $patientData = [
                'id' => (int)$patient['id'],
                'user_id' => (int)$patient['user_id'],
                'date_naissance' => $patient['date_naissance'] ?? 'N/A',
                'sexe' => $patient['sexe'] ?? 'N/A',
                'telephone' => $patient['telephone'] ?? null,
                'adresse' => $patient['adresse'] ?? null,
                'created_at' => $patient['created_at'] ?? null,
                'nom' => $patient['nom'] ?? 'N/A',
                'prenom' => $patient['prenom'] ?? 'N/A',
                'email' => $patient['email'] ?? 'N/A'
            ];
            $result[] = $patientData;
            logMessage("Patient traité: id=" . $patientData['id']);
        }

        logMessage("Patients après traitement: " . json_encode($result, JSON_PRETTY_PRINT));
        http_response_code(200);
        echo json_encode($result);
    } elseif ($method === 'GET' && isset($request[1])) {
        // GET /api/patients/{patientId}
        $patientId = $request[1];
        logMessage("Requête GET pour id: $patientId");

        $sql = "
            SELECT p.id, p.user_id, p.date_naissance, p.sexe, p.telephone, p.adresse, p.created_at, 
                   u.nom, u.prenom, u.email
            FROM patients p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.id = :patient_id AND p.archived = 0 AND u.role = 'patient'
        ";

        if ($role === 'medecin') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM medecin_patient mp 
                WHERE mp.patient_id = p.id AND mp.medecin_id = :medecin_id
            )";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
        if ($role === 'medecin') {
            $stmt->bindParam(':medecin_id', $medecin_id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$patient) {
            logMessage("Patient non trouvé: id=$patientId");
            http_response_code(404);
            echo json_encode(['error' => 'Patient non trouvé']);
            exit;
        }

        $patientData = [
            'id' => (int)$patient['id'],
            'user_id' => (int)$patient['user_id'],
            'date_naissance' => $patient['date_naissance'] ?? 'N/A',
            'sexe' => $patient['sexe'] ?? 'N/A',
            'telephone' => $patient['telephone'] ?? null,
            'adresse' => $patient['adresse'] ?? null,
            'created_at' => $patient['created_at'] ?? null,
            'nom' => $patient['nom'] ?? 'N/A',
            'prenom' => $patient['prenom'] ?? 'N/A',
            'email' => $patient['email'] ?? 'N/A'
        ];

        logMessage("Patient renvoyé: " . json_encode($patientData, JSON_PRETTY_PRINT));
        http_response_code(200);
        echo json_encode($patientData);
    } elseif ($method === 'POST') {
        // POST /api/patients - Créer un nouveau patient
        logMessage("Requête POST pour créer un patient");

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            logMessage('Erreur: Données JSON invalides');
            http_response_code(400);
            echo json_encode(['error' => 'Données JSON invalides']);
            exit;
        }

        logMessage("Données reçues: " . json_encode($data, JSON_PRETTY_PRINT));

        $required_fields = ['nom', 'prenom', 'email', 'date_naissance', 'sexe'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                logMessage("Erreur: Champ requis manquant - $field");
                http_response_code(400);
                echo json_encode(['error' => "Le champ $field est requis"]);
                exit;
            }
        }

        $nom = $data['nom'];
        $prenom = $data['prenom'];
        $email = $data['email'];
        $date_naissance = $data['date_naissance'];
        $sexe = $data['sexe'];
        $telephone = $data['telephone'] ?? null;
        $adresse = $data['adresse'] ?? null;

        // Validation de l'email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            logMessage("Erreur: Email invalide - $email");
            http_response_code(400);
            echo json_encode(['error' => 'Email invalide']);
            exit;
        }

        // Vérifier si l'email existe déjà
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            logMessage("Erreur: Email déjà utilisé - $email");
            http_response_code(400);
            echo json_encode(['error' => 'Cet email est déjà utilisé']);
            exit;
        }

        // Créer un nouvel utilisateur
        $conn->beginTransaction();
        try {
            $password = password_hash('defaultpassword', PASSWORD_BCRYPT); // Mot de passe par défaut
            $stmt = $conn->prepare("
                INSERT INTO users (nom, prenom, email, password, role, created_at)
                VALUES (?, ?, ?, ?, 'patient', NOW())
            ");
            $stmt->execute([$nom, $prenom, $email, $password]);
            $new_user_id = $conn->lastInsertId();
            logMessage("Utilisateur créé: user_id=$new_user_id");

            // Créer un nouveau patient
            $stmt = $conn->prepare("
                INSERT INTO patients (user_id, date_naissance, sexe, telephone, adresse, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$new_user_id, $date_naissance, $sexe, $telephone, $adresse]);
            $patient_id = $conn->lastInsertId();
            logMessage("Patient créé: patient_id=$patient_id");

            // Si l'utilisateur est un médecin, associer le patient
            if ($role === 'medecin') {
                $stmt = $conn->prepare("
                    INSERT INTO medecin_patient (medecin_id, patient_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$medecin_id, $patient_id]);
                logMessage("Association medecin-patient créée: medecin_id=$medecin_id, patient_id=$patient_id");
            }

            $conn->commit();
            logMessage("Transaction validée");

            $patientData = [
                'id' => (int)$patient_id,
                'user_id' => (int)$new_user_id,
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'date_naissance' => $date_naissance,
                'sexe' => $sexe,
                'telephone' => $telephone,
                'adresse' => $adresse,
                'created_at' => date('Y-m-d H:i:s')
            ];

            logMessage("Patient créé avec succès: " . json_encode($patientData, JSON_PRETTY_PRINT));
            http_response_code(201);
            echo json_encode($patientData);
        } catch (Exception $e) {
            $conn->rollBack();
            logMessage('Erreur lors de la création: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la création du patient: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($method === 'PUT' && isset($request[1])) {
        // PUT /api/patients/{patientId} - Mettre à jour un patient
        $patientId = $request[1];
        logMessage("Requête PUT pour id: $patientId");

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            logMessage('Erreur: Données JSON invalides');
            http_response_code(400);
            echo json_encode(['error' => 'Données JSON invalides']);
            exit;
        }

        logMessage("Données reçues pour mise à jour: " . json_encode($data, JSON_PRETTY_PRINT));

        $required_fields = ['nom', 'prenom', 'email', 'date_naissance', 'sexe'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                logMessage("Erreur: Champ requis manquant - $field");
                http_response_code(400);
                echo json_encode(['error' => "Le champ $field est requis"]);
                exit;
            }
        }

        // Assigner les valeurs à des variables pour bindParam
        $nom = $data['nom'];
        $prenom = $data['prenom'];
        $email = $data['email'];
        $date_naissance = $data['date_naissance'];
        $sexe = $data['sexe'];
        $telephone = isset($data['telephone']) ? $data['telephone'] : null;
        $adresse = isset($data['adresse']) ? $data['adresse'] : null;

        // Vérifier si le patient existe et récupérer son user_id
        $stmt = $conn->prepare("
            SELECT p.user_id
            FROM patients p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.id = :patient_id AND p.archived = 0 AND u.role = 'patient'
        ");
        if ($role === 'medecin') {
            $stmt = $conn->prepare("
                SELECT p.user_id
                FROM patients p
                INNER JOIN users u ON p.user_id = u.id
                INNER JOIN medecin_patient mp ON p.id = mp.patient_id
                WHERE p.id = :patient_id AND mp.medecin_id = :medecin_id AND p.archived = 0 AND u.role = 'patient'
            ");
            $stmt->bindParam(':medecin_id', $medecin_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
        $stmt->execute();
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            logMessage("Patient non trouvé: id=$patientId");
            http_response_code(404);
            echo json_encode(['error' => 'Patient non trouvé']);
            exit;
        }

        $patient_user_id = $patient['user_id'];

        // Vérifier si l'email est déjà utilisé par un autre utilisateur
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $patient_user_id]);
        if ($stmt->fetch()) {
            logMessage("Erreur: Email déjà utilisé - $email");
            http_response_code(400);
            echo json_encode(['error' => 'Cet email est déjà utilisé par un autre utilisateur']);
            exit;
        }

        // Mettre à jour les données
        $conn->beginTransaction();
        try {
            // Mettre à jour la table users
            $stmt = $conn->prepare("
                UPDATE users
                SET nom = ?, prenom = ?, email = ?
                WHERE id = ?
            ");
            $stmt->execute([$nom, $prenom, $email, $patient_user_id]);
            logMessage("Utilisateur mis à jour: user_id=$patient_user_id");

            // Mettre à jour la table patients
            $stmt = $conn->prepare("
                UPDATE patients
                SET date_naissance = ?, sexe = ?, telephone = ?, adresse = ?
                WHERE id = ?
            ");
            $stmt->bindParam(1, $date_naissance);
            $stmt->bindParam(2, $sexe);
            $stmt->bindParam(3, $telephone, $telephone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(4, $adresse, $adresse === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(5, $patientId, PDO::PARAM_INT);
            $stmt->execute();
            $affectedRows = $stmt->rowCount();
            logMessage("Patient mis à jour: patient_id=$patientId, lignes affectées=$affectedRows");

            $conn->commit();
            logMessage("Transaction validée");

            // Récupérer les données mises à jour pour la réponse
            $stmt = $conn->prepare("
                SELECT p.id, p.user_id, p.date_naissance, p.sexe, p.telephone, p.adresse, p.created_at, 
                       u.nom, u.prenom, u.email
                FROM patients p
                INNER JOIN users u ON p.user_id = u.id
                WHERE p.id = ?
            ");
            $stmt->execute([$patientId]);
            $updatedPatient = $stmt->fetch(PDO::FETCH_ASSOC);

            $patientData = [
                'id' => (int)$updatedPatient['id'],
                'user_id' => (int)$updatedPatient['user_id'],
                'date_naissance' => $updatedPatient['date_naissance'] ?? 'N/A',
                'sexe' => $updatedPatient['sexe'] ?? 'N/A',
                'telephone' => $updatedPatient['telephone'] ?? null,
                'adresse' => $updatedPatient['adresse'] ?? null,
                'created_at' => $updatedPatient['created_at'] ?? null,
                'nom' => $updatedPatient['nom'] ?? 'N/A',
                'prenom' => $updatedPatient['prenom'] ?? 'N/A',
                'email' => $updatedPatient['email'] ?? 'N/A'
            ];

            logMessage("Patient mis à jour renvoyé: " . json_encode($patientData, JSON_PRETTY_PRINT));
            http_response_code(200);
            echo json_encode($patientData);
        } catch (Exception $e) {
            $conn->rollBack();
            logMessage('Erreur lors de la mise à jour: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la mise à jour du patient: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($method === 'DELETE' && isset($request[1])) {
        // DELETE /api/patients/{patientId} - Supprimer un patient (archivage logique)
        $patientId = $request[1];
        logMessage("Requête DELETE pour id: $patientId");

        // Vérifier si le patient existe
        $stmt = $conn->prepare("
            SELECT p.user_id
            FROM patients p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.id = :patient_id AND p.archived = 0 AND u.role = 'patient'
        ");
        if ($role === 'medecin') {
            $stmt = $conn->prepare("
                SELECT p.user_id
                FROM patients p
                INNER JOIN users u ON p.user_id = u.id
                INNER JOIN medecin_patient mp ON p.id = mp.patient_id
                WHERE p.id = :patient_id AND mp.medecin_id = :medecin_id AND p.archived = 0 AND u.role = 'patient'
            ");
            $stmt->bindParam(':medecin_id', $medecin_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(':patient_id', $patientId, PDO::PARAM_INT);
        $stmt->execute();
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            logMessage("Patient non trouvé: id=$patientId");
            http_response_code(404);
            echo json_encode(['error' => 'Patient non trouvé']);
            exit;
        }

        $patient_user_id = $patient['user_id'];

        // Archiver le patient et l'utilisateur (suppression logique)
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("UPDATE patients SET archived = 1 WHERE id = ?");
            $stmt->execute([$patientId]);
            logMessage("Patient archivé: patient_id=$patientId");

            $stmt = $conn->prepare("UPDATE users SET archived = 1 WHERE id = ?");
            $stmt->execute([$patient_user_id]);
            logMessage("Utilisateur archivé: user_id=$patient_user_id");

            if ($role === 'medecin') {
                $stmt = $conn->prepare("DELETE FROM medecin_patient WHERE patient_id = ? AND medecin_id = ?");
                $stmt->execute([$patientId, $medecin_id]);
                logMessage("Association medecin-patient supprimée: patient_id=$patientId, medecin_id=$medecin_id");
            }

            $conn->commit();
            logMessage("Transaction validée");

            http_response_code(200);
            echo json_encode(['message' => 'Patient supprimé avec succès']);
        } catch (Exception $e) {
            $conn->rollBack();
            logMessage('Erreur lors de la suppression: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la suppression du patient: ' . $e->getMessage()]);
            exit;
        }
    } else {
        logMessage('Méthode non autorisée: ' . $method);
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non autorisée']);
        exit;
    }
} catch (Exception $e) {
    logMessage('Erreur inattendue: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
    exit;
}

logMessage('Fin de la requête /patients');
ob_end_clean();
?>