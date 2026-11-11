<?php
// =====================================================================
//  022 — Lycée Champagnat : coefficients transmis par l'école et classe de 2nde A2
//  Coefficients (tableau « Coefficients à compléter », nov. 2026) :
//    Com. écrite et Lecture : 2 en 4e et 3e ; EPS : 1 en 4e et 3e ;
//    Français : 1 en 2nde D ; SVT : 5 en Tle D.
//  Une matière absente de la classe est ajoutée (sans professeur). Ces valeurs sont confirmées.
//  2nde A2 : classe ouverte cette année, avec les matières et coefficients officiels de 2nde A2.
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
$ecole = ligne("SELECT id FROM ecoles WHERE code = 'KANDI'");
if (!$annee || !$ecole) { info('  Lycée Champagnat ou année 2026-2027 introuvable : rien à faire.'); return; }

$matiere = fn(string $code) => (int)(ligne('SELECT id FROM matieres WHERE code = ?', [$code])['id'] ?? 0);
// Groupe et rang sur le bulletin, comme pour les autres classes (migration 015)
$PLACE = ['COMECRITE' => ['Lettres', 1], 'LECTURE' => ['Lettres', 2], 'FRANCAIS' => ['Lettres', 3], 'PHILO' => ['Lettres', 4],
          'HISTGEO' => ['Lettres', 5], 'ANGLAIS' => ['Lettres', 6], 'ESPAGNOL' => ['Lettres', 7], 'MATHS' => ['Sciences', 10],
          'PCT' => ['Sciences', 11], 'SVT' => ['Sciences', 12], 'EPS' => ['Autres', 20], 'CONDUITE' => ['Autres', 21]];

$poser = function (int $classe, string $code, float $coef, int $aConfirmer = 0) use ($matiere, $PLACE): string {
  $m = $matiere($code);
  if (!$m) return 'absente';
  $cm = ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$classe, $m]);
  if ($cm) { requete('UPDATE classe_matieres SET coefficient = ?, coef_a_confirmer = ?, actif = 1 WHERE id = ?', [$coef, $aConfirmer, $cm['id']]); return 'mise à jour'; }
  [$groupe, $rang] = $PLACE[$code] ?? [null, 30];
  requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage) VALUES (?,?,?,?,?,?)',
          [$classe, $m, $coef, $aConfirmer, $groupe, $rang]);
  return 'ajoutée';
};
$classe = fn(string $nom) => (int)(ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$ecole['id'], $annee['id'], $nom])['id'] ?? 0);

// 1. Coefficients transmis par l'école
$bilan = [];
foreach ([['4e', 'COMECRITE', 2], ['4e', 'LECTURE', 2], ['3e', 'COMECRITE', 2], ['3e', 'LECTURE', 2],
          ['4e', 'EPS', 1], ['3e', 'EPS', 1], ['2nde D', 'FRANCAIS', 1], ['Tle D', 'SVT', 5]] as [$nom, $code, $coef]) {
  $c = $classe($nom);
  if ($c) $bilan[] = "$nom $code " . $poser($c, $code, $coef);
}

// 2. Classe de 2nde A2 (coefficients officiels du ministère pour la 2nde A2)
$cree = 'déjà présente';
if (!$classe('2nde A2')) {
  $niveau = ligne("SELECT id FROM niveaux WHERE code = '2NDE'");
  $serie = ligne("SELECT id FROM series WHERE code = 'A2'");
  if ($niveau && $serie) {
    requete('INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom) VALUES (?,?,?,?,?)',
            [$ecole['id'], $annee['id'], $niveau['id'], $serie['id'], '2nde A2']);
    $cree = 'créée';
  } else $cree = 'impossible (niveau ou série manquant)';
}
if ($c = $classe('2nde A2')) {
  foreach (['FRANCAIS' => 2, 'ANGLAIS' => 3, 'PHILO' => 2, 'HISTGEO' => 2, 'ESPAGNOL' => 2, 'MATHS' => 1, 'PCT' => 1, 'SVT' => 1, 'EPS' => 1] as $code => $coef)
    if (!ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c, $matiere($code)])) $poser($c, $code, $coef);
  if (!ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c, $matiere('CONDUITE')])) $poser($c, 'CONDUITE', 1, 1);
}
info('  Champagnat : ' . implode(', ', $bilan) . " ; 2nde A2 $cree.");
