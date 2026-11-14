<?php
// =====================================================================
//  026 — Primaire et maternelle
//  Primaire : 5 évaluations formatives dans l'année (2 + 2 + 1 par trimestre), 9 matières
//  sans coefficient, une matière réussie à partir de 10/20, seuil = deux tiers des matières.
//  Les règles sont réglables par école (parametres_primaire) et marquées « à confirmer ».
//  Maternelle : grille trimestrielle d'observation (11 domaines, TS / S / PS).
// =====================================================================

// Type d'évaluation propre au primaire (une par matière et par évaluation formative)
bd()->exec("ALTER TABLE evaluations MODIFY type ENUM('INTERRO','DEVOIR','DTL','EVAL') NOT NULL");

bd()->exec("CREATE TABLE IF NOT EXISTS parametres_primaire (
  ecole_id      INT UNSIGNED NOT NULL PRIMARY KEY,
  note_reussite DECIMAL(4,2) NOT NULL DEFAULT 10.00,       -- une matière est réussie à partir de cette note
  seuil_num     TINYINT UNSIGNED NOT NULL DEFAULT 2,       -- seuil = arrondi supérieur de (matières composées x num / den)
  seuil_den     TINYINT UNSIGNED NOT NULL DEFAULT 3,
  repartition   VARCHAR(20) NOT NULL DEFAULT '2,2,1',      -- nombre d'évaluations par trimestre
  passage_min   TINYINT UNSIGNED NOT NULL DEFAULT 3,       -- évaluations réussies nécessaires pour passer
  a_confirmer   TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_pp_ecole FOREIGN KEY (ecole_id) REFERENCES ecoles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

bd()->exec("CREATE TABLE IF NOT EXISTS maternelle_grilles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscription_id  INT UNSIGNED NOT NULL,
  periode_id      INT UNSIGNED NOT NULL,
  valeurs         VARCHAR(80) NOT NULL,                     -- 11 appréciations séparées par des virgules : TS, S, PS ou vide
  observation     VARCHAR(500) NULL,
  saisie_par      INT UNSIGNED NOT NULL,
  saisie_le       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modifie_par     INT UNSIGNED NULL,
  modifie_le      DATETIME NULL,
  UNIQUE KEY uq_grille (inscription_id, periode_id),
  CONSTRAINT fk_mg_insc FOREIGN KEY (inscription_id) REFERENCES inscriptions(id),
  CONSTRAINT fk_mg_per  FOREIGN KEY (periode_id) REFERENCES periodes(id),
  CONSTRAINT fk_mg_saisi FOREIGN KEY (saisie_par) REFERENCES utilisateurs(id),
  CONSTRAINT fk_mg_modif FOREIGN KEY (modifie_par) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Matières composées au primaire (liste transmise par les écoles FVPT, nov. 2026)
$MATIERES = [
  ['DICTEE',   'Dictée',                                        'Dictée'],
  ['MATHS',    'Mathématiques',                                 'Maths'],
  ['EXPECR',   'Expression écrite',                             'Expr. écrite'],
  ['COMPECR',  'Compréhension de l\'écrit (lecture)',           'Lecture'],
  ['EST',      'Éducation scientifique et technologique',       'EST'],
  ['ES',       'Éducation sociale',                             'ES'],
  ['EAORAL',   'Éducation artistique (chant, poésie, conte)',   'EA oral'],
  ['EADESSIN', 'Éducation artistique (dessin)',                 'EA dessin'],
  ['EPS',      'Éducation physique et sportive',                'EPS'],
];
$ids = [];
foreach ($MATIERES as [$code, $lib, $court]) {
  $m = ligne('SELECT id FROM matieres WHERE code = ?', [$code]);
  if (!$m) { requete('INSERT INTO matieres (code, libelle, libelle_court) VALUES (?,?,?)', [$code, $lib, $court]); $m = ['id' => (int)bd()->lastInsertId()]; }
  $ids[] = (int)$m['id'];
}

// Les 9 matières dans chaque classe du primaire (coefficient 1), sans toucher à ce qui existe
$n = 0;
foreach (lignes("SELECT c.id FROM classes c JOIN niveaux nv ON nv.id = c.niveau_id WHERE nv.cycle = 'PRIMAIRE'") as $c)
  foreach ($ids as $rang => $mid)
    if (!ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $mid])) {
      requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, ordre_affichage) VALUES (?,?,1,?)', [$c['id'], $mid, $rang + 1]);
      $n++;
    }

// Règles par défaut pour chaque école qui a des classes du primaire
foreach (lignes("SELECT DISTINCT c.ecole_id FROM classes c JOIN niveaux nv ON nv.id = c.niveau_id WHERE nv.cycle = 'PRIMAIRE'") as $e)
  requete('INSERT IGNORE INTO parametres_primaire (ecole_id) VALUES (?)', [$e['ecole_id']]);

info("  Primaire : $n matière(s) ajoutée(s) aux classes ; maternelle : grilles d'observation disponibles.");
