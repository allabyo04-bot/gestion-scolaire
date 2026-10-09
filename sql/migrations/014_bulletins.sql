-- =====================================================================
--  014 — Bulletins : type DTL, réglages du bulletin, second logo, site web
-- =====================================================================
SET NAMES utf8mb4;

-- DTL : compte comme un devoir dans la moyenne, affiché dans sa propre colonne
ALTER TABLE evaluations MODIFY type ENUM('INTERRO','DEVOIR','DTL') NOT NULL;

CREATE OR REPLACE VIEW v_notes_effectives AS
SELECT n.inscription_id, e.periode_id, e.classe_matiere_id, e.type,
       CASE n.statut WHEN 'NOTE' THEN n.valeur WHEN 'ABSENT_NON_JUSTIFIE' THEN 0 END AS valeur
FROM notes n JOIN evaluations e ON e.id = n.evaluation_id
WHERE e.statut = 'VALIDEE' AND n.statut IN ('NOTE','ABSENT_NON_JUSTIFIE');

CREATE OR REPLACE VIEW v_moyennes_matiere AS
SELECT x.inscription_id, x.periode_id, x.classe_matiere_id, cm.coefficient,
       x.moy_interros, x.somme_devoirs, x.nb_devoirs,
       ROUND((COALESCE(x.moy_interros,0) + x.somme_devoirs) / ((x.moy_interros IS NOT NULL) + x.nb_devoirs), 2) AS moyenne,
       ROUND(ROUND((COALESCE(x.moy_interros,0) + x.somme_devoirs) / ((x.moy_interros IS NOT NULL) + x.nb_devoirs), 2) * cm.coefficient, 2) AS points
FROM (
  SELECT inscription_id, periode_id, classe_matiere_id,
         AVG(CASE WHEN type = 'INTERRO' THEN valeur END) AS moy_interros,
         COALESCE(SUM(CASE WHEN type IN ('DEVOIR','DTL') THEN valeur END), 0) AS somme_devoirs,
         COUNT(CASE WHEN type IN ('DEVOIR','DTL') THEN 1 END) AS nb_devoirs
  FROM v_notes_effectives GROUP BY inscription_id, periode_id, classe_matiere_id
) x JOIN classe_matieres cm ON cm.id = x.classe_matiere_id;

ALTER TABLE ecoles ADD COLUMN site_web VARCHAR(150) NULL;
ALTER TABLE ecole_images MODIFY type ENUM('LOGO','CACHET','SIGNATURE','LOGO_FONDATION') NOT NULL;

-- Réglages du bulletin, par école (tout est modifiable dans Paramètres > Bulletins)
CREATE TABLE parametres_bulletin (
  ecole_id              INT UNSIGNED PRIMARY KEY,
  appreciations         VARCHAR(600) NOT NULL,             -- barème JSON : [[seuil, libellé], …] du plus haut au plus bas
  seuil_felicitations   DECIMAL(4,2) NULL,
  seuil_encouragements  DECIMAL(4,2) NULL,
  seuil_tableau         DECIMAL(4,2) NULL,
  afficher_absences     TINYINT(1) NOT NULL DEFAULT 0,
  titre_signataire      VARCHAR(80) NOT NULL DEFAULT 'Le Chef d''établissement',
  nom_signataire        VARCHAR(120) NULL,
  note_bas              VARCHAR(200) NULL,
  a_confirmer           TINYINT(1) NOT NULL DEFAULT 1,     -- valeurs par défaut pas encore validées par l'école
  CONSTRAINT fk_pb_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO parametres_bulletin (ecole_id, appreciations, seuil_felicitations, seuil_encouragements, seuil_tableau, titre_signataire, nom_signataire, note_bas)
SELECT id, '[[18,"Excellent"],[16,"Très bien"],[14,"Bien"],[12,"Assez bien"],[10,"Passable"],[8,"Médiocre"],[0,"Insuffisant"]]',
       16, 14, 12, 'Le Chef d''établissement', nom_directrice, 'NB : Conservez précieusement ce bulletin. Il ne sera délivré aucun duplicata.'
FROM ecoles;
