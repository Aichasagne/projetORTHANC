<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

// Récupération de l'ID du patient depuis les paramètres de la requête
$patientId = isset($_GET['patientId']) ? (int)$_GET['patientId'] : 0;

if ($patientId <= 0) {
    http_response_code(400);
    echo json_encode(['erreur' => 'ID patient invalide']);
    exit;
}

try {
    $query = "
        SELECT mr.*, CONCAT(u.nom, ' ', u.prenom) AS nom_medecin
        FROM medical_records mr
        JOIN users u ON mr.medecin_id = u.id
        WHERE mr.patient_id = :patientId
        ORDER BY mr.date_enregistrement DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute(['patientId' => $patientId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($records);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erreur' => 'Requête échouée : ' . $e->getMessage()]);
}
?>