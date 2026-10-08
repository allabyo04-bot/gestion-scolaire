-- =====================================================================
--  002 — Corrections après réception des listes réelles des écoles
--  1. Numéro Educmaster : 12 ou 13 chiffres (et non 6 à 10)
--  2. Maternelle : « Maternelle 1 » et « Maternelle 2 » (2 années au Bénin)
-- =====================================================================
SET NAMES utf8mb4;

/*facultatif*/ ALTER TABLE eleves DROP CONSTRAINT chk_educmaster;
-- Les numéros saisis pendant les essais avec l'ancienne règle (6 à 10 chiffres) sont vidés
UPDATE eleves SET educmaster = NULL WHERE educmaster IS NOT NULL AND educmaster NOT REGEXP '^[0-9]{12,13}$';
ALTER TABLE eleves MODIFY educmaster VARCHAR(13) NULL;
ALTER TABLE eleves ADD CONSTRAINT chk_educmaster CHECK (educmaster IS NULL OR educmaster REGEXP '^[0-9]{12,13}$');

UPDATE niveaux SET code = 'MAT1', libelle = 'Maternelle 1' WHERE code = 'PS';
UPDATE niveaux SET code = 'MAT2', libelle = 'Maternelle 2' WHERE code = 'MS';
-- La « Grande Section » n'existe pas au Bénin : retirée si elle n'est utilisée nulle part
DELETE FROM niveaux WHERE code = 'GS'
  AND id NOT IN (SELECT niveau_id FROM (SELECT niveau_id FROM classes) AS c)
  AND id NOT IN (SELECT niveau_id FROM (SELECT niveau_id FROM config_evaluation_defaut WHERE niveau_id IS NOT NULL) AS d);
