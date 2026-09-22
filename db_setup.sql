-- Aucun mot de passe ne doit être versionné dans ce fichier.
-- Créez d'abord l'utilisateur `mdm_user` avec un secret fourni par le
-- gestionnaire de secrets de l'environnement, puis exécutez ce script.

CREATE DATABASE IF NOT EXISTS mdm_2isy
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON mdm_2isy.* TO 'mdm_user'@'localhost';
FLUSH PRIVILEGES;
