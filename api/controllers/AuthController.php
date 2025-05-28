<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;

class AuthController {
    private $db;
    private $jwt_secret = JWT_SECRET;

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
        if ($this->db === null) {
            throw new Exception("Impossible de se connecter à la base de données.");
        }
    }

    public function register($data) {
        // Validate required fields for users table
        if (!isset($data['nom'], $data['prenom'], $data['email'], $data['password'], $data['role'])) {
            return ['error' => 'Données manquantes pour l\'utilisateur'];
        }

        // Validate role-specific fields
        $role = $data['role'];
        if ($role === 'patient' && !isset($data['date_naissance'], $data['sexe'], $data['telephone'], $data['adresse'])) {
            return ['error' => 'Données manquantes pour le patient : date_naissance, sexe, telephone, adresse requis'];
        } elseif ($role === 'medecin' && !isset($data['specialite'], $data['numero_licence'])) {
            return ['error' => 'Données manquantes pour le médecin : specialite, numero_licence requis'];
        } elseif ($role === 'radiologue' && !isset($data['certification'])) {
            return ['error' => 'Données manquantes pour le radiologue : certification requis'];
        } elseif ($role === 'admin' && !isset($data['niveau_acces'])) {
            return ['error' => 'Données manquantes pour l\'admin : niveau_acces requis'];
        }

        // Check if email already exists
        $query = "SELECT id FROM users WHERE email = :email";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['error' => 'Cet email est déjà utilisé'];
        }

        // Start transaction
        try {
            $this->db->beginTransaction();

            // Insert into users table
            $query = "INSERT INTO users (nom, prenom, email, password, role, created_at) 
                      VALUES (:nom, :prenom, :email, :password, :role, NOW())";
            $stmt = $this->db->prepare($query);
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            $stmt->bindParam(':nom', $data['nom']);
            $stmt->bindParam(':prenom', $data['prenom']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $data['role']);
            if (!$stmt->execute()) {
                throw new Exception('Erreur lors de la création de l\'utilisateur');
            }

            $userId = $this->db->lastInsertId();

            // Insert into role-specific table
            if ($role === 'patient') {
                $query = "INSERT INTO patients (user_id, date_naissance, sexe, telephone, adresse, created_at)
                          VALUES (:user_id, :date_naissance, :sexe, :telephone, :adresse, NOW())";
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->bindParam(':date_naissance', $data['date_naissance']);
                $stmt->bindParam(':sexe', $data['sexe']);
                $stmt->bindParam(':telephone', $data['telephone']);
                $stmt->bindParam(':adresse', $data['adresse']);
            } elseif ($role === 'medecin') {
                $query = "INSERT INTO medecins (user_id, specialite, numero_licence, created_at)
                          VALUES (:user_id, :specialite, :numero_licence, NOW())";
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->bindParam(':specialite', $data['specialite']);
                $stmt->bindParam(':numero_licence', $data['numero_licence']);
            } elseif ($role === 'radiologue') {
                $query = "INSERT INTO radiologues (user_id, certification, created_at)
                          VALUES (:user_id, :certification, NOW())";
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->bindParam(':certification', $data['certification']);
            } elseif ($role === 'admin') {
                $query = "INSERT INTO admins (user_id, niveau_acces, created_at)
                          VALUES (:user_id, :niveau_acces, NOW())";
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->bindParam(':niveau_acces', $data['niveau_acces']);
            }

            if (!$stmt->execute()) {
                throw new Exception('Erreur lors de l\'ajout des données spécifiques au rôle');
            }

            $this->db->commit();
            return ['message' => 'Utilisateur créé avec succès'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['error' => 'Erreur lors de la création : ' . $e->getMessage()];
        }
    }

    public function login($data) {
        if (!isset($data['email'], $data['password'])) {
            return ['error' => 'Données manquantes'];
        }

        $query = "SELECT * FROM users WHERE email = :email";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($data['password'], $user['password'])) {
            $payload = [
                'iat' => time(),
                'exp' => time() + (60 * 60 * 24), // Token valide 24h
                'data' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ];
            $jwt = JWT::encode($payload, $this->jwt_secret, 'HS256');
            return ['token' => $jwt, 'role' => $user['role']];
        }
        return ['error' => 'Email ou mot de passe incorrect'];
    }

    public function validateToken($token) {
        try {
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($this->jwt_secret, 'HS256'));
            $query = "SELECT * FROM users WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $decoded->data->id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return ['user' => $user];
            }
            return ['error' => 'Utilisateur non trouvé'];
        } catch (Exception $e) {
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }
}