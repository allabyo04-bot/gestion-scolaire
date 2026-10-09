-- =====================================================================
--  011 — Logo, cachet et signature des écoles ; coefficients à confirmer
-- =====================================================================
SET NAMES utf8mb4;

-- Images stockées dans la base (le disque du serveur Railway est effacé à chaque déploiement)
CREATE TABLE ecole_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id    INT UNSIGNED NOT NULL,
  type        ENUM('LOGO','CACHET','SIGNATURE') NOT NULL,
  donnees     MEDIUMTEXT NOT NULL,                       -- image en « data URL » (base64), redimensionnée par le site
  modifie_par INT UNSIGNED NULL,
  modifie_le  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_image (ecole_id, type),
  CONSTRAINT fk_img_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coefficient provisoire (chargé sans source officielle) : à confirmer par la direction
ALTER TABLE classe_matieres ADD COLUMN coef_a_confirmer TINYINT(1) NOT NULL DEFAULT 0;
