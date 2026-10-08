<?php
// Rang « à la française » avec ex aequo : 15 ; 14 ; 14 ; 12 → 1er ; 2e ex ; 2e ex ; 4e
function classer(array $valeurs): array {
  arsort($valeurs, SORT_NUMERIC);
  $rangs = []; $position = 0; $precedent = null; $rang = 0;
  foreach ($valeurs as $cle => $v) {
    $position++;
    if ($precedent === null || bccomp_f($v, $precedent) !== 0) $rang = $position;
    $rangs[$cle] = $rang;
    $precedent = $v;
  }
  $compte = array_count_values($rangs);
  $resultat = [];
  foreach ($rangs as $cle => $r) $resultat[$cle] = ['rang' => $r, 'ex_aequo' => $compte[$r] > 1];
  return $resultat;
}
function bccomp_f($a, $b): int { return round((float)$a, 2) <=> round((float)$b, 2); }

// Moyennes et rangs complets d'une classe pour une période
function resultats_classe(int $classe_id, int $periode_id): array {
  $eleves = lignes("SELECT i.id AS inscription_id, e.matricule, e.educmaster, e.nom, e.prenoms, e.sexe
                    FROM inscriptions i JOIN eleves e ON e.id = i.eleve_id
                    WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY e.nom, e.prenoms", [$classe_id]);
  $matieres = lignes('SELECT cm.id, m.libelle, cm.coefficient, cm.groupe_bulletin, cm.ordre_affichage
                      FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id
                      WHERE cm.classe_id = ? AND cm.actif = 1 ORDER BY cm.ordre_affichage, m.libelle', [$classe_id]);
  $mm = lignes('SELECT v.* FROM v_moyennes_matiere v JOIN inscriptions i ON i.id = v.inscription_id
                WHERE i.classe_id = ? AND v.periode_id = ?', [$classe_id, $periode_id]);
  $mg = lignes('SELECT v.* FROM v_moyennes_generales v JOIN inscriptions i ON i.id = v.inscription_id
                WHERE i.classe_id = ? AND v.periode_id = ?', [$classe_id, $periode_id]);

  $parEleve = [];
  foreach ($eleves as $e) $parEleve[$e['inscription_id']] = $e + ['matieres' => [], 'moyenne_generale' => null,
                                                                   'total_points' => null, 'total_coefficients' => null];
  $parMatiere = [];
  foreach ($mm as $l) {
    if (!isset($parEleve[$l['inscription_id']])) continue;
    $parEleve[$l['inscription_id']]['matieres'][$l['classe_matiere_id']] = [
      'moy_interros' => $l['moy_interros'] === null ? null : round((float)$l['moy_interros'], 2),
      'nb_devoirs' => (int)$l['nb_devoirs'], 'moyenne' => (float)$l['moyenne'],
      'coefficient' => (float)$l['coefficient'], 'points' => (float)$l['points'],
    ];
    $parMatiere[$l['classe_matiere_id']][$l['inscription_id']] = (float)$l['moyenne'];
  }
  foreach ($parMatiere as $cm => $valeurs)
    foreach (classer($valeurs) as $insc => $r) $parEleve[$insc]['matieres'][$cm] += $r;

  $generales = [];
  foreach ($mg as $l) {
    if (!isset($parEleve[$l['inscription_id']])) continue;
    $parEleve[$l['inscription_id']]['moyenne_generale'] = (float)$l['moyenne_generale'];
    $parEleve[$l['inscription_id']]['total_points'] = (float)$l['total_points'];
    $parEleve[$l['inscription_id']]['total_coefficients'] = (float)$l['total_coefficients'];
    $generales[$l['inscription_id']] = (float)$l['moyenne_generale'];
  }
  foreach (classer($generales) as $insc => $r) $parEleve[$insc] += $r;

  // Statistiques de classe (utiles au bulletin)
  $stats = null;
  if ($generales) {
    $stats = ['plus_forte' => max($generales), 'plus_faible' => min($generales),
              'moyenne_classe' => round(array_sum($generales) / count($generales), 2),
              'classes' => count($generales), 'effectif' => count($eleves)];
  }
  return ['matieres' => $matieres, 'eleves' => array_values($parEleve), 'statistiques' => $stats];
}
