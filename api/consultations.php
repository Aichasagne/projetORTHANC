<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Inclusion de la classe Database
require_once 'C:/xampp/htdocs/projet-medical/api/config/database.php';

// Instanciation de la classe Database
$database = new Database();
$conn = $database->connect();

if ($conn === null) {
    http_response_code(500);
    echo json_encode(['erreur' => 'Connexion à la base de données échouée']);
    exit;
}

// Vérification du token (exemple simplifié ; améliorez selon votre système d'authentification)
$headers = apache_request_headers();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Aucun token fourni']);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
// Ajoutez votre logique de validation du token ici (ex. décodage JWT ou vérification de session)

// Déterminer la méthode de la requête
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Ajouter une nouvelle consultation
    $patientId = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
    $dateConsultation = isset($_POST['date_consultation']) ? $_POST['date_consultation'] : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $file = isset($_FILES['file']) ? $_FILES['file'] : null;

    if ($patientId <= 0 || !$dateConsultation || !$notes) {
        http_response_code(400);
        echo json_encode(['erreur' => 'Données manquantes']);
        exit;
    }

    try {
        // Récupérer l'ID du médecin à partir du token (simulé ici, remplacez par votre logique)
        $medecinId = 1; // Remplacez par une récupération réelle (ex. via JWT)

        // Gérer le fichier DICOM s'il est fourni
        $fichier = null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $formData = new FormData();
            $formData->append('file', $file['tmp_name']);
            $response = file_get_contents('http://localhost/projet-medical/api/proxy-orthanc.php?path=instances', false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: multipart/form-data\r\n",
                    'content' => $formData->getContent()
                ]
            ]));
            $result = json_decode($response, true);
            if (!$result || !isset($result['studyInstanceUID'])) {
                throw new Error('Erreur lors de l\'upload du fichier DICOM');
            }
            $fichier = $result['studyInstanceUID'];
        }

        // Insérer la consultation dans la base de données
        $query = "
            INSERT INTO consultations (patient_id, medecin_id, date_consultation, notes, fichier)
            VALUES (:patientId, :medecinId, :dateConsultation, :notes, :fichier)
        ";
        $stmt = $conn->prepare($query);
        $stmt->execute([
            'patientId' => $patientId,
            'medecinId' => $medecinId,
            'dateConsultation' => $dateConsultation,
            'notes' => $notes,
            'fichier' => $fichier
        ]);

        http_response_code(201);
        echo json_encode(['message' => 'Consultation ajoutée avec succès']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['erreur' => 'Requête échouée : ' . $e->getMessage()]);
    }
} else {
    // Récupérer les consultations (GET)
    $patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : 0;

    if ($patientId <= 0) {
        http_response_code(400);
        echo json_encode(['erreur' => 'ID patient invalide']);
        exit;
    }

    try {
        $query = "
            SELECT c.*, CONCAT(u.nom, ' ', u.prenom) AS nom_medecin
            FROM consultations c
            JOIN users u ON c.medecin_id = u.id
            WHERE c.patient_id = :patientId
            ORDER BY c.date_consultation DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->execute(['patientId' => $patientId]);
        $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map the result to match the expected JSON structure in openConsultationsModal
        $formattedConsultations = array_map(function($c) {
            return [
                'id' => $c['id'],
                'date' => $c['date_consultation'],
                'diagnosis' => $c['notes'] ?? 'Aucun diagnostic',
                'treatment' => '',
                'nom_medecin' => $c['nom_medecin']
            ];
        }, $consultations);

        echo json_encode($formattedConsultations);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['erreur' => 'Requête échouée : ' . $e->getMessage()]);
    }
}
?>