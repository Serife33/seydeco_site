-- Base de test (suffixe _test ajouté par doctrine.yaml en env test), exécuté une seule fois à la création du volume
CREATE DATABASE IF NOT EXISTS `app_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `app_test`.* TO 'app'@'%';
