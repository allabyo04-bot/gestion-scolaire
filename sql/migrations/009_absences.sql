-- =====================================================================
--  009 — Absences et retards des élèves
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE absences (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscription_id  INT UNSIGNED NOT NULL,
  ecole_id        INT UNSIGNED NOT NULL,
  classe_id       INT UNSIGNED NOT NULL,
  date_absence    DATE NOT NULL,
  creneau         ENUM('MATIN','APRES_MIDI') NOT NULL,
  statut          ENUM('ABSENT','RETARD') NOT NULL,
  heures          DECIMAL(3,1) NULL,                        -- heures de cours manquées (absence)
  minutes         SMALLINT UNSIGNED NULL,                   -- minutes de retard
  justifiee       TINYINT(1) NOT NULL DEFAULT 0,
  motif           VARCHAR(200) NULL,
  justifiee_par   INT UNSIGNED NULL,
  justifiee_le    DATETIME NULL,
  saisi_par       INT UNSIGNED NOT NULL,
  saisi_le        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modifie_par     INT UNSIGNED NULL,
  modifie_le      DATETIME NULL,
  UNIQUE KEY uq_absence (inscription_id, date_absence, creneau),
  KEY idx_ab_classe (classe_id, date_absence),
  KEY idx_ab_ecole (ecole_id, justifiee, date_absence),
  CONSTRAINT fk_ab_insc   FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_ab_ecole  FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT fk_ab_classe FOREIGN KEY (classe_id) REFERENCES classes(id),
  CONSTRAINT fk_ab_saisi  FOREIGN KEY (saisi_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_ab_just   FOREIGN KEY (justifiee_par) REFERENCES utilisateurs(id),
  CONSTRAINT chk_ab_valeurs CHECK ((statut = 'ABSENT' AND heures > 0) OR (statut = 'RETARD' AND minutes > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durée habituelle d'une demi-journée (modifiable dans Paramètres > Année et périodes)
ALTER TABLE parametres_annee ADD COLUMN heures_matin DECIMAL(3,1) NOT NULL DEFAULT 4.0;
ALTER TABLE parametres_annee ADD COLUMN heures_apres_midi DECIMAL(3,1) NOT NULL DEFAULT 2.0;

-- Dates des trimestres 2026-2027 (seulement là où elles ne sont pas encore renseignées)
UPDATE periodes p JOIN annees_scolaires a ON a.id = p.annee_id SET p.date_debut = '2026-09-14', p.date_fin = '2026-12-18'
  WHERE a.libelle = '2026-2027' AND p.numero = 1 AND p.date_debut IS NULL AND p.libelle LIKE '%trimestre%';
UPDATE periodes p JOIN annees_scolaires a ON a.id = p.annee_id SET p.date_debut = '2027-01-04', p.date_fin = '2027-03-26'
  WHERE a.libelle = '2026-2027' AND p.numero = 2 AND p.date_debut IS NULL AND p.libelle LIKE '%trimestre%';
UPDATE periodes p JOIN annees_scolaires a ON a.id = p.annee_id SET p.date_debut = '2027-04-12', p.date_fin = '2027-07-16'
  WHERE a.libelle = '2026-2027' AND p.numero = 3 AND p.date_debut IS NULL AND p.libelle LIKE '%trimestre%';
