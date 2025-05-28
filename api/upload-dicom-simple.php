<?php
// Désactiver l'affichage des erreurs à l'écran
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Activer les logs d'erreurs
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log'); // Assurez-vous que ce chemin est accessible

// Définir les en-têtes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Log pour confirmer que le script est appelé
error_log("Début de upload-dicom-simple.php");

// Vérifier si l'extension cURL est activée
if (!function_exists('curl_init')) {
    error_log("Erreur : l'extension cURL n'est pas activée dans PHP");
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : l\'extension cURL n\'est pas activée']);
    exit();
}

// Vérifier si un fichier a été envoyé
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    error_log("Erreur : Aucun fichier envoyé ou erreur lors de l'upload");
    http_response_code(400);
    echo json_encode(['error' => 'Aucun fichier envoyé ou erreur lors de l\'upload']);
    exit();
}

// Récupérer le fichier DICOM
$file = $_FILES['file'];
$filePath = $file['tmp_name'];
error_log("Fichier reçu : " . $file['name']);

// Vérifier si le fichier est accessible
if (!file_exists($filePath) || !is_readable($filePath)) {
    error_log("Erreur : Le fichier temporaire n'est pas accessible : " . $filePath);
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : fichier temporaire non accessible']);
    exit();
}

// Envoyer le fichier à Orthanc
try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:8042/instances');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/dicom']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filePath));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log de la réponse d'Orthanc
    error_log("Réponse d'Orthanc (HTTP $httpCode) : " . ($response ?: 'Réponse vide'));
    if ($curlError) {
        error_log("Erreur cURL : $curlError");
    }

    if ($httpCode !== 200) {
        error_log("Erreur lors de l'upload vers Orthanc (HTTP $httpCode)");
        throw new Exception("Erreur lors de l'upload vers Orthanc (HTTP $httpCode)");
    }

    if (empty($response)) {
        error_log("Erreur : Réponse d'Orthanc vide");
        throw new Exception("Réponse d'Orthanc vide");
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Erreur de parsing JSON de la réponse d'Orthanc : " . json_last_error_msg());
        throw new Exception("Erreur de parsing de la réponse d'Orthanc : " . json_last_error_msg());
    }

    if (!$result || !isset($result['ID'])) {
        error_log("ID de l'instance non retourné par Orthanc : " . $response);
        throw new Exception("ID de l'instance non retourné par Orthanc");
    }

    // Retourner l'instance ID
    echo json_encode(['instance_id' => $result['ID']]);
} catch (Exception $e) {
    error_log("Erreur lors de l'upload : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de l\'upload : ' . $e->getMessage()]);
}