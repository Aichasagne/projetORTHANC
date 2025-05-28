<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Fonction pour logger les messages
function logMessage($message) {
    file_put_contents('debug_orthanc.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

logMessage('Début de la requête proxy-orthanc');

// Gestion des requêtes OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Vérifier si exec est disponible
if (!function_exists('exec')) {
    logMessage('Erreur: La fonction exec est désactivée sur ce serveur');
    http_response_code(500);
    echo json_encode(['error' => 'La fonction exec est désactivée, nécessaire pour dcmdump']);
    exit;
}

// Connexion à la base de données
try {
    $pdo = new PDO('mysql:host=localhost;dbname=projet_medical', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    logMessage('Erreur de connexion à la base de données: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données : ' . $e->getMessage()]);
    exit;
}

// Configuration Orthanc
$orthancUrl = 'http://localhost:8042';

// Chemin complet vers dcmdump.exe (modifie selon ton installation)
$dcmdumpPath = 'C:\dcmtk-3.6.9-win64-dynamic\bin\dcmdump.exe';

// Fonction pour générer un UID synthétique (solution de secours ultime)
function generateSyntheticUID() {
    return '1.2.3.4.5.' . time() . '.' . rand(1000, 9999);
}

// Récupérer le chemin de la requête
$path = isset($_GET['path']) ? trim($_GET['path'], '/') : '';
if (empty($path)) {
    logMessage('Erreur: Aucun chemin spécifié');
    http_response_code(400);
    echo json_encode(['error' => 'Aucun chemin spécifié']);
    exit;
}

// Construire l'URL complète pour Orthanc
$fullUrl = rtrim($orthancUrl, '/') . '/' . $path;
logMessage('URL Orthanc: ' . $fullUrl);

// Initialiser cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $fullUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Gérer les différentes méthodes HTTP
$method = $_SERVER['REQUEST_METHOD'];
logMessage('Méthode HTTP: ' . $method);

switch ($method) {
    case 'GET':
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        break;

    case 'POST':
        if ($path === 'instances') {
            try {
                // Log des données reçues
                logMessage('$_FILES: ' . json_encode($_FILES));
                logMessage('$_POST: ' . json_encode($_POST));

                // Gestion de l'upload de fichier DICOM
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    logMessage('Erreur: Vérification $_FILES[\'file\']: ' . json_encode($_FILES));
                    throw new Exception('Aucun fichier DICOM valide fourni ou erreur d\'upload: ' . ($_FILES['file']['error'] ?? 'pas de fichier'));
                }

                $filePath = $_FILES['file']['tmp_name'];
                $patientId = isset($_POST['patient_id']) ? $_POST['patient_id'] : null;
                $customInstanceId = isset($_POST['instance_id']) ? $_POST['instance_id'] : null;
                logMessage('Patient ID: ' . ($patientId ?? 'null') . ', Instance ID personnalisé: ' . ($customInstanceId ?? 'null') . ', Fichier temporaire: ' . $filePath);

                // Vérifier si le fichier temporaire existe et est lisible
                if (!file_exists($filePath) || !is_readable($filePath)) {
                    logMessage('Erreur: Fichier temporaire introuvable ou non lisible: ' . $filePath);
                    throw new Exception('Fichier temporaire introuvable ou non lisible');
                }

                // Vérifier l'existence du patient_id si fourni
                if ($patientId !== null) {
                    try {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE id = ?");
                        $stmt->execute([$patientId]);
                        if ($stmt->fetchColumn() == 0) {
                            logMessage('Erreur: patient_id ' . $patientId . ' n\'existe pas');
                            throw new Exception('Le patient_id spécifié (' . $patientId . ') n\'existe pas');
                        }
                    } catch (PDOException $e) {
                        logMessage('Erreur lors de la vérification du patient_id: ' . $e->getMessage());
                        throw new Exception('Erreur lors de la vérification du patient_id : ' . $e->getMessage());
                    }
                }

                // Lire le contenu du fichier
                $fileContent = file_get_contents($filePath);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/dicom']);

                // Exécuter l'upload
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);

                if ($response === false || !empty($curlError) || $httpCode >= 400) {
                    logMessage('Erreur initiale d\'upload: HTTP Code=' . $httpCode . ', Erreur cURL=' . $curlError . ', Réponse=' . substr($response, 0, 500));
                    throw new Exception('Erreur lors de l\'upload initial vers Orthanc: ' . ($curlError ?: json_decode($response, true)['error'] ?? $response));
                }

                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE || !isset($result['ID'])) {
                    logMessage('Erreur: Réponse Orthanc invalide ou ID manquant: ' . $response);
                    throw new Exception('Réponse Orthanc invalide ou ID manquant');
                }

                $orthancId = $result['ID'];
                logMessage('ID Orthanc attribué: ' . $orthancId);

                // Récupérer les métadonnées via /instances/{id}
                $metadataUrl = "$orthancUrl/instances/$orthancId";
                logMessage('Requête des métadonnées via URL: ' . $metadataUrl);
                $ch = curl_init($metadataUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $metadataResponse = curl_exec($ch);
                $metadataHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $metadataError = curl_error($ch);
                curl_close($ch);

                logMessage('Réponse brute des métadonnées: ' . $metadataResponse);

                if ($metadataResponse === false || $metadataHttpCode >= 400) {
                    logMessage('Erreur lors de la requête des métadonnées: Code=' . $metadataHttpCode . ', Erreur=' . $metadataError);
                    throw new Exception('Erreur lors de la récupération des métadonnées DICOM');
                }

                $metadataData = json_decode($metadataResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    logMessage('Erreur: Métadonnées non-JSON: ' . $metadataResponse);
                    throw new Exception('Métadonnées DICOM non-JSON');
                }

                $studyInstanceUID = null;
                // Vérifier dans MainDicomTags
                if (isset($metadataData['MainDicomTags']['StudyInstanceUID'])) {
                    $studyInstanceUID = $metadataData['MainDicomTags']['StudyInstanceUID'];
                    logMessage('StudyInstanceUID récupéré via MainDicomTags: ' . $studyInstanceUID);
                } else {
                    logMessage('StudyInstanceUID non trouvé dans MainDicomTags, vérification via /studies...');
                    // Récupérer StudyInstanceUID via /studies/{id}
                    if (isset($metadataData['ParentStudy'])) {
                        $studyUrl = "$orthancUrl/studies/{$metadataData['ParentStudy']}";
                        logMessage('Requête StudyInstanceUID via URL: ' . $studyUrl);
                        $ch = curl_init($studyUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $studyResponse = curl_exec($ch);
                        $studyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $studyError = curl_error($ch);
                        curl_close($ch);

                        logMessage('Réponse brute de /studies: ' . $studyResponse);

                        if ($studyResponse === false || $studyHttpCode >= 400) {
                            logMessage('Erreur lors de la requête /studies: Code=' . $studyHttpCode . ', Erreur=' . $studyError);
                        } else {
                            $studyData = json_decode($studyResponse, true);
                            if (isset($studyData['MainDicomTags']['StudyInstanceUID'])) {
                                $studyInstanceUID = $studyData['MainDicomTags']['StudyInstanceUID'];
                                logMessage('StudyInstanceUID récupéré via /studies: ' . $studyInstanceUID);
                            } else {
                                logMessage('StudyInstanceUID non trouvé dans /studies, structure: ' . json_encode($studyData));
                            }
                        }
                    }
                }

                // Solution de secours avec dcmdump si nécessaire
                if (!$studyInstanceUID) {
                    $output = [];
                    $returnCode = 0;
                    // Utiliser le chemin complet de dcmdump et gérer les chemins Windows
                    $dcmdumpCommand = '"' . $dcmdumpPath . '" -q +P 0020,000D ' . escapeshellarg($filePath) . ' 2>&1';
                    logMessage('Commande dcmdump exécutée: ' . $dcmdumpCommand);
                    exec($dcmdumpCommand, $output, $returnCode);
                    logMessage('Sortie de dcmdump: ' . json_encode($output));
                    logMessage('Code de retour de dcmdump: ' . $returnCode);
                    if ($returnCode === 0 && !empty($output)) {
                        foreach ($output as $line) {
                            if (preg_match('/\[(.*?)\]/', $line, $matches)) {
                                $studyInstanceUID = trim($matches[1]);
                                logMessage('StudyInstanceUID récupéré via dcmdump: ' . $studyInstanceUID);
                                break;
                            }
                        }
                    } else {
                        logMessage('Échec de dcmdump, code de retour: ' . $returnCode . ', sortie: ' . implode(', ', $output));
                    }
                }

                // Solution de secours ultime : UID synthétique
                if (!$studyInstanceUID) {
                    $studyInstanceUID = generateSyntheticUID();
                    logMessage('Échec de récupération du StudyInstanceUID via métadonnées et dcmdump, UID synthétique généré: ' . $studyInstanceUID);
                }

                // Récupérer les métadonnées pour Study Date
                $instanceData = $metadataData;
                logMessage('Détails de l\'instance: ' . json_encode($instanceData, JSON_PRETTY_PRINT));

                $studyDate = $instanceData['MainDicomTags']['StudyDate'] ?? null;
                if ($studyDate) {
                    try {
                        $studyDate = DateTime::createFromFormat('Ymd', $studyDate)->format('Y-m-d');
                    } catch (Exception $e) {
                        logMessage('Erreur lors du formatage de StudyDate: ' . $e->getMessage());
                        $studyDate = null;
                    }
                }

                // Stocker l'instance dans la base de données
                try {
                    $uploadDate = date('Y-m-d H:i:s');
                    $description = 'StudyInstanceUID: ' . $studyInstanceUID;

                    if ($patientId !== null) {
                        $stmt = $pdo->prepare("INSERT INTO dicom_instances (orthanc_instance_id, patient_id, upload_date, study_date, description) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$orthancId, $patientId, $uploadDate, $studyDate, $description]);
                        logMessage("Instance DICOM stockée avec patient_id=$patientId, orthanc_instance_id=$orthancId");
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO dicom_instances (orthanc_instance_id, upload_date, study_date, description) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$orthancId, $uploadDate, $studyDate, $description]);
                        logMessage("Instance DICOM stockée sans patient_id, orthanc_instance_id=$orthancId");
                    }

                    if ($customInstanceId) {
                        $stmt = $pdo->prepare("UPDATE dicom_instances SET orthanc_instance_id = ? WHERE orthanc_instance_id = ? AND upload_date = ?");
                        $stmt->execute([$customInstanceId, $orthancId, $uploadDate]);
                        logMessage("Instance ID personnalisé appliqué: $customInstanceId");
                    }
                } catch (PDOException $e) {
                    logMessage('Erreur lors de l\'insertion dans la base de données: ' . $e->getMessage());
                    throw new Exception('Erreur lors de l\'enregistrement de l\'instance: ' . $e->getMessage());
                }

                // Retourner la réponse avec le StudyInstanceUID extrait
                $result['studyInstanceUID'] = $studyInstanceUID;
                $result['patientId'] = $patientId;
                $result['customInstanceId'] = $customInstanceId;
                echo json_encode($result);
            } catch (Exception $e) {
                $errorData = ['error' => $e->getMessage()];
                logMessage('Exception capturée: ' . $e->getMessage() . ', Détails: ' . json_encode($errorData));
                http_response_code(400);
                echo json_encode($errorData);
            }
        } else {
            // Autres requêtes POST (par exemple, modification)
            $inputData = file_get_contents('php://input');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $inputData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        break;

    case 'DELETE':
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        break;

    case 'PUT':
        $inputData = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $inputData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        break;

    default:
        logMessage('Méthode HTTP non supportée: ' . $method);
        http_response_code(405);
        echo json_encode(['error' => 'Méthode HTTP non supportée']);
        exit;
}

// Exécuter la requête cURL pour les autres cas
if ($method !== 'POST' || $path !== 'instances') {
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    logMessage('Requête Orthanc: HTTP Code=' . $httpCode . ', Réponse=' . substr($response, 0, 500) . ', Erreur cURL=' . $curlError);

    if ($response === false || !empty($curlError)) {
        logMessage('Erreur cURL: ' . $curlError);
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la communication avec Orthanc: ' . $curlError]);
        exit;
    }

    if ($httpCode >= 400) {
        $errorMessage = ($contentType && strpos($contentType, 'application/json') !== false)
            ? (json_decode($response, true)['error'] ?? $response)
            : $response;
        logMessage('Erreur Orthanc: Code=' . $httpCode . ', Message=' . $errorMessage);
        http_response_code($httpCode);
        echo json_encode(['error' => $errorMessage]);
        exit;
    }

    // Pour les autres requêtes, retourner la réponse telle quelle
    if ($contentType && strpos($contentType, 'application/json') !== false) {
        echo $response;
    } else {
        echo json_encode(['data' => $response]);
    }
}
?>