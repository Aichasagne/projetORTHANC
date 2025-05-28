<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ExamenController {
    private $db;
    private $jwt_secret = JWT_SECRET; // Utiliser la constante définie dans index.php
    private $orthanc_url = 'http://localhost:8042'; // URL de base d'Orthanc

    public function __construct() {
        $database = new Database();
        $this->db = $database->connect();
        if ($this->db === null) {
            error_log("Échec de la connexion à la base de données.");
            throw new Exception("Impossible de se connecter à la base de données.");
        }

        // Vérifier si Orthanc est accessible
        $this->checkOrthancAvailability();
    }

    private function checkOrthancAvailability() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->orthanc_url . '/system');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Orthanc n'est pas accessible: HTTP Code $httpCode");
            throw new Exception("Orthanc n'est pas accessible");
        }
    }

    public function getExamens() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getExamens).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (getExamens).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role === 'patient') {
                return $this->getPatientExamens($decoded->data->id);
            } elseif ($role === 'medecin' || $role === 'admin') {
                $query = "SELECT e.id, e.type_examen, e.date_examen, e.resultat, e.fichier_dicom, e.statut, 
                                 p.id AS patient_id, u.nom, u.prenom 
                          FROM examens e
                          JOIN patients p ON e.patient_id = p.id
                          JOIN users u ON p.user_id = u.id";
                $stmt = $this->db->prepare($query);
                $stmt->execute();
                $examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return ['examens' => $examens];
            }
            error_log("Rôle non reconnu: $role (getExamens).");
            return ['error' => 'Rôle non reconnu'];
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getExamens): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }
    }

    private function getPatientExamens($userId) {
        $query = "SELECT e.id, e.type_examen, e.date_examen, e.resultat, e.fichier_dicom, e.statut 
                  FROM examens e
                  JOIN patients p ON e.patient_id = p.id
                  WHERE p.user_id = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        $examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['examens' => $examens];
    }

    public function getExamsByPatient($patientId) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getExamsByPatient).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (getExamsByPatient).");
                return ['error' => 'Token invalide'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getExamsByPatient): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        $query = "SELECT e.id, e.type_examen, e.date_examen, e.resultat, e.fichier_dicom, e.statut
                  FROM examens e
                  WHERE e.patient_id = :patient_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':patient_id', $patientId);
        $stmt->execute();
        $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les annotations pour chaque examen
        foreach ($exams as &$exam) {
            $query = "SELECT a.annotation_text, u.nom, u.prenom
                      FROM annotations a
                      JOIN users u ON a.user_id = u.id
                      WHERE a.examen_id = :examen_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':examen_id', $exam['id']);
            $stmt->execute();
            $exam['annotations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return ['exams' => $exams];
    }

    public function addExamen($data) {
        error_log("Début de addExamen: " . json_encode($data));

        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (addExamen).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (addExamen).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (addExamen).");
                return ['error' => 'Accès interdit : seuls les médecins et admins peuvent ajouter des examens'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (addExamen): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        if (!isset($data['patient_id'], $data['medecin_id'], $data['radiologue_id'], $data['type_examen'], $data['date_examen'])) {
            error_log("Données manquantes dans addExamen: " . json_encode($data));
            return ['error' => 'Données manquantes : patient_id, medecin_id, radiologue_id, type_examen, date_examen sont requis'];
        }

        // Vérifier si un fichier DICOM a été uploadé
        if (!isset($_FILES['dicom_file']) || $_FILES['dicom_file']['error'] !== UPLOAD_ERR_OK) {
            error_log("Fichier DICOM manquant dans addExamen.");
            return ['error' => 'Un fichier DICOM est requis'];
        }

        $dicomFilePath = $_FILES['dicom_file']['tmp_name'];

        // Vérifier que le fichier est un DICOM valide (basé sur l'extension)
        $fileExtension = strtolower(pathinfo($_FILES['dicom_file']['name'], PATHINFO_EXTENSION));
        if ($fileExtension !== 'dcm') {
            error_log("Format de fichier invalide dans addExamen: $fileExtension");
            return ['error' => 'Le fichier doit être un fichier DICOM (.dcm)'];
        }

        // Uploader le fichier DICOM sur Orthanc
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->orthanc_url . '/instances');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/dicom']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($dicomFilePath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Erreur lors de l'upload du fichier DICOM sur Orthanc: HTTP Code $httpCode");
            return ['error' => 'Erreur lors de l\'upload du fichier DICOM sur Orthanc'];
        }

        $orthancResponse = json_decode($response, true);
        if (!$orthancResponse || !isset($orthancResponse['ID'])) {
            error_log("Erreur lors de l'upload du fichier DICOM sur Orthanc: Réponse invalide " . json_encode($orthancResponse));
            return ['error' => 'Erreur lors de l\'upload du fichier DICOM sur Orthanc'];
        }

        $dicomUrl = $this->orthanc_url . "/instances/{$orthancResponse['ID']}/file";
        error_log("Fichier DICOM uploadé avec succès, URL: $dicomUrl");

        $query = "INSERT INTO examens (patient_id, medecin_id, radiologue_id, type_examen, date_examen, fichier_dicom, resultat, statut, created_at) 
                  VALUES (:patient_id, :medecin_id, :radiologue_id, :type_examen, :date_examen, :fichier_dicom, :resultat, :statut, NOW())";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':patient_id', $data['patient_id']);
            $stmt->bindParam(':medecin_id', $data['medecin_id']);
            $stmt->bindParam(':radiologue_id', $data['radiologue_id']);
            $stmt->bindParam(':type_examen', $data['type_examen']);
            $stmt->bindParam(':date_examen', $data['date_examen']);
            $stmt->bindParam(':fichier_dicom', $dicomUrl);
            $stmt->bindParam(':resultat', $data['resultat'] ?? null);
            $stmt->bindParam(':statut', $data['statut'] ?? 'en_attente');

            if ($stmt->execute()) {
                error_log("Examen ajouté avec succès.");
                return ['message' => 'Examen ajouté avec succès'];
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Erreur SQL lors de l'insertion: " . json_encode($errorInfo));
                return ['error' => 'Erreur SQL lors de l\'ajout de l\'examen : ' . $errorInfo[2]];
            }
        } catch (Exception $e) {
            error_log("Exception lors de l'insertion: " . $e->getMessage());
            return ['error' => 'Erreur lors de l\'ajout de l\'examen : ' . $e->getMessage()];
        }
    }

    public function addAnnotation($data) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (addAnnotation).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (addAnnotation).");
                return ['error' => 'Token invalide'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (addAnnotation): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        if (!isset($data['examen_id'], $data['user_id'], $data['annotation_text'])) {
            error_log("Données manquantes dans addAnnotation: " . json_encode($data));
            return ['error' => 'Données manquantes : examen_id, user_id, annotation_text sont requis'];
        }

        $query = "INSERT INTO annotations (examen_id, user_id, annotation_text, created_at)
                  VALUES (:examen_id, :user_id, :annotation_text, NOW())";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':examen_id', $data['examen_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':annotation_text', $data['annotation_text']);

        if ($stmt->execute()) {
            error_log("Annotation ajoutée avec succès.");
            return ['message' => 'Annotation ajoutée avec succès'];
        } else {
            $errorInfo = $stmt->errorInfo();
            error_log("Erreur SQL lors de l'insertion de l'annotation: " . json_encode($errorInfo));
            return ['error' => 'Erreur SQL lors de l\'ajout de l\'annotation : ' . $errorInfo[2]];
        }
    }

    public function exportExams() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (exportExams).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (exportExams).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (exportExams).");
                return ['error' => 'Accès interdit : seuls les médecins et admins peuvent exporter les examens'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (exportExams): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        $query = "SELECT u.nom AS patient_nom, u.prenom AS patient_prenom, e.type_examen, e.date_examen, e.resultat, e.fichier_dicom, e.statut
                  FROM examens e
                  JOIN patients p ON e.patient_id = p.id
                  JOIN users u ON p.user_id = u.id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csvData = "Patient Nom,Patient Prenom,Type Examen,Date Examen,Resultat,Fichier DICOM,Statut\n";
        foreach ($exams as $exam) {
            $csvData .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $exam['patient_nom'],
                $exam['patient_prenom'],
                $exam['type_examen'],
                $exam['date_examen'],
                str_replace(',', ';', $exam['resultat'] ?? 'N/A'),
                $exam['fichier_dicom'] ?? 'N/A',
                $exam['statut']
            );
        }

        return ['csv' => base64_encode($csvData)];
    }

    public function createInterHospitalRequest($data) {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (createInterHospitalRequest).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (createInterHospitalRequest).");
                return ['error' => 'Token invalide'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (createInterHospitalRequest): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        if (!isset($data['user_id'], $data['hospital_name'], $data['reason'])) {
            error_log("Données manquantes dans createInterHospitalRequest: " . json_encode($data));
            return ['error' => 'Données manquantes : user_id, hospital_name, reason sont requis'];
        }

        $query = "INSERT INTO inter_hospital_requests (user_id, hospital_name, reason, status, created_at)
                  VALUES (:user_id, :hospital_name, :reason, 'pending', NOW())";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':hospital_name', $data['hospital_name']);
        $stmt->bindParam(':reason', $data['reason']);

        if ($stmt->execute()) {
            error_log("Demande inter-hospitalière créée avec succès.");
            return ['message' => 'Demande d\'accès inter-hospitalier créée avec succès'];
        } else {
            $errorInfo = $stmt->errorInfo();
            error_log("Erreur SQL lors de l'insertion de la demande inter-hospitalière: " . json_encode($errorInfo));
            return ['error' => 'Erreur SQL lors de la création de la demande : ' . $errorInfo[2]];
        }
    }

    public function getMedecins() {
        $query = "SELECT m.id, u.nom, u.prenom, m.specialite, m.numero_licence 
                  FROM medecins m
                  JOIN users u ON m.user_id = u.id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $medecins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['medecins' => $medecins];
    }

    public function getRadiologues() {
        $query = "SELECT r.id, u.nom, u.prenom, r.certification 
                  FROM radiologues r
                  JOIN users u ON r.user_id = u.id";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $radiologues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['radiologues' => $radiologues];
    }

    public function getStatistics() {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            error_log("Token manquant dans les en-têtes (getStatistics).");
            return ['error' => 'Token manquant'];
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        try {
            $decoded = JWT::decode($token, new Key($this->jwt_secret, 'HS256'));
            if (!$decoded->data->id) {
                error_log("Token invalide: ID manquant dans le token (getStatistics).");
                return ['error' => 'Token invalide'];
            }

            $role = $decoded->data->role;
            if ($role !== 'medecin' && $role !== 'admin') {
                error_log("Accès interdit: Rôle utilisateur = " . $role . " (getStatistics).");
                return ['error' => 'Accès interdit : seuls les médecins et admins peuvent voir les statistiques'];
            }
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du token (getStatistics): " . $e->getMessage());
            return ['error' => 'Token invalide: ' . $e->getMessage()];
        }

        $stats = [];

        $query = "SELECT COUNT(*) as total FROM patients";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['total_patients'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $query = "SELECT COUNT(*) as total FROM examens";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['total_examens'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $query = "SELECT type_examen, COUNT(*) as count 
                  FROM examens 
                  GROUP BY type_examen";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['examens_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $query = "SELECT sexe, COUNT(*) as count 
                  FROM patients 
                  GROUP BY sexe";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['patients_by_sexe'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $query = "SELECT statut, COUNT(*) as count 
                  FROM examens 
                  GROUP BY statut";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['examens_by_statut'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }
}
?>