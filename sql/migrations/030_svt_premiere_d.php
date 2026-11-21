<?php
// =====================================================================
//  030 — SVT manquante en 1ère D (Lycée Champagnat, et donc CPEG SOMO 2 qui en reprenait les matières)
//  SOMO 2 : coefficient 5 d'après la fiche de notes de l'école ; Champagnat : 5 également, à confirmer.
// =====================================================================
$svt = ligne("SELECT id FROM matieres WHERE code = 'SVT'");
if (!$svt) { info('  Matière SVT introuvable.'); return; }
$n = 0;
foreach ([['KANDI', 1], ['SOMO2', 0]] as [$code, $aConfirmer]) {
  foreach (lignes("SELECT c.id FROM classes c JOIN ecoles e ON e.id = c.ecole_id JOIN annees_scolaires a ON a.id = c.annee_id
                   WHERE e.code = ? AND a.libelle = '2026-2027' AND c.nom = '1ère D'", [$code]) as $c) {
    if (ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $svt['id']])) continue;
    $ordre = (int)(ligne('SELECT MAX(ordre_affichage) AS m FROM classe_matieres WHERE classe_id = ?', [$c['id']])['m'] ?? 0) + 1;
    requete("INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage) VALUES (?,?,5,?, 'Sciences', ?)", [$c['id'], $svt['id'], $aConfirmer, $ordre]);
    $n++;
  }
}
info("  SVT ajoutée en 1ère D : $n classe(s).");
