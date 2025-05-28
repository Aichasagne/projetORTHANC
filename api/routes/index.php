<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Importer les classes nécessaires pour JWT
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Gérer les requêtes OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Définir la clé JWT (devrait être dans un fichier de configuration ou .env)
define('JWT_SECRET', 'your_jwt_secret_key');

// Vérifier si autoload.php existe
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    error_log("Erreur: vendor/autoload.php non trouvé. Assurez-vous que Composer est installé et que les dépendances sont à jour.");
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : Dépendances manquantes. Veuillez vérifier l\'installation de Composer.']);
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/PatientController.php';
require_once __DIR__ . '/../controllers/ExamenController.php';

// Fonction pour valider le token
function validateToken() {
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Token manquant']);
        exit;
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        return $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Token invalide']);
        exit;
    }
}

// Appliquer la validation du token pour les routes protégées
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);

$authenticated = false;
$user_id = null;
$role = null;

if (isset($uri[2]) && $uri[2] === 'api') {
    if (isset($uri[3]) && !in_array($uri[3], ['login', 'register'])) {
        $decoded = validateToken();
        $user_id = $decoded->data->id ?? null; // Ajuster selon la structure du token généré par AuthController
        $role = $decoded->data->role ?? null; // Récupérer le rôle directement depuis le token
        if (!$user_id || !$role) {
            http_response_code(401);
            echo json_encode(['error' => 'Données utilisateur manquantes dans le token']);
            exit;
        }
        $authenticated = true;
    }

    if (isset($uri[3])) {
        $data = json_decode(file_get_contents('php://input'), true);

        if ($uri[3] === 'login') {
            $controller = new AuthController();
            echo json_encode($controller->login($data));
        } elseif ($uri[3] === 'register') {
            $controller = new AuthController();
            echo json_encode($controller->register($data));
        } elseif ($uri[3] === 'patients' && $authenticated) {
            $controller = new PatientController();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo json_encode($controller->getPatients());
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode($controller->addPatient($data));
            } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($uri[4])) {
                echo json_encode($controller->updatePatient($uri[4], $data));
            } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($uri[4])) {
                echo json_encode($controller->deletePatient($uri[4]));
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
            }
        } elseif ($uri[3] === 'patient' && $uri[4] === 'me' && $authenticated) {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
                exit();
            }
            $controller = new PatientController();
            echo json_encode($controller->getProfile());
        } elseif ($uri[3] === 'examens' && $authenticated) {
            $controller = new ExamenController();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo json_encode($controller->getExamens());
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                echo json_encode($controller->addExamen($data));
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
            }
        } elseif ($uri[3] === 'medecins' && $authenticated) {
            $controller = new ExamenController();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo json_encode($controller->getMedecins());
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
            }
        } elseif ($uri[3] === 'radiologues' && $authenticated) {
            $controller = new ExamenController();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo json_encode($controller->getRadiologues());
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
            }
        } elseif ($uri[3] === 'statistics' && $authenticated) {
            $controller = new ExamenController();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                echo json_encode($controller->getStatistics());
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode non autorisée']);
            }
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Route non trouvée']);
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Route non trouvée']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Route non trouvée']);
}
?>