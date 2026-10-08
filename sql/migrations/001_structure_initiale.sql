-- =====================================================================
--  GESTION SCOLAIRE MULTI-ÉCOLES — Réseau du Père Théodore
--  (Kandi / Champagnart, Parakou, N'Dali, ...)
--  Noyau : écoles, utilisateurs & rôles, classes, matières,
--          évaluations paramétrables, notes, verrouillage, audit.
--  Compatible MariaDB 10.4+ / MySQL 8.0.16+  —  InnoDB, utf8mb4
--  Version 1 — octobre 2026
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. ÉCOLES (chaque site a son nom, son entête, son logo)
-- ---------------------------------------------------------------------
CREATE TABLE ecoles (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(20)  NOT NULL UNIQUE,          -- ex : KANDI, PARAKOU, NDALI
  nom_officiel      VARCHAR(200) NOT NULL,                 -- ex : École Champagnart
  sigle             VARCHAR(30)  NULL,
  ville             VARCHAR(100) NOT NULL,
  adresse           VARCHAR(255) NULL,
  boite_postale     VARCHAR(50)  NULL,
  telephone         VARCHAR(50)  NULL,
  email             VARCHAR(150) NULL,
  -- Entête du bulletin (lignes libres, ex : ministère, direction départementale)
  entete_ligne1     VARCHAR(200) NULL,
  entete_ligne2     VARCHAR(200) NULL,
  entete_ligne3     VARCHAR(200) NULL,
  devise            VARCHAR(200) NULL,
  logo_chemin       VARCHAR(255) NULL,
  cachet_chemin     VARCHAR(255) NULL,
  signature_chemin  VARCHAR(255) NULL,
  nom_directrice    VARCHAR(150) NULL,                     -- imprimé au bas du bulletin
  titre_signataire  VARCHAR(100) NOT NULL DEFAULT 'La Directrice',
  actif             TINYINT(1)   NOT NULL DEFAULT 1,
  cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modifie_le        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. UTILISATEURS & RÔLES
--    SUPER_ADMIN : toutes les écoles (ecole_id vide)
--    Tous les autres : rattachés obligatoirement à UNE école
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id              INT UNSIGNED NULL,
  role                  ENUM('SUPER_ADMIN','DIRECTRICE','SECRETARIAT','COMPTABLE','PROFESSEUR') NOT NULL,
  nom                   VARCHAR(100) NOT NULL,
  prenoms               VARCHAR(150) NOT NULL,
  telephone             VARCHAR(30)  NULL,
  email                 VARCHAR(150) NULL,
  identifiant           VARCHAR(60)  NOT NULL UNIQUE,
  mot_de_passe_hash     VARCHAR(255) NOT NULL,             -- password_hash() PHP, jamais en clair
  doit_changer_mdp      TINYINT(1)   NOT NULL DEFAULT 1,
  actif                 TINYINT(1)   NOT NULL DEFAULT 1,
  tentatives_echouees   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloque_jusqu_a        DATETIME     NULL,
  derniere_connexion    DATETIME     NULL,
  cree_le               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cree_par              INT UNSIGNED NULL,
  CONSTRAINT fk_util_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT chk_util_rattachement CHECK (
       (role = 'SUPER_ADMIN' AND ecole_id IS NULL)
    OR (role <> 'SUPER_ADMIN' AND ecole_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ANNÉES SCOLAIRES & PÉRIODES (trimestres ou semestres, paramétrable)
-- ---------------------------------------------------------------------
CREATE TABLE annees_scolaires (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  libelle     VARCHAR(20) NOT NULL UNIQUE,                 -- ex : 2026-2027
  date_debut  DATE NOT NULL,
  date_fin    DATE NOT NULL,
  en_cours    TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres de calcul propres à une école pour une année
CREATE TABLE parametres_annee (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id             INT UNSIGNED NOT NULL,
  annee_id             INT UNSIGNED NOT NULL,
  type_periode         ENUM('TRIMESTRE','SEMESTRE') NOT NULL DEFAULT 'TRIMESTRE',
  decimales            TINYINT UNSIGNED NOT NULL DEFAULT 2,
  moyenne_passage      DECIMAL(4,2) NOT NULL DEFAULT 10.00,
  mode_moy_annuelle    ENUM('SIMPLE','PONDEREE') NOT NULL DEFAULT 'SIMPLE',
  UNIQUE KEY uq_param (ecole_id, annee_id),
  CONSTRAINT fk_param_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT fk_param_annee FOREIGN KEY (annee_id) REFERENCES annees_scolaires(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE periodes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id    INT UNSIGNED NOT NULL,
  annee_id    INT UNSIGNED NOT NULL,
  numero      TINYINT UNSIGNED NOT NULL,                   -- 1, 2, 3
  libelle     VARCHAR(50) NOT NULL,                        -- ex : 1er Trimestre
  poids       DECIMAL(4,2) NOT NULL DEFAULT 1.00,          -- utilisé si moyenne annuelle PONDEREE
  date_debut  DATE NULL,
  date_fin    DATE NULL,
  statut      ENUM('OUVERTE','CLOTUREE') NOT NULL DEFAULT 'OUVERTE',
  UNIQUE KEY uq_periode (ecole_id, annee_id, numero),
  CONSTRAINT fk_per_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id),
  CONSTRAINT fk_per_annee FOREIGN KEY (annee_id) REFERENCES annees_scolaires(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. RÉFÉRENTIELS : niveaux, séries, matières
-- ---------------------------------------------------------------------
CREATE TABLE niveaux (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(10) NOT NULL UNIQUE,
  libelle  VARCHAR(50) NOT NULL,
  cycle    ENUM('MATERNELLE','PRIMAIRE','COLLEGE','LYCEE') NOT NULL,
  ordre    TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE series (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(10) NOT NULL UNIQUE,                    -- A1, A2, B, C, D ...
  libelle  VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matieres (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20)  NOT NULL UNIQUE,
  libelle        VARCHAR(100) NOT NULL,                    -- ex : Mathématiques
  libelle_court  VARCHAR(30)  NULL,                        -- ex : Maths
  est_conduite   TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. CLASSES et MATIÈRES DE CLASSE (coefficient + professeur)
-- ---------------------------------------------------------------------
CREATE TABLE classes (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id              INT UNSIGNED NOT NULL,
  annee_id              INT UNSIGNED NOT NULL,
  niveau_id             INT UNSIGNED NOT NULL,
  serie_id              INT UNSIGNED NULL,
  nom                   VARCHAR(50)  NOT NULL,             -- ex : 6ème A, Tle D
  prof_principal_id     INT UNSIGNED NULL,
  effectif_max          SMALLINT UNSIGNED NULL,
  UNIQUE KEY uq_classe (ecole_id, annee_id, nom),
  CONSTRAINT fk_cl_ecole  FOREIGN KEY (ecole_id)  REFERENCES ecoles(id),
  CONSTRAINT fk_cl_annee  FOREIGN KEY (annee_id)  REFERENCES annees_scolaires(id),
  CONSTRAINT fk_cl_niveau FOREIGN KEY (niveau_id) REFERENCES niveaux(id),
  CONSTRAINT fk_cl_serie  FOREIGN KEY (serie_id)  REFERENCES series(id),
  CONSTRAINT fk_cl_pp     FOREIGN KEY (prof_principal_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE classe_matieres (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  classe_id        INT UNSIGNED NOT NULL,
  matiere_id       INT UNSIGNED NOT NULL,
  coefficient      DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  professeur_id    INT UNSIGNED NULL,                      -- le prof qui saisit les notes
  groupe_bulletin  VARCHAR(60)  NULL,                      -- ex : Matières littéraires
  ordre_affichage  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  actif            TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cm (classe_id, matiere_id),
  CONSTRAINT fk_cm_classe  FOREIGN KEY (classe_id)  REFERENCES classes(id),
  CONSTRAINT fk_cm_matiere FOREIGN KEY (matiere_id) REFERENCES matieres(id),
  CONSTRAINT fk_cm_prof    FOREIGN KEY (professeur_id) REFERENCES utilisateurs(id),
  CONSTRAINT chk_cm_coef   CHECK (coefficient > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. PARAMÉTRAGE DES ÉVALUATIONS (rien n'est codé en dur)
--    a) Modèle par défaut (école, éventuellement par niveau)
--    b) Évaluations réelles : une ligne par interro / devoir.
--       Le NOMBRE d'interros et de devoirs = le nombre de lignes,
--       différent possible pour chaque matière, classe et période.
-- ---------------------------------------------------------------------
CREATE TABLE config_evaluation_defaut (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecole_id     INT UNSIGNED NOT NULL,
  niveau_id    INT UNSIGNED NULL,                          -- vide = tous les niveaux
  nb_interros  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  nb_devoirs   TINYINT UNSIGNED NOT NULL DEFAULT 2,
  UNIQUE KEY uq_cfg (ecole_id, niveau_id),
  CONSTRAINT fk_cfg_ecole  FOREIGN KEY (ecole_id)  REFERENCES ecoles(id),
  CONSTRAINT fk_cfg_niveau FOREIGN KEY (niveau_id) REFERENCES niveaux(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluations (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  classe_matiere_id  INT UNSIGNED NOT NULL,
  periode_id         INT UNSIGNED NOT NULL,
  type               ENUM('INTERRO','DEVOIR') NOT NULL,
  numero             TINYINT UNSIGNED NOT NULL,            -- Interro 1, 2, 3... / Devoir 1, 2
  date_evaluation    DATE NULL,
  statut             ENUM('BROUILLON','VALIDEE') NOT NULL DEFAULT 'BROUILLON',
  validee_par        INT UNSIGNED NULL,
  validee_le         DATETIME NULL,
  UNIQUE KEY uq_eval (classe_matiere_id, periode_id, type, numero),
  CONSTRAINT fk_ev_cm   FOREIGN KEY (classe_matiere_id) REFERENCES classe_matieres(id),
  CONSTRAINT fk_ev_per  FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_ev_val  FOREIGN KEY (validee_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. ÉLÈVES, TUTEURS, INSCRIPTIONS
-- ---------------------------------------------------------------------
CREATE TABLE eleves (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  educmaster       VARCHAR(10)  NULL UNIQUE,               -- chiffres uniquement, 6 à 10
  matricule        VARCHAR(30)  NOT NULL UNIQUE,           -- matricule interne au réseau
  nom              VARCHAR(100) NOT NULL,
  prenoms          VARCHAR(150) NOT NULL,
  sexe             ENUM('M','F') NOT NULL,
  date_naissance   DATE NULL,
  lieu_naissance   VARCHAR(100) NULL,
  nationalite      VARCHAR(60)  NULL DEFAULT 'Béninoise',
  photo_chemin     VARCHAR(255) NULL,
  cree_le          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_educmaster CHECK (educmaster IS NULL OR educmaster REGEXP '^[0-9]{6,10}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tuteurs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom        VARCHAR(100) NOT NULL,
  prenoms    VARCHAR(150) NULL,
  telephone  VARCHAR(30)  NOT NULL,                        -- sert aussi de login portail parents
  email      VARCHAR(150) NULL,
  profession VARCHAR(100) NULL,
  KEY idx_tut_tel (telephone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eleve_tuteurs (
  eleve_id   INT UNSIGNED NOT NULL,
  tuteur_id  INT UNSIGNED NOT NULL,
  lien       ENUM('PERE','MERE','TUTEUR','AUTRE') NOT NULL DEFAULT 'TUTEUR',
  principal  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (eleve_id, tuteur_id),
  CONSTRAINT fk_et_eleve  FOREIGN KEY (eleve_id)  REFERENCES eleves(id),
  CONSTRAINT fk_et_tuteur FOREIGN KEY (tuteur_id) REFERENCES tuteurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inscriptions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eleve_id          INT UNSIGNED NOT NULL,
  classe_id         INT UNSIGNED NOT NULL,
  ecole_id          INT UNSIGNED NOT NULL,                 -- recopié de la classe (filtrage rapide)
  annee_id          INT UNSIGNED NOT NULL,                 -- recopié de la classe (unicité)
  date_inscription  DATE NOT NULL,
  redoublant        TINYINT(1) NOT NULL DEFAULT 0,
  statut            ENUM('ACTIF','TRANSFERE','ABANDON','EXCLU') NOT NULL DEFAULT 'ACTIF',
  UNIQUE KEY uq_insc (eleve_id, annee_id),                 -- une seule classe par an
  CONSTRAINT fk_in_eleve  FOREIGN KEY (eleve_id)  REFERENCES eleves(id),
  CONSTRAINT fk_in_classe FOREIGN KEY (classe_id) REFERENCES classes(id),
  CONSTRAINT fk_in_ecole  FOREIGN KEY (ecole_id)  REFERENCES ecoles(id),
  CONSTRAINT fk_in_annee  FOREIGN KEY (annee_id)  REFERENCES annees_scolaires(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. NOTES + HISTORIQUE DES MODIFICATIONS
--    statut ABSENT               : signalé par le prof, en attente (non compté)
--    statut ABSENT_JUSTIFIE      : exclu du calcul
--    statut ABSENT_NON_JUSTIFIE  : compté 0 (décision directrice)
-- ---------------------------------------------------------------------
CREATE TABLE notes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluation_id   INT UNSIGNED NOT NULL,
  inscription_id  INT UNSIGNED NOT NULL,
  valeur          DECIMAL(4,2) NULL,
  statut          ENUM('NOTE','ABSENT','ABSENT_JUSTIFIE','ABSENT_NON_JUSTIFIE','DISPENSE') NOT NULL DEFAULT 'NOTE',
  saisie_par      INT UNSIGNED NOT NULL,
  saisie_le       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modifie_par     INT UNSIGNED NULL,
  modifie_le      DATETIME NULL,
  UNIQUE KEY uq_note (evaluation_id, inscription_id),
  CONSTRAINT fk_no_eval  FOREIGN KEY (evaluation_id)  REFERENCES evaluations(id),
  CONSTRAINT fk_no_insc  FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_no_saisi FOREIGN KEY (saisie_par)  REFERENCES utilisateurs(id),
  CONSTRAINT fk_no_modif FOREIGN KEY (modifie_par) REFERENCES utilisateurs(id),
  CONSTRAINT chk_note_valeur CHECK (
       (statut = 'NOTE' AND valeur IS NOT NULL AND valeur BETWEEN 0 AND 20)
    OR (statut <> 'NOTE' AND valeur IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Toute modification d'une note VALIDÉE passe par ici (motif obligatoire)
CREATE TABLE notes_historique (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id          INT UNSIGNED NOT NULL,
  ancienne_valeur  DECIMAL(4,2) NULL,
  ancien_statut    VARCHAR(25)  NOT NULL,
  nouvelle_valeur  DECIMAL(4,2) NULL,
  nouveau_statut   VARCHAR(25)  NOT NULL,
  motif            VARCHAR(255) NOT NULL,
  modifie_par      INT UNSIGNED NOT NULL,
  modifie_le       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nh_note  FOREIGN KEY (note_id) REFERENCES notes(id),
  CONSTRAINT fk_nh_user  FOREIGN KEY (modifie_par) REFERENCES utilisateurs(id),
  CONSTRAINT chk_nh_motif CHECK (CHAR_LENGTH(TRIM(motif)) >= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. RÉSULTATS DE PÉRIODE (figés à la publication du bulletin)
-- ---------------------------------------------------------------------
CREATE TABLE bulletins (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscription_id      INT UNSIGNED NOT NULL,
  periode_id          INT UNSIGNED NOT NULL,
  moyenne_generale    DECIMAL(5,2) NULL,
  rang                SMALLINT UNSIGNED NULL,
  ex_aequo            TINYINT(1) NOT NULL DEFAULT 0,
  effectif_classe     SMALLINT UNSIGNED NULL,
  absences_heures     SMALLINT UNSIGNED NULL,
  appreciation        VARCHAR(255) NULL,
  distinction         ENUM('AUCUNE','ENCOURAGEMENTS','TABLEAU_HONNEUR','FELICITATIONS') NOT NULL DEFAULT 'AUCUNE',
  sanction            ENUM('AUCUNE','AVERTISSEMENT','BLAME') NOT NULL DEFAULT 'AUCUNE',
  code_verification   CHAR(32) NULL UNIQUE,                -- pour le QR code anti-falsification
  statut              ENUM('BROUILLON','PUBLIE') NOT NULL DEFAULT 'BROUILLON',
  publie_par          INT UNSIGNED NULL,
  publie_le           DATETIME NULL,
  UNIQUE KEY uq_bull (inscription_id, periode_id),
  CONSTRAINT fk_bu_insc FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_bu_per  FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_bu_pub  FOREIGN KEY (publie_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. JOURNAL D'AUDIT (qui, quoi, quand, avant/après)
-- ---------------------------------------------------------------------
CREATE TABLE journal_audit (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id   INT UNSIGNED NULL,                      -- vide si échec de connexion inconnu
  identifiant_saisi VARCHAR(60) NULL,
  ecole_id         INT UNSIGNED NULL,
  action           VARCHAR(50)  NOT NULL,                  -- CONNEXION, ECHEC_CONNEXION, CREATION, MODIFICATION, SUPPRESSION, VALIDATION, IMPRESSION, PAIEMENT...
  table_cible      VARCHAR(60)  NULL,
  enregistrement_id BIGINT UNSIGNED NULL,
  description      VARCHAR(255) NULL,
  valeurs_avant    LONGTEXT NULL,                          -- JSON
  valeurs_apres    LONGTEXT NULL,                          -- JSON
  adresse_ip       VARCHAR(45)  NULL,
  navigateur       VARCHAR(255) NULL,
  cree_le          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ja_user  (utilisateur_id, cree_le),
  KEY idx_ja_ecole (ecole_id, cree_le),
  KEY idx_ja_cible (table_cible, enregistrement_id),
  CONSTRAINT fk_ja_user  FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_ja_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10 bis. JETONS DE CONNEXION (sessions de l'application)
--   Seule l'empreinte SHA-256 du jeton est stockée, jamais le jeton.
-- ---------------------------------------------------------------------
CREATE TABLE jetons (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id  INT UNSIGNED NOT NULL,
  empreinte       CHAR(64) NOT NULL UNIQUE,
  cree_le         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expire_le       DATETIME NOT NULL,
  derniere_activite DATETIME NULL,
  adresse_ip      VARCHAR(45) NULL,
  revoque         TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_jet_user (utilisateur_id),
  CONSTRAINT fk_jet_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 11. CALCUL DES MOYENNES (vue de référence)
--   Moyenne matière = (moyenne des interros + somme des devoirs)
--                     ÷ (1 + nombre de devoirs comptés)
--   - sans interro comptée : moyenne des devoirs seuls
--   - seules les évaluations VALIDÉES comptent
--   - ABSENT / ABSENT_JUSTIFIE / DISPENSE : exclus ; ABSENT_NON_JUSTIFIE : 0
--   Points = moyenne matière (arrondie) × coefficient
-- =====================================================================
CREATE OR REPLACE VIEW v_notes_effectives AS
SELECT n.inscription_id, e.periode_id, e.classe_matiere_id, e.type,
       CASE n.statut WHEN 'NOTE' THEN n.valeur
                     WHEN 'ABSENT_NON_JUSTIFIE' THEN 0 END AS valeur
FROM notes n
JOIN evaluations e ON e.id = n.evaluation_id
WHERE e.statut = 'VALIDEE'
  AND n.statut IN ('NOTE','ABSENT_NON_JUSTIFIE');

CREATE OR REPLACE VIEW v_moyennes_matiere AS
SELECT x.inscription_id, x.periode_id, x.classe_matiere_id,
       cm.coefficient,
       x.moy_interros, x.somme_devoirs, x.nb_devoirs,
       ROUND((COALESCE(x.moy_interros,0) + x.somme_devoirs)
             / ((x.moy_interros IS NOT NULL) + x.nb_devoirs), 2) AS moyenne,
       ROUND(ROUND((COALESCE(x.moy_interros,0) + x.somme_devoirs)
             / ((x.moy_interros IS NOT NULL) + x.nb_devoirs), 2) * cm.coefficient, 2) AS points
FROM (
  SELECT inscription_id, periode_id, classe_matiere_id,
         AVG(CASE WHEN type='INTERRO' THEN valeur END)            AS moy_interros,
         COALESCE(SUM(CASE WHEN type='DEVOIR' THEN valeur END),0) AS somme_devoirs,
         COUNT(CASE WHEN type='DEVOIR' THEN 1 END)                AS nb_devoirs
  FROM v_notes_effectives
  GROUP BY inscription_id, periode_id, classe_matiere_id
) x
JOIN classe_matieres cm ON cm.id = x.classe_matiere_id;

CREATE OR REPLACE VIEW v_moyennes_generales AS
SELECT inscription_id, periode_id,
       SUM(points)      AS total_points,
       SUM(coefficient) AS total_coefficients,
       ROUND(SUM(points) / SUM(coefficient), 2) AS moyenne_generale
FROM v_moyennes_matiere
GROUP BY inscription_id, periode_id;

-- =====================================================================
-- 12. DONNÉES DE RÉFÉRENCE
-- =====================================================================
INSERT INTO niveaux (code, libelle, cycle, ordre) VALUES
 ('PS','Petite Section','MATERNELLE',1), ('MS','Moyenne Section','MATERNELLE',2),
 ('GS','Grande Section','MATERNELLE',3),
 ('CI','CI','PRIMAIRE',4), ('CP','CP','PRIMAIRE',5), ('CE1','CE1','PRIMAIRE',6),
 ('CE2','CE2','PRIMAIRE',7), ('CM1','CM1','PRIMAIRE',8), ('CM2','CM2','PRIMAIRE',9),
 ('6E','6e','COLLEGE',10), ('5E','5e','COLLEGE',11),
 ('4E','4e','COLLEGE',12), ('3E','3e','COLLEGE',13),
 ('2NDE','2nde','LYCEE',14), ('1ERE','1re','LYCEE',15), ('TLE','Tle','LYCEE',16);

INSERT INTO series (code, libelle) VALUES
 ('A1','Série A1 — Lettres-Langues'), ('A2','Série A2 — Lettres-Sciences humaines'),
 ('B','Série B — Économie'), ('C','Série C — Mathématiques-Sciences physiques'),
 ('D','Série D — Mathématiques-Sciences de la nature');
