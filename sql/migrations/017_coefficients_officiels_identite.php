<?php
// =====================================================================
//  017 — Coefficients officiels et identité visuelle des groupes
//  1. Coefficients du ministère (MESTFP, Direction de l'enseignement secondaire,
//     « Tableau des crédits horaires hebdomadaires et des coefficients »), appliqués
//     uniquement aux matières encore « à confirmer » (rien de saisi à la main n'est touché).
//     Restent à confirmer : 1ère A et Tle A sans précision A1/A2, Communication écrite et
//     Lecture en 4e-3e, Conduite, et les matières sans coefficient officiel au niveau donné.
//  2. Couleurs et logo par groupe : « Les Écoles FVPT » (vert et jaune, logo officiel).
// =====================================================================
$C = ['COLLEGE_1' => ['FRANCAIS'=>1,'ANGLAIS'=>1,'HISTGEO'=>1,'MATHS'=>1,'PCT'=>1,'SVT'=>1,'EPS'=>1,'COMECRITE'=>1,'LECTURE'=>1],
      'COLLEGE_2' => ['FRANCAIS'=>2,'ANGLAIS'=>2,'HISTGEO'=>2,'ESPAGNOL'=>2,'MATHS'=>3,'PCT'=>2,'SVT'=>2,'EPS'=>1]];
$A_2NDE = ['FRANCAIS'=>2,'ANGLAIS'=>3,'PHILO'=>2,'HISTGEO'=>2,'ESPAGNOL'=>2,'MATHS'=>1,'PCT'=>1,'SVT'=>1,'EPS'=>1];
$CD_2NDE = ['FRANCAIS'=>1,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>1,'MATHS'=>3,'PCT'=>3,'SVT'=>3,'EPS'=>1];
$OFFICIEL = [
  '6E|' => $C['COLLEGE_1'], '5E|' => $C['COLLEGE_1'], '4E|' => $C['COLLEGE_2'], '3E|' => $C['COLLEGE_2'],
  '2NDE|A1' => $A_2NDE, '2NDE|A2' => $A_2NDE, '2NDE|' => $A_2NDE,          // 2nde A sans précision : A1 et A2 identiques
  '2NDE|B' => ['FRANCAIS'=>2,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>2,'ESPAGNOL'=>2,'MATHS'=>1,'PCT'=>1,'SVT'=>1,'EPS'=>1],
  '2NDE|C' => $CD_2NDE, '2NDE|D' => $CD_2NDE,
  '1ERE|A1' => ['FRANCAIS'=>5,'ANGLAIS'=>3,'PHILO'=>4,'HISTGEO'=>3,'ESPAGNOL'=>2,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  '1ERE|A2' => ['FRANCAIS'=>4,'ANGLAIS'=>3,'PHILO'=>3,'HISTGEO'=>5,'ESPAGNOL'=>2,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  '1ERE|B'  => ['FRANCAIS'=>4,'ANGLAIS'=>3,'PHILO'=>3,'HISTGEO'=>4,'ESPAGNOL'=>1,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  '1ERE|C'  => ['FRANCAIS'=>2,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>2,'MATHS'=>6,'PCT'=>5,'SVT'=>2,'EPS'=>1],
  '1ERE|D'  => ['FRANCAIS'=>2,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>2,'MATHS'=>4,'PCT'=>4,'SVT'=>5,'EPS'=>1],
  'TLE|A1'  => ['FRANCAIS'=>3,'ANGLAIS'=>3,'PHILO'=>4,'HISTGEO'=>3,'ESPAGNOL'=>2,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  'TLE|A2'  => ['FRANCAIS'=>4,'ANGLAIS'=>3,'PHILO'=>3,'HISTGEO'=>5,'ESPAGNOL'=>2,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  'TLE|B'   => ['FRANCAIS'=>4,'ANGLAIS'=>3,'PHILO'=>3,'HISTGEO'=>4,'ESPAGNOL'=>1,'MATHS'=>2,'PCT'=>1,'SVT'=>2,'EPS'=>1],
  'TLE|C'   => ['FRANCAIS'=>2,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>2,'MATHS'=>6,'PCT'=>5,'SVT'=>2,'EPS'=>1],
  'TLE|D'   => ['FRANCAIS'=>2,'ANGLAIS'=>2,'PHILO'=>2,'HISTGEO'=>2,'MATHS'=>4,'PCT'=>4,'SVT'=>5,'EPS'=>1],
];
$appliques = 0; $restants = 0;
$lignes = lignes("SELECT cm.id, m.code AS matiere, n.code AS niveau, s.code AS serie
                  FROM classe_matieres cm JOIN classes c ON c.id = cm.classe_id JOIN niveaux n ON n.id = c.niveau_id
                  JOIN matieres m ON m.id = cm.matiere_id LEFT JOIN series s ON s.id = c.serie_id
                  WHERE cm.coef_a_confirmer = 1 AND n.cycle IN ('COLLEGE','LYCEE')");
foreach ($lignes as $l) {
  $cle = $l['niveau'] . '|' . ($l['serie'] ?? '');
  $coef = $OFFICIEL[$cle][$l['matiere']] ?? null;
  if ($coef === null) { $restants++; continue; }
  requete('UPDATE classe_matieres SET coefficient = ?, coef_a_confirmer = 0 WHERE id = ?', [$coef, $l['id']]);
  $appliques++;
}

// ---- Identité visuelle des groupes
foreach (['couleur' => "VARCHAR(7) NULL", 'couleur_accent' => "VARCHAR(7) NULL", 'logo' => "MEDIUMTEXT NULL"] as $col => $def)
  if (!ligne("SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'groupes' AND COLUMN_NAME = ?", [$col]))
    bd()->exec("ALTER TABLE groupes ADD COLUMN $col $def");
$png = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../donnees/logo_ecoles_fvpt.png'));
requete("UPDATE groupes SET couleur = COALESCE(couleur, '#0B5E17'), couleur_accent = COALESCE(couleur_accent, '#FEED01'), logo = COALESCE(logo, ?),
         sigle = COALESCE(sigle, 'Écoles FVPT') WHERE code = 'FVPT'", [$png]);
// Le logo des Écoles FVPT est aussi celui du Lycée Champagnat (à gauche sur ses bulletins)
$kandi = ligne("SELECT id FROM ecoles WHERE code = 'KANDI'");
if ($kandi && !ligne("SELECT id FROM ecole_images WHERE ecole_id = ? AND type = 'LOGO'", [$kandi['id']]))
  requete("INSERT INTO ecole_images (ecole_id, type, donnees) VALUES (?, 'LOGO', ?)", [$kandi['id'], $png]);
info("  Coefficients officiels : $appliques appliqué(s), $restants encore à confirmer. Identité « Les Écoles FVPT » (vert et jaune, logo) en place.");
