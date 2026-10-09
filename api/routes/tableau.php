<?php
// =====================================================================
//  TABLEAU DE BORD : effectifs, recouvrement des frais, avancement des notes
//  Administrateur général : toutes les écoles ; directrice : son école.
// =====================================================================
require_once __DIR__ . '/finances.php';
require_once __DIR__ . '/caisse.php';

function r_tableau_bord() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $a = ligne('SELECT * FROM annees_scolaires WHERE en_cours = 1');
  if (!$a) repondre(['annee' => null, 'ecoles' => []]);
  $ecoles = est_super_admin() ? lignes('SELECT id, code, nom_officiel, ville FROM ecoles WHERE actif = 1 ORDER BY ville, nom_officiel')
                              : lignes('SELECT id, code, nom_officiel, ville FROM ecoles WHERE id = ?', [$UTILISATEUR['ecole_id']]);
  $resultat = [];
  foreach ($ecoles as $e) $resultat[] = $e + bilan_ecole((int)$e['id'], $a);

  // Encaissements des 14 derniers jours (écoles visibles)
  $ids = array_column($ecoles, 'id') ?: [0];
  $marques = implode(',', array_fill(0, count($ids), '?'));
  $jours = lignes("SELECT date_paiement AS jour, SUM(montant) AS total FROM paiements WHERE annule = 0 AND ecole_id IN ($marques)
                   AND date_paiement >= CURDATE() - INTERVAL 13 DAY GROUP BY date_paiement", $ids);
  $parJour = array_column($jours, 'total', 'jour'); $serie = [];
  for ($k = 13; $k >= 0; $k--) { $j = date('Y-m-d', strtotime("-$k day")); $serie[] = ['jour' => $j, 'total' => (int)($parJour[$j] ?? 0)]; }
  repondre(['annee' => $a['libelle'], 'ecoles' => $resultat, 'encaissements_14j' => $serie]);
}

function bilan_ecole(int $ecole, array $a): array {
  // ---- Effectifs
  $eff = ligne("SELECT COUNT(*) AS total, SUM(el.sexe = 'F') AS filles FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                WHERE i.ecole_id = ? AND i.annee_id = ? AND i.statut = 'ACTIF'", [$ecole, $a['id']]);
  $nbClasses = (int)ligne('SELECT COUNT(*) AS n FROM classes WHERE ecole_id = ? AND annee_id = ?', [$ecole, $a['id']])['n'];
  $parCycle = lignes("SELECT n.cycle, COUNT(*) AS eleves FROM inscriptions i JOIN classes c ON c.id = i.classe_id JOIN niveaux n ON n.id = c.niveau_id
                      WHERE i.ecole_id = ? AND i.annee_id = ? AND i.statut = 'ACTIF' GROUP BY n.cycle ORDER BY MIN(n.ordre)", [$ecole, $a['id']]);

  // ---- Finances (même calcul que la caisse, sans requête par élève)
  $tarifs = [];
  foreach (lignes('SELECT * FROM tarifs WHERE ecole_id = ? AND annee_id = ?', [$ecole, $a['id']]) as $t) {
    $t['tranches'] = lignes('SELECT * FROM tarif_tranches WHERE tarif_id = ? ORDER BY numero', [$t['id']]);
    $tarifs[(int)$t['niveau_id']] = $t;
  }
  $inscr = lignes("SELECT i.id, c.niveau_id,
                     (SELECT COALESCE(SUM(r.montant),0) FROM remises r WHERE r.inscription_id = i.id) AS remise,
                     (SELECT COALESCE(SUM(p.montant),0) FROM paiements p WHERE p.inscription_id = i.id AND p.annule = 0) AS paye
                   FROM inscriptions i JOIN classes c ON c.id = i.classe_id
                   WHERE i.ecole_id = ? AND i.annee_id = ? AND i.statut = 'ACTIF'", [$ecole, $a['id']]);
  $f = ['attendu' => 0, 'encaisse' => 0, 'reste' => 0, 'en_retard' => 0, 'eleves_en_retard' => 0, 'eleves_soldes' => 0, 'sans_tarif' => 0];
  foreach ($inscr as $x) {
    $t = $tarifs[(int)$x['niveau_id']] ?? null;
    if (!$t) { $f['sans_tarif']++; continue; }
    $du = max(0, (int)$t['montant'] - (int)$x['remise']); $paye = (int)$x['paye'];
    [, $retard] = repartir($t['tranches'], (int)$x['remise'], $paye);
    $f['attendu'] += $du; $f['encaisse'] += $paye; $f['reste'] += max(0, $du - $paye); $f['en_retard'] += $retard;
    if ($retard > 0) $f['eleves_en_retard']++;
    if ($paye >= $du) $f['eleves_soldes']++;
  }
  $f['jours_non_remis'] = count(jours_non_remis($ecole, date('Y-m-d', strtotime('-7 day')), date('Y-m-d', strtotime('-1 day'))));
  $f['remises_a_confirmer'] = (int)ligne("SELECT COUNT(*) AS n FROM remises_caisse WHERE ecole_id = ? AND statut = 'REMISE'", [$ecole])['n'];
  $f['taux'] = $f['attendu'] ? round(100 * $f['encaisse'] / $f['attendu'], 1) : 0;
  $f['aujourdhui'] = (int)ligne('SELECT COALESCE(SUM(montant),0) AS t FROM paiements WHERE ecole_id = ? AND annule = 0 AND date_paiement = CURDATE()', [$ecole])['t'];

  // ---- Notes de la période en cours (première période ouverte)
  $periode = ligne("SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? AND statut = 'OUVERTE' ORDER BY numero LIMIT 1", [$ecole, $a['id']]);
  $notes = ['periode' => $periode['libelle'] ?? null];
  $notes += ligne("SELECT COUNT(DISTINCT cm.id) AS matieres, SUM(cm.professeur_id IS NULL) AS sans_professeur
                   FROM classe_matieres cm JOIN classes c ON c.id = cm.classe_id WHERE c.ecole_id = ? AND c.annee_id = ? AND cm.actif = 1", [$ecole, $a['id']]);
  $notes['classes_sans_matieres'] = (int)ligne('SELECT COUNT(*) AS n FROM classes c WHERE c.ecole_id = ? AND c.annee_id = ?
                     AND NOT EXISTS (SELECT 1 FROM classe_matieres cm WHERE cm.classe_id = c.id AND cm.actif = 1)', [$ecole, $a['id']])['n'];
  if ($periode) {
    $notes += ligne("SELECT COUNT(*) AS evaluations, SUM(e.statut = 'VALIDEE') AS validees,
                       SUM(e.statut = 'BROUILLON' AND EXISTS (SELECT 1 FROM notes n WHERE n.evaluation_id = e.id)) AS en_cours
                     FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id JOIN classes c ON c.id = cm.classe_id
                     WHERE c.ecole_id = ? AND e.periode_id = ?", [$ecole, $periode['id']]);
    $notes['classes_sans_evaluations'] = (int)ligne('SELECT COUNT(*) AS n FROM classes c WHERE c.ecole_id = ? AND c.annee_id = ?
                     AND EXISTS (SELECT 1 FROM classe_matieres cm WHERE cm.classe_id = c.id AND cm.actif = 1)
                     AND NOT EXISTS (SELECT 1 FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                                     WHERE cm.classe_id = c.id AND e.periode_id = ?)', [$ecole, $a['id'], $periode['id']])['n'];
    // Professeurs dont des évaluations sont encore à saisir ou à valider
    $notes['professeurs_en_retard'] = lignes("SELECT CONCAT(u.nom, ' ', u.prenoms) AS professeur, COUNT(*) AS non_validees
                     FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id JOIN classes c ON c.id = cm.classe_id
                     JOIN utilisateurs u ON u.id = cm.professeur_id
                     WHERE c.ecole_id = ? AND e.periode_id = ? AND e.statut = 'BROUILLON'
                     GROUP BY u.id ORDER BY non_validees DESC LIMIT 5", [$ecole, $periode['id']]);
  }
  foreach (['matieres', 'sans_professeur', 'evaluations', 'validees', 'en_cours'] as $k) $notes[$k] = (int)($notes[$k] ?? 0);

  // ---- Absences
  $abs = ['appels_aujourdhui' => (int)ligne("SELECT COUNT(DISTINCT j.enregistrement_id) AS n FROM journal_audit j JOIN classes c ON c.id = j.enregistrement_id
             WHERE j.action = 'APPEL' AND j.table_cible = 'classes' AND j.cree_le >= CURDATE() AND c.ecole_id = ? AND c.annee_id = ?", [$ecole, $a['id']])['n']];
  $abs += ligne("SELECT COUNT(*) AS a_justifier, COALESCE(SUM(heures),0) AS heures_a_justifier FROM absences
                 WHERE ecole_id = ? AND statut = 'ABSENT' AND justifiee = 0", [$ecole]);
  $abs += ligne("SELECT COALESCE(SUM(CASE WHEN statut = 'ABSENT' THEN heures END),0) AS heures_7j, SUM(statut = 'RETARD') AS retards_7j
                 FROM absences WHERE ecole_id = ? AND date_absence >= CURDATE() - INTERVAL 6 DAY", [$ecole]);
  // Élèves les plus absents du trimestre en cours (heures non justifiées)
  $pc = ligne('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? AND date_debut <= CURDATE() AND date_fin >= CURDATE() LIMIT 1', [$ecole, $a['id']]);
  $abs['periode'] = $pc['libelle'] ?? null;
  $abs['plus_absents'] = $pc ? lignes("SELECT el.id AS eleve_id, el.nom, el.prenoms, c.nom AS classe, SUM(a.heures) AS heures
                 FROM absences a JOIN inscriptions i ON i.id = a.inscription_id JOIN eleves el ON el.id = i.eleve_id JOIN classes c ON c.id = a.classe_id
                 WHERE a.ecole_id = ? AND a.statut = 'ABSENT' AND a.justifiee = 0 AND a.date_absence BETWEEN ? AND ?
                 GROUP BY i.id HAVING heures > 0 ORDER BY heures DESC LIMIT 3", [$ecole, $pc['date_debut'], $pc['date_fin']]) : [];
  foreach (['a_justifier', 'retards_7j'] as $k) $abs[$k] = (int)$abs[$k];
  foreach (['heures_a_justifier', 'heures_7j'] as $k) $abs[$k] = (float)$abs[$k];

  $comptes = lignes("SELECT role, COUNT(*) AS n FROM utilisateurs WHERE ecole_id = ? AND actif = 1 GROUP BY role", [$ecole]);
  return ['effectifs' => ['total' => (int)$eff['total'], 'filles' => (int)$eff['filles'], 'classes' => $nbClasses, 'par_cycle' => $parCycle],
          'finances' => $f, 'notes' => $notes, 'absences' => $abs, 'comptes' => array_column($comptes, 'n', 'role')];
}
