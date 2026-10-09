<?php
// =====================================================================
//  015 — Adaptation au modèle de bulletin (Lycée Champagnat, 5e, 2e trimestre 2025-2026)
//  - Collège : « Français » remplacé par « Communication écrite » + « Lecture » (même professeur)
//  - Conduite ajoutée dans chaque classe du secondaire
//  - Groupes du bulletin (Lettres / Sciences / Autres) et ordre des matières
//  - Entêtes et coordonnées des écoles (seulement si elles n'ont pas été modifiées à la main)
// =====================================================================
$annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) return;

$matiere = function (string $libelle, ?string $court, int $conduite = 0): int {
  $m = ligne('SELECT id FROM matieres WHERE libelle = ? OR (libelle_court IS NOT NULL AND libelle_court = ?)', [$libelle, $court ?? $libelle]);
  if ($m) return (int)$m['id'];
  $code = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $court ?? $libelle)));
  $c = substr($code, 0, 12); $n = 1; $essai = $c;
  while (ligne('SELECT id FROM matieres WHERE code = ?', [$essai])) $essai = $c . (++$n);
  requete('INSERT INTO matieres (code, libelle, libelle_court, est_conduite) VALUES (?,?,?,?)', [$essai, $libelle, $court, $conduite]);
  return (int)bd()->lastInsertId();
};
$comEcrite = $matiere('Communication écrite', 'Com. écrite');
$lecture = $matiere('Lecture', null);
$conduite = $matiere('Conduite', null, 1);
$francais = (int)(ligne("SELECT id FROM matieres WHERE libelle = 'Français'")['id'] ?? 0);

// Groupe et rang de chaque matière sur le bulletin (ordre du modèle)
$ordre = [ 'Communication écrite' => ['Lettres', 1], 'Lecture' => ['Lettres', 2], 'Français' => ['Lettres', 3], 'Philosophie' => ['Lettres', 4],
  'Histoire-Géographie' => ['Lettres', 5], 'Anglais' => ['Lettres', 6], 'Espagnol' => ['Lettres', 7],
  'Mathématiques' => ['Sciences', 10], 'Physique, chimie et technologie' => ['Sciences', 11], 'Sciences de la vie et de la terre' => ['Sciences', 12],
  'Éducation physique et sportive' => ['Autres', 20], 'Conduite' => ['Autres', 21] ];

$bilan = ['francais_scinde' => 0, 'conduite' => 0, 'classes' => 0];
$classes = lignes("SELECT c.id, n.code AS niveau FROM classes c JOIN niveaux n ON n.id = c.niveau_id
                   WHERE c.annee_id = ? AND n.cycle IN ('COLLEGE','LYCEE')", [$annee['id']]);
foreach ($classes as $c) {
  $bilan['classes']++;
  // 1. Collège : scinder le Français (si aucune note n'a encore été saisie)
  if ($francais && in_array($c['niveau'], ['6E', '5E', '4E', '3E'], true)) {
    $fr = ligne('SELECT * FROM classe_matieres WHERE classe_id = ? AND matiere_id = ? AND actif = 1', [$c['id'], $francais]);
    if ($fr && !ligne('SELECT id FROM evaluations WHERE classe_matiere_id = ? LIMIT 1', [$fr['id']])) {
      foreach ([$comEcrite, $lecture] as $m)
        if (!ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $m]))
          requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, professeur_id) VALUES (?,?,?,?,?)',
                  [$c['id'], $m, $fr['coefficient'], $fr['coef_a_confirmer'], $fr['professeur_id']]);
      requete('DELETE FROM classe_matieres WHERE id = ?', [$fr['id']]);
      $bilan['francais_scinde']++;
    }
  }
  // 2. Conduite dans chaque classe du secondaire
  if (!ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $conduite])) {
    requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer) VALUES (?,?,1,1)', [$c['id'], $conduite]);
    $bilan['conduite']++;
  }
  // 3. Groupe et ordre (sans toucher à un groupe déjà choisi à la main)
  foreach (lignes('SELECT cm.id, cm.groupe_bulletin, m.libelle FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id WHERE cm.classe_id = ?', [$c['id']]) as $cm) {
    if (!isset($ordre[$cm['libelle']])) continue;
    [$groupe, $rang] = $ordre[$cm['libelle']];
    requete('UPDATE classe_matieres SET groupe_bulletin = COALESCE(groupe_bulletin, ?), ordre_affichage = ? WHERE id = ?', [$groupe, $rang, $cm['id']]);
  }
}

// 4. Entêtes des écoles, sur le modèle du bulletin (seulement si l'entête est encore celle d'origine)
$site = 'www.fondation-viepourtous.org';
foreach (lignes("SELECT * FROM ecoles WHERE entete_ligne1 = 'République du Bénin'") as $e) {
  requete('UPDATE ecoles SET entete_ligne1 = ?, entete_ligne2 = ?, entete_ligne3 = ?, site_web = COALESCE(site_web, ?) WHERE id = ?',
          [$e['entete_ligne2'], 'Fondation Vie Pour Tous, Vida para todos', 'Direction nationale des écoles FVPT', $site, $e['id']]);
}
// Lycée Champagnat : nom officiel, coordonnées et signataire du bulletin
requete("UPDATE ecoles SET nom_officiel = 'Lycée Champagnat', boite_postale = COALESCE(boite_postale, 'BP 71 Kandi'),
         telephone = COALESCE(telephone, '(229) 60104111 / 60104118'), email = COALESCE(email, 'direction@fondation-viepourtous.org')
         WHERE code = 'KANDI' AND nom_officiel = 'CPEG Champagnat'");
requete("UPDATE parametres_bulletin pb JOIN ecoles e ON e.id = pb.ecole_id SET pb.titre_signataire = 'Le Proviseur', pb.nom_signataire = NULL
         WHERE e.code = 'KANDI' AND pb.a_confirmer = 1");
info("  Bulletin : {$bilan['classes']} classes du secondaire, Français scindé dans {$bilan['francais_scinde']} classe(s), Conduite ajoutée dans {$bilan['conduite']}.");
