-- =====================================================================
--  005 — Module finances : tarifs, tranches, remises, paiements, reçus
-- =====================================================================
SET NAMES utf8mb4;

-- Tarif annuel d'un niveau dans une école
CREATE TABLE tarifs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id    INT UNSIGNED NOT NULL,
  annee_id    INT UNSIGNED NOT NULL,
  niveau_id   INT UNSIGNED NOT NULL,
  montant     INT UNSIGNED NOT NULL,                       -- en francs CFA
  observation VARCHAR(200) NULL,                           -- ex : Tout frais compris
  modifie_le  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tarif (ecole_id, annee_id, niveau_id),
  CONSTRAINT fk_ta_ecole  FOREIGN KEY (ecole_id)  REFERENCES ecoles(id),
  CONSTRAINT fk_ta_annee  FOREIGN KEY (annee_id)  REFERENCES annees_scolaires(id),
  CONSTRAINT fk_ta_niveau FOREIGN KEY (niveau_id) REFERENCES niveaux(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Découpage du tarif en tranches (la somme des tranches = le montant du tarif)
CREATE TABLE tarif_tranches (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tarif_id  INT UNSIGNED NOT NULL,
  numero    TINYINT UNSIGNED NOT NULL,
  libelle   VARCHAR(60) NOT NULL,                          -- ex : 1re tranche (inscription)
  montant   INT UNSIGNED NOT NULL,
  echeance  DATE NULL,                                     -- vide : pas de retard possible
  UNIQUE KEY uq_tranche (tarif_id, numero),
  CONSTRAINT fk_tt_tarif FOREIGN KEY (tarif_id) REFERENCES tarifs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réductions accordées à un élève pour l'année (fratrie, enfant du personnel…)
CREATE TABLE remises (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscription_id  INT UNSIGNED NOT NULL,
  montant         INT UNSIGNED NOT NULL,
  motif           VARCHAR(200) NOT NULL,
  accordee_par    INT UNSIGNED NOT NULL,
  accordee_le     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_re_insc FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_re_user FOREIGN KEY (accordee_par) REFERENCES utilisateurs(id),
  CONSTRAINT chk_re_montant CHECK (montant > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements : jamais supprimés, seulement annulés (avec motif)
CREATE TABLE paiements (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero_recu        VARCHAR(30) NOT NULL UNIQUE,          -- ex : KANDI-26-R00001
  inscription_id     INT UNSIGNED NOT NULL,
  ecole_id           INT UNSIGNED NOT NULL,
  annee_id           INT UNSIGNED NOT NULL,
  montant            INT UNSIGNED NOT NULL,
  mode               ENUM('ESPECES','MOBILE_MONEY','VIREMENT','CHEQUE') NOT NULL,
  reference          VARCHAR(80) NULL,                     -- n° de transaction, de chèque…
  verse_par          VARCHAR(120) NULL,                    -- nom de la personne qui paie
  date_paiement      DATE NOT NULL,
  reste_apres        INT UNSIGNED NOT NULL DEFAULT 0,      -- reste à payer juste après ce paiement (figé pour le reçu)
  encaisse_par       INT UNSIGNED NOT NULL,
  encaisse_le        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  annule             TINYINT(1) NOT NULL DEFAULT 0,
  annule_par         INT UNSIGNED NULL,
  annule_le          DATETIME NULL,
  motif_annulation   VARCHAR(200) NULL,
  KEY idx_pa_insc (inscription_id),
  KEY idx_pa_jour (ecole_id, date_paiement),
  CONSTRAINT fk_pa_insc  FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_pa_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT fk_pa_annee FOREIGN KEY (annee_id) REFERENCES annees_scolaires(id),
  CONSTRAINT fk_pa_enc   FOREIGN KEY (encaisse_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_pa_ann   FOREIGN KEY (annule_par) REFERENCES utilisateurs(id),
  CONSTRAINT chk_pa_montant CHECK (montant > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
