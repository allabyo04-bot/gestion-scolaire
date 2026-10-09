-- =====================================================================
--  010 — Remise de caisse journalière (par caissier et par jour)
--  Photo des encaissements au moment de la remise, espèces comptées,
--  écart, puis confirmation de réception par la direction.
-- =====================================================================
SET NAMES utf8mb4;
CREATE TABLE remises_caisse (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id              INT UNSIGNED NOT NULL,
  caissier_id           INT UNSIGNED NOT NULL,
  date_caisse           DATE NOT NULL,
  nb_recus              SMALLINT UNSIGNED NOT NULL,
  total_systeme         INT UNSIGNED NOT NULL,              -- total encaissé selon le logiciel (tous modes)
  total_especes         INT UNSIGNED NOT NULL,              -- dont espèces selon le logiciel
  par_mode              VARCHAR(400) NOT NULL,              -- détail par mode (JSON)
  especes_comptees      INT UNSIGNED NOT NULL,              -- espèces réellement remises
  ecart                 INT NOT NULL,                       -- espèces comptées - espèces du logiciel
  commentaire           VARCHAR(255) NULL,
  statut                ENUM('REMISE','CONFIRMEE') NOT NULL DEFAULT 'REMISE',
  remise_le             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmee_par         INT UNSIGNED NULL,
  confirmee_le          DATETIME NULL,
  commentaire_direction VARCHAR(255) NULL,
  UNIQUE KEY uq_remise (ecole_id, caissier_id, date_caisse),
  CONSTRAINT fk_rc_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT fk_rc_caissier FOREIGN KEY (caissier_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_rc_conf FOREIGN KEY (confirmee_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
