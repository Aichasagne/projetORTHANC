-- Set the character set to UTF-8 to handle special characters
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Create the database with UTF-8 encoding
CREATE DATABASE IF NOT EXISTS projet_medical
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE projet_medical;

-- Create table: users
CREATE TABLE users (
    id INT(11) NOT NULL AUTO_INCREMENT,
    nom VARCHAR(255) NOT NULL,
    prenom VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('medecin', 'patient', 'radiologue', 'admin') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY (email)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: medecins
CREATE TABLE medecins (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    specialite VARCHAR(255) NOT NULL,
    numero_licence VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: patients
CREATE TABLE patients (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    date_naissance DATE NOT NULL,
    sexe ENUM('M', 'F') NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    adresse VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    medecin_id INT(11) NOT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: consultations
CREATE TABLE consultations (
    id INT(11) NOT NULL AUTO_INCREMENT,
    patient_id INT(11) NOT NULL,
    medecin_id INT(11) NOT NULL,
    date_consultation DATETIME NOT NULL,
    diagnostic TEXT NOT NULL,
    fichier VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: prescriptions
CREATE TABLE prescriptions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    consultation_id INT(11) NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: medical_records
CREATE TABLE medical_records (
    id INT(11) NOT NULL AUTO_INCREMENT,
    patient_id INT(11) NOT NULL,
    medecin_id INT(11) NOT NULL,
    date_enregistrement DATE NOT NULL,
    type_enregistrement VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    cree_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: dossier
CREATE TABLE dossier (
    id INT(11) NOT NULL AUTO_INCREMENT,
    patient_id INT(11) NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: examens
CREATE TABLE examens (
    id INT(11) NOT NULL AUTO_INCREMENT,
    patient_id INT(11) NOT NULL,
    medecin_id INT(11) NOT NULL,
    radiologue_id INT(11) DEFAULT NULL,
    type_examen VARCHAR(255) NOT NULL,
    date_examen DATE NOT NULL,
    resultat TEXT NOT NULL,
    fichier_dicom VARCHAR(255) NOT NULL,
    statut ENUM('termine', 'en_cours') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (patient Paragon 11) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE,
    FOREIGN KEY (radiologue_id) REFERENCES users(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: rendezvous
CREATE TABLE rendezvous (
    id INT(11) NOT NULL AUTO_INCREMENT,
    patient_id INT(11) NOT NULL,
    medecin_id INT(11) NOT NULL,
    date_rdv DATETIME NOT NULL,
    motif TEXT NOT NULL,
    statut ENUM('termine', 'en_attente') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (medecin_id) REFERENCES medecins(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create table: dicom_instances
CREATE TABLE dicom_instances (
    dicom_instance_id INT(11) NOT NULL AUTO_INCREMENT,
    orthanc_instance_id VARCHAR(255) NOT NULL,
    patient_id INT(11) NOT NULL,
    upload_date DATETIME NOT NULL,
    study_date DATE DEFAULT NULL,
    description TEXT NOT NULL,
    orthanc_original_id VARCHAR(255) DEFAULT NULL,
    consultation_id INT(11) DEFAULT NULL,
    PRIMARY KEY (dicom_instance_id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Insert data into users
INSERT INTO users (id, nom, prenom, email, password, role, created_at) VALUES
(1, 'Sagne', 'Aicha', 'aichasagne@esp.sn', '$2y$10$u.RVlacTYdapXj0tILvVd.l5t7Nd.BklYH6gFTghthFjEV./5zS5W', 'medecin', '2025-04-24 13:50:58'),
(2, 'ndiaye', 'kabyraa', 'kabyrndiaye@esp.sn', '$2y$10$PkK2QjbVkpdwmO50nb9prerCHhGZQfnyL3kqW6t9rJGbfFAAjq2b2', 'patient', '2025-04-24 13:53:45'),
(3, 'dell', 'modou', 'modoudell@esp.sn', '$2y$10$Oe06YKgXhqHyD9oevOn78uYlpES2GfAL2zdLceOtO/Rw/mfv8x9qm', 'patient', '2025-04-24 14:07:09'),
(4, 'toure', 'ndeye khary', 'ndeyekhary@esp.sn', '$2y$10$vwhdSYgxoTxBM4rCnE3sCO3fauMFHwz4Im5nWsNI1kAN0ptB4VFCy', 'medecin', '2025-04-24 14:07:59'),
(5, 'mouhamed', 'niass', 'mouhamedniass@esp.sn', '$2y$10$2CO6fHqmMqaSOaCjCk.ra.wTy9SFNudya.UH/4NTnlGqG/nXw6DAi', 'radiologue', '2025-04-24 14:10:05'),
(6, 'aminata', 'khoule', 'aminatakhoule@esp.sn', '$2y$10$PlZS9rMlGSQN3UHuNUGbi.IPZs.Irg1lq0zg6MT12bZXQLCbvDej6', 'radiologue', '2025-04-24 14:10:54'),
(7, 'aladji', 'rafett', 'aladjirafett@esp.sn', '$2y$10$lAWceEU93uggxAxPP7oY7.Cmi0LFfDR2YbND.6/P8ohHQCx83BiEa', 'admin', '2025-04-24 14:11:56'),
(8, 'ndeye', 'niakh', 'ndeyeniakh@esp.sn', '$2y$10$ocH2w4OCu3Z.nn315EFvPeo8bbYv/J9dLpyblafhUAP/kRuhURpES', 'admin', '2025-04-24 14:13:21'),
(9, 'ayshu', 'queen', 'aichasagne4@gmail.com', '$2y$10$I0BT327v2TTK2zEpB1r8H.O5qd2esGAAHUzIm7NTAWVLjc8B6p2hC', 'patient', '2025-04-30 14:33:18'),
(10, 'mbacke', 'amsatou', 'amsatoumbacke@esp.sn', '$2y$10$MB7xS048JxBqtIxNJNdCJeBpKVW9UTzMl1T7RFTBzce0eGGkCtjha', 'patient', '2025-04-30 14:34:37'),
(11, 'lam', 'souleymane', 'souleymanelam@patient.sn', '$2y$10$M1wY4aURbbErYExXJsnSHOyKCc1mX4XiTG/0SNrJ9fd8xhpicl26W', 'patient', '2025-04-30 14:36:22');

-- Insert data into medecins
INSERT INTO medecins (id, user_id, specialite, numero_licence, created_at) VALUES
(1, 1, 'medecin generaliste', '12121', '2025-04-24 13:50:58'),
(2, 4, 'ORL', '5678', '2025-04-24 14:07:59');

-- Insert data into patients
INSERT INTO patients (id, user_id, date_naissance, sexe, telephone, adresse, created_at, archived, medecin_id) VALUES
(1, 2, '2002-04-17', 'F', '776543421', 'guediawaye', '2025-04-24 13:53:45', 0, 1),
(2, 3, '2023-10-13', 'M', '765432189', 'dakar', '2025-04-24 14:07:09', 0, 1),
(3, 9, '2025-04-03', 'F', '774142626', 'grand mbao', '2025-04-30 14:33:18', 0, 1),
(4, 10, '2025-04-05', 'F', '776543213', 'thies', '2025-04-30 14:34:38', 0, 1),
(5, 11, '2025-05-10', 'M', '709875643', 'louga', '2025-04-30 14:36:22', 0, 1);

-- Insert data into consultations
INSERT INTO consultations (id, patient_id, medecin_id, date_consultation, diagnostic, fichier) VALUES
(1, 1, 1, '2024-06-15 10:30:00', 'Hypertension artérielle', NULL),
(2, 1, 1, '2024-07-01 11:00:00', 'Suivi post-traitement', NULL),
(3, 1, 1, '2024-08-10 09:45:00', 'Contrôle général', NULL),
(4, 2, 1, '2024-09-01 14:00:00', 'Pneumonie légère détectée', NULL),
(5, 2, 1, '2024-09-15 09:30:00', 'Suivi après traitement antibiotique', NULL),
(6, 2, 1, '2024-10-01 11:00:00', 'Contrôle général, état stable', NULL),
(7, 3, 1, '2024-07-01 10:00:00', 'Céphalées fréquentes', NULL),
(8, 3, 1, '2024-07-15 13:00:00', 'Suivi neurologique recommandé', NULL),
(9, 3, 1, '2024-08-01 15:00:00', 'Aucune anomalie nouvelle', NULL),
(10, 4, 1, '2024-10-01 08:30:00', 'Douleurs abdominales', NULL),
(11, 4, 1, '2024-10-10 10:00:00', 'Suivi après échographie', NULL),
(12, 4, 1, '2024-10-20 12:00:00', 'Amélioration notable', NULL),
(13, 5, 1, '2024-08-15 09:00:00', 'Fatigue chronique', NULL),
(14, 5, 1, '2024-08-25 11:30:00', 'Bilan sanguin recommandé', NULL),
(15, 5, 1, '2024-09-05 14:00:00', 'Résultats normaux', NULL),
(16, 2, 1, '2024-09-01 14:00:00', 'Pneumonie légère détectée', NULL),
(17, 2, 1, '2024-09-15 09:30:00', 'Suivi après traitement antibiotique', NULL),
(18, 2, 1, '2024-10-01 11:00:00', 'Contrôle général, état stable', NULL),
(19, 3, 1, '2024-07-01 10:00:00', 'Céphalées fréquentes', NULL),
(20, 3, 1, '2024-07-15 13:00:00', 'Suivi neurologique recommandé', NULL),
(21, 3, 1, '2024-08-01 15:00:00', 'Aucune anomalie nouvelle', NULL),
(22, 4, 1, '2024-10-01 08:30:00', 'Douleurs abdominales', NULL),
(23, 4, 1, '2024-10-10 10:00:00', 'Suivi après échographie', NULL),
(24, 4, 1, '2024-10-20 12:00:00', 'Amélioration notable', NULL),
(25, 5, 1, '2024-08-15 09:00:00', 'Fatigue chronique', NULL),
(26, 5, 1, '2024-08-25 11:30:00', 'Bilan sanguin recommandé', NULL),
(27, 5, 1, '2024-09-05 14:00:00', 'Résultats normaux', NULL);

-- Insert data into prescriptions
INSERT INTO prescriptions (id, consultation_id, details, created_at) VALUES
(1, 1, 'Paracetamol 500mg, 3 times daily for 5 days.', '2025-05-31 01:22:00'),
(2, 2, 'Amoxicillin 500mg, twice daily for 7 days.', '2025-05-31 01:22:00'),
(3, 3, 'Ibuprofen 400mg, as needed for pain.', '2025-05-31 01:22:00'),
(4, 4, 'Augmentin 1g, twice daily for 7 days.', '2025-06-01 11:40:00'),
(5, 5, 'Paracetamol 1g, as needed for fever.', '2025-06-01 11:40:00'),
(6, 6, 'Hydration and rest recommended.', '2025-06-01 11:40:00'),
(7, 7, 'Sumatriptan 50mg, for migraines.', '2025-06-01 11:40:00'),
(8, 8, 'Referral to neurologist.', '2025-06-01 11:40:00'),
(9, 9, 'Continue monitoring.', '2025-06-01 11:40:00'),
(10, 10, 'Omeprazole 20mg, daily for 14 days.', '2025-06-01 11:40:00'),
(11, 11, 'Follow-up in 1 month.', '2025-06-01 11:40:00'),
(12, 12, 'Probiotics recommended.', '2025-06-01 11:40:00'),
(13, 13, 'Iron supplements, 325mg daily.', '2025-06-01 11:40:00'),
(14, 14, 'Vitamin D 1000IU daily.', '2025-06-01 11:40:00'),
(15, 15, 'Continue current regimen.', '2025-06-01 11:40:00');

-- Insert data into medical_records
INSERT INTO medical_records (id, patient_id, medecin_id, date_enregistrement, type_enregistrement, description, cree_le) VALUES
(1, 1, 1, '2024-06-17', 'Suivi général', 'Regroupe les examens cardiovasculaires et bilans sanguins', '2025-05-28 18:15:29'),
(2, 2, 1, '2024-06-19', 'Dossier pédiatrique', 'Examens de routine chez l''enfant', '2025-05-28 18:15:29'),
(3, 3, 1, '2024-06-21', 'Suivi neurologique', 'IRM cérébrale et échographies', '2025-05-28 18:15:29'),
(4, 4, 1, '2024-06-23', 'Contrôle complet', 'Radio, ECG et scanner pour bilan global', '2025-05-28 18:15:29'),
(5, 5, 1, '2024-06-25', 'Bilan général', 'IRM lombaire et scanner abdominal', '2025-05-28 18:15:29');

-- Insert data into dossier
INSERT INTO dossier (id, patient_id, description, created_at) VALUES
(1, 1, 'Tachycardie détectée lors de l''ECG du 16 juin', '2025-05-28 18:18:16'),
(2, 1, 'Cholestérol élevé au bilan du 2 juillet', '2025-05-28 18:18:16'),
(3, 2, 'Inflammation détectée à la radio pédiatrique', '2025-05-28 18:18:16'),
(4, 3, 'IRM cérébrale normale', '2025-05-28 18:18:16'),
(5, 4, 'ECG stable, à surveiller', '2025-05-28 18:18:16'),
(6, 5, 'Anomalie mineure détectée au scanner', '2025-05-28 18:18:16');

-- Insert data into examens
INSERT INTO examens (id, patient_id, medecin_id, radiologue_id, type_examen, date_examen, resultat, fichier_dicom, statut, created_at) VALUES
(1, 1, 1, NULL, 'ECG', '2024-06-16', 'Tachycardie sinusale détectée', 'ecg_p1.dcm', 'termine', '2025-05-28 18:06:17'),
(2, 1, 1, NULL, 'Bilan sanguin', '2024-07-02', 'Cholestérol légèrement élevé', 'bilan_p1.dcm', 'termine', '2025-05-28 18:06:17'),
(3, 1, 1, NULL, 'IRM cérébrale', '2024-08-12', 'Normale', 'irm_p1.dcm', 'termine', '2025-05-28 18:06:17'),
(4, 2, 1, NULL, 'Radiographie thoracique', '2024-06-20', 'Infiltrats pulmonaires légers', 'radio_p2.dcm', 'termine', '2025-05-28 18:06:30'),
(5, 2, 1, NULL, 'Scanner abdominal', '2024-07-10', 'Aucune anomalie détectée', 'scanner_p2.dcm', 'termine', '2025-05-28 18:06:30'),
(6, 3, 1, NULL, 'Échographie rénale', '2024-05-30', 'Rein gauche légèrement hypertrophié', 'echo_p3.dcm', 'termine', '2025-05-28 18:06:41'),
(7, 4, 1, NULL, 'Bilan hépatique', '2024-09-01', 'Fonction hépatique normale', 'bilan_p4.dcm', 'termine', '2025-05-28 18:06:54'),
(8, 4, 1, NULL, 'IRM abdominale', '2024-09-10', 'Présence de petits kystes bénins', 'irm_p4.dcm', 'termine', '2025-05-28 18:06:54'),
(9, 5, 1, NULL, 'Scanner cérébral', '2024-08-01', 'Lésion bénigne détectée', 'scanner_p5.dcm', 'termine', '2025-05-28 18:07:05');

-- Insert data into rendezvous
INSERT INTO rendezvous (id, patient_id, medecin_id, date_rdv, motif, statut, created_at) VALUES
(1, 1, 1, '2024-06-15 10:00:00', 'Consultation initiale', 'termine', '2025-05-28 18:18:34'),
(2, 1, 1, '2024-07-01 09:30:00', 'Suivi traitement', 'termine', '2025-05-28 18:18:34'),
(3, 1, 1, '2024-08-10 11:00:00', 'Contrôle général', 'termine', '2025-05-28 18:18:34'),
(4, 2, 1, '2024-07-05 14:00:00', 'Consultation pédiatrique', 'termine', '2025-05-28 18:18:34'),
(5, 3, 1, '2024-08-12 15:00:00', 'IRM neurologique', 'termine', '2025-05-28 18:18:34'),
(6, 4, 1, '2024-09-01 13:00:00', 'Consultation de routine', 'termine', '2025-05-28 18:18:34'),
(7, 5, 1, '2024-09-10 09:00:00', 'Analyse scanner', 'termine', '2025-05-28 18:18:34');

