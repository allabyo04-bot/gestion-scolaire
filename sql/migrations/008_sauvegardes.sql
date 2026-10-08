-- 008 — Historique des sauvegardes de la base
SET NAMES utf8mb4;
CREATE TABLE sauvegardes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type          ENUM('NOCTURNE','MANUELLE') NOT NULL,
  statut        ENUM('REUSSIE','ECHEC') NOT NULL,
  taille_octets INT UNSIGNED NULL,
  nb_tables     SMALLINT UNSIGNED NULL,
  nb_lignes     INT UNSIGNED NULL,
  destination   VARCHAR(150) NULL,                          -- adresse e-mail, ou « téléchargement »
  message       VARCHAR(255) NULL,
  utilisateur_id INT UNSIGNED NULL,
  cree_le       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sv_date (cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
