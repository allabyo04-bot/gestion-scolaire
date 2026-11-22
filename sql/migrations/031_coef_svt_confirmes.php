<?php
// =====================================================================
//  031 — Coefficients de SVT confirmés par le Secrétariat général (22/11/2026)
//  Lycée Champagnat : 1ère D = 5, 2nde C = 3.
// =====================================================================
$n = 0;
foreach ([['1ère D', 5], ['2nde C', 3]] as [$classe, $coef]) {
  $cm = ligne("SELECT cm.id FROM classe_matieres cm JOIN classes c ON c.id = cm.classe_id JOIN ecoles e ON e.id = c.ecole_id
               JOIN annees_scolaires a ON a.id = c.annee_id JOIN matieres m ON m.id = cm.matiere_id
               WHERE e.code = 'KANDI' AND a.libelle = '2026-2027' AND c.nom = ? AND m.code = 'SVT'", [$classe]);
  if (!$cm) continue;
  requete('UPDATE classe_matieres SET coefficient = ?, coef_a_confirmer = 0 WHERE id = ?', [$coef, $cm['id']]);
  $n++;
}
info("  Champagnat : $n coefficient(s) de SVT confirmé(s).");
