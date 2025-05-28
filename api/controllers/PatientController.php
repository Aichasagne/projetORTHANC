<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class PatientController {
    private $db;
    private $jwt_secret = JWT_SECRET; // Utiliser la constante définie dans index.php

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
        if ($this->db === null) {
            error_log("Échec de la connexion à la base de données.");
            throw new Exception("Impossible de se connecter à la base de données.");
        }
    }

    public function getPatients() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getPatients).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (getPatients).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (getPatients).");
                return ['error' => 'Accès interdit : seuls les médecins et admins peuvent voir les patients'];
            }

            $query = "SELECT u.id, u.nom, u.prenom, u.email, p.date_naissance, p.sexe, p.telephone, p.adresse, p.id as patient_id 
                      FROM patients p 
                      JOIN users u ON p.user_id = u.id";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ['patients' => $patients];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getPatients): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }

    public function getProfile() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getProfile).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            $userId = $decoded->data->id;
            $role = $decoded->data->role;
            if ($role !== 'patient') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (getProfile).");
                return ['error' => 'Accès non autorisé: vous n\'êtes pas un patient'];
            }

            $query = "SELECT u.nom, u.prenom, u.email, p.date_naissance, p.sexe, p.telephone, p.adresse, p.id as patient_id
                      FROM patients p
                      JOIN users u ON p.user_id = u.id
                      WHERE u.id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patient) {
                return ['patient' => $patient];
            }
            return ['error' => 'Profil non trouvé'];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getProfile): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }

    public function getPatientProfile($userId) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getPatientProfile).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            $role = $decoded->data->role;
            $currentUserId = $decoded->data->id;

            if ($role !== 'medecin' && $role !== 'admin' && $currentUserId != $userId) {
                error_log("Accès interdit: Utilisateur $currentUserId tente d'accéder au profil $userId (getPatientProfile).");
                return ['error' => 'Accès non autorisé'];
            }

            $query = "SELECT u.nom, u.prenom, u.email, p.date_naissance, p.sexe, p.telephone, p.adresse, p.id as patient_id
                      FROM patients p
                      JOIN users u ON p.user_id = u.id
                      WHERE u.id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patient) {
                return ['patient' => $patient];
            }
            return ['error' => 'Profil non trouvé'];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getPatientProfile): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }

    public function addPatient($data) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (addPatient).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (addPatient).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (addPatient).");
                return ['error' => 'Accès interdit: seuls les médecins et admins peuvent ajouter des patients'];
            }

            if (!isset($data['nom'], $data['prenom'], $data['email'], $data['password'], $data['date_naissance'], $data['sexe'], $data['telephone'])) {
                error_log("Données manquantes dans addPatient: " . json_encode($data));
                return ['error' => 'Données manquantes'];
            }

            $query = "INSERT INTO users (nom, prenom, email, password, role, created_at)
                      VALUES (:nom, :prenom, :email, :password, 'patient', NOW())";
            $stmt = $this->db->prepare($query);
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            $stmt->bindParam(':nom', $data['nom']);
            $stmt->bindParam(':prenom', $data['prenom']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $hashedPassword);
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log("Erreur SQL lors de la création de l'utilisateur: " . json_encode($errorInfo));
                return ['error' => 'Erreur lors de la création de l\'utilisateur'];
            }

            $userId = $this->db->lastInsertId();
            $query = "INSERT INTO patients (user_id, date_naissance, sexe, telephone, adresse, created_at)
                      VALUES (:user_id, :date_naissance, :sexe, :telephone, :adresse, NOW())";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':date_naissance', $data['date_naissance']);
            $stmt->bindParam(':sexe', $data['sexe']);
            $stmt->bindParam(':telephone', $data['telephone']);
            $stmt->bindParam(':adresse', $data['adresse'] ?? null);
            if ($stmt->execute()) {
                error_log("Patient ajouté avec succès, user_id: $userId.");
                return ['message' => 'Patient ajouté avec succès'];
            }

            $errorInfo = $stmt->errorInfo();
            error_log("Erreur SQL lors de l'ajout du patient: " . json_encode($errorInfo));
            return ['error' => 'Erreur lors de l\'ajout du patient'];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (addPatient): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }

    public function updatePatient($patientId, $data) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (updatePatient).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (updatePatient).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            $currentUserId = $decoded->data->id;

            if ($role !== 'medecin' && $role !== 'admin' && $currentUserId != $patientId) {
                error_log("Accès interdit: Utilisateur $currentUserId tente de modifier le patient $patientId (updatePatient).");
                return ['error' => 'Accès interdit: seuls les médecins, admins ou l\'utilisateur concerné peuvent modifier ce profil'];
            }

            if (!isset($data['nom'], $data['prenom'], $data['email'], $data['date_naissance'], $data['sexe'])) {
                error_log("Données manquantes dans updatePatient: " . json_encode($data));
                return ['error' => 'Données manquantes'];
            }

            // Vérifier si le patient existe
            $query = "SELECT user_id FROM patients WHERE id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $patientId);
            $stmt->execute();
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$patient) {
                error_log("Patient non trouvé: ID $patientId (updatePatient).");
                return ['error' => 'Patient non trouvé'];
            }

            $userId = $patient['user_id'];

            // Assigner les valeurs à des variables pour bindParam
            $nom = $data['nom'];
            $prenom = $data['prenom'];
            $email = $data['email'];
            $date_naissance = $data['date_naissance'];
            $sexe = $data['sexe'];
            $telephone = isset($data['telephone']) ? $data['telephone'] : null;
            $adresse = isset($data['adresse']) ? $data['adresse'] : null;

            // Mettre à jour les informations de base
            $query = "UPDATE users u
                      JOIN patients p ON p.user_id = u.id
                      SET u.nom = :nom, u.prenom = :prenom, u.email = :email,
                          p.date_naissance = :date_naissance, p.sexe = :sexe, p.telephone = :telephone, p.adresse = :adresse
                      WHERE p.id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':prenom', $prenom);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':date_naissance', $date_naissance);
            $stmt->bindParam(':sexe', $sexe);
            $stmt->bindParam(':telephone', $telephone, $telephone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':adresse', $adresse, $adresse === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':patient_id', $patientId);

            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log("Erreur SQL lors de la mise à jour du patient: " . json_encode($errorInfo));
                return ['error' => 'Erreur lors de la mise à jour du patient'];
            }

            // Mettre à jour le mot de passe si fourni
            if (!empty($data['password'])) {
                $query = "UPDATE users SET password = :password WHERE id = :user_id";
                $stmt = $this->db->prepare($query);
                $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
                $stmt->bindParam(':password', $hashedPassword);
                $stmt->bindParam(':user_id', $userId);
                if (!$stmt->execute()) {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Erreur SQL lors de la mise à jour du mot de passe: " . json_encode($errorInfo));
                    return ['error' => 'Erreur lors de la mise à jour du mot de passe'];
                }
            }

            // Récupérer les données mises à jour pour la réponse
            $query = "SELECT u.id, u.nom, u.prenom, u.email, p.date_naissance, p.sexe, p.telephone, p.adresse, p.id as patient_id 
                      FROM users u 
                      JOIN patients p ON p.user_id = u.id 
                      WHERE p.id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $patientId);
            $stmt->execute();
            $updatedPatient = $stmt->fetch(PDO::FETCH_ASSOC);

            error_log("Patient mis à jour avec succès: ID $patientId.");
            return [
                'id' => (int)$updatedPatient['id'],
                'patient_id' => (int)$updatedPatient['patient_id'],
                'nom' => $updatedPatient['nom'],
                'prenom' => $updatedPatient['prenom'],
                'email' => $updatedPatient['email'],
                'date_naissance' => $updatedPatient['date_naissance'],
                'sexe' => $updatedPatient['sexe'],
                'telephone' => $updatedPatient['telephone'],
                'adresse' => $updatedPatient['adresse']
            ];
        } catch (Exception $e) {
            error_log("Erreur lors de la mise à jour du patient (updatePatient): " . $e->getMessage());
            return ['error' => 'Erreur: ' . $e->getMessage()];
        }
    }

    public function deletePatient($patientId) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (deletePatient).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (deletePatient).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (deletePatient).");
                return ['error' => 'Accès interdit: seuls les médecins et admins peuvent supprimer des patients'];
            }

            $query = "SELECT user_id FROM patients WHERE id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $patientId);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                error_log("Patient non trouvé: ID $patientId (deletePatient).");
                return ['error' => 'Patient non trouvé'];
            }

            $userId = $user['user_id'];

            // Supprimer les examens associés au patient
            $query = "DELETE FROM examens WHERE patient_id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $patientId);
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log("Erreur SQL lors de la suppression des examens: " . json_encode($errorInfo));
                return ['error' => 'Erreur lors de la suppression des examens associés'];
            }

            // Supprimer le patient
            $query = "DELETE FROM patients WHERE id = :patient_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $patientId);
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                error_log("Erreur SQL lors de la suppression du patient: " . json_encode($errorInfo));
                return ['error' => 'Erreur lors de la suppression du patient'];
            }

            // Supprimer l'utilisateur
            $query = "DELETE FROM users WHERE id = :user_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            if ($stmt->execute()) {
                error_log("Patient supprimé avec succès: ID $patientId.");
                return ['message' => 'Patient supprimé avec succès'];
            }

            $errorInfo = $stmt->errorInfo();
            error_log("Erreur SQL lors de la suppression de l'utilisateur: " . json_encode($errorInfo));
            return ['error' => 'Erreur lors de la suppression de l\'utilisateur'];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (deletePatient): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }
}
?>