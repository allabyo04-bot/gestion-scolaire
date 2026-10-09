<?php
// =====================================================================
//  BULLETINS : calcul complet (colonnes du modèle, bilans, rangs, mentions)
//  et contrôles avant impression. Seules les évaluations VALIDÉES comptent.
// =====================================================================
require_once __DIR__ . '/eleves.php';

function parametres_bulletin(int $ecole): array {
  $p = ligne('SELECT * FROM parametres_bulletin WHERE ecole_id = ?', [$ecole]);
  if (!$p) {
    requete("INSERT INTO parametres_bulletin (ecole_id, appreciations, seuil_felicitations, seuil_encouragements, seuil_tableau, note_bas)
             VALUES (?, '[[18,\"Excellent\"],[16,\"Très bien\"],[14,\"Bien\"],[12,\"Assez bien\"],[10,\"Passable\"],[8,\"Médiocre\"],[0,\"Insuffisant\"]]', 16, 14, 12,
             'NB : Conservez précieusement ce bulletin. Il ne sera délivré aucun duplicata.')", [$ecole]);
    $p = ligne('SELECT * FROM parametres_bulletin WHERE ecole_id = ?', [$ecole]);
  }
  $p['appreciations'] = json_decode($p['appreciations'], true) ?: [];
  usort($p['appreciations'], fn($a, $b) => $b[0] <=> $a[0]);
  return $p;
}

function appreciation(?float $moy, array $bareme): ?string {
  if ($moy === null) return null;
  foreach ($bareme as [$seuil, $libelle]) if ($moy >= (float)$seuil) return $libelle;
  return null;
}

// Mention du conseil : la plus haute atteinte (avertissement et blâme restent décidés à la main)
function mention(?float $moy, array $p): ?string {
  if ($moy === null) return null;
  foreach ([['FELICITATIONS', $p['seuil_felicitations']], ['ENCOURAGEMENTS', $p['seuil_encouragements']], ['TABLEAU_HONNEUR', $p['seuil_tableau']]] as [$m, $s])
    if ($s !== null && $moy >= (float)$s) return $m;
  return null;
}

function moyenne_ponderee(array $lignes): ?float {
  $pts = 0; $coef = 0;
  foreach ($lignes as [$moy, $c]) { if ($moy === null) continue; $pts += $moy * $c; $coef += $c; }
  return $coef ? round($pts / $coef, 2) : null;
}

function r_bul_classe() {
  $c = classe(entier('classe_id'));
  verifier_consultation_classe($c);
  $p = periode(entier('periode_id'));
  if ((int)$p['ecole_id'] !== (int)$c['ecole_id']) erreur("Cette période n'appartient pas à l'école de la classe.");
  $param = parametres_bulletin((int)$c['ecole_id']);
  $ecole = entete_ecole((int)$c['ecole_id']) + ['site_web' => ligne('SELECT site_web FROM ecoles WHERE id = ?', [$c['ecole_id']])['site_web']];
  $annee = ligne('SELECT libelle FROM annees_scolaires WHERE id = ?', [$c['annee_id']])['libelle'];

  $res = resultats_classe((int)$c['id'], (int)$p['id']);
  $matieres = lignes("SELECT cm.id, m.libelle, COALESCE(m.libelle_court, m.libelle) AS court, m.est_conduite, cm.coefficient, cm.groupe_bulletin,
                             CONCAT(u.nom, ' ', u.prenoms) AS professeur
                      FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id LEFT JOIN utilisateurs u ON u.id = cm.professeur_id
                      WHERE cm.classe_id = ? AND cm.actif = 1 ORDER BY cm.ordre_affichage, m.libelle", [$c['id']]);

  // Notes de chaque évaluation validée (pour les colonnes DTL, 1er devoir, 2e devoir…)
  $detail = []; $nbDevoirs = 2; $avecDtl = false;
  foreach (lignes("SELECT n.inscription_id, e.classe_matiere_id, e.type, e.numero, n.statut, n.valeur
                   FROM notes n JOIN evaluations e ON e.id = n.evaluation_id JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                   WHERE cm.classe_id = ? AND e.periode_id = ? AND e.statut = 'VALIDEE' AND e.type IN ('DEVOIR','DTL')", [$c['id'], $p['id']]) as $n) {
    $v = $n['statut'] === 'NOTE' ? (float)$n['valeur'] : ($n['statut'] === 'ABSENT_NON_JUSTIFIE' ? 0.0 : null);
    if ($n['type'] === 'DTL') { $avecDtl = true; $detail[$n['inscription_id']][$n['classe_matiere_id']]['dtl'][] = $v; }
    else { $nbDevoirs = max($nbDevoirs, (int)$n['numero']); $detail[$n['inscription_id']][$n['classe_matiere_id']]['devoirs'][(int)$n['numero']] = $v; }
  }

  // Récapitulatif des trimestres précédents (moyenne et rang de chaque élève)
  $recap = [];
  foreach (lignes('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? AND numero <= ? ORDER BY numero', [$c['ecole_id'], $c['annee_id'], $p['numero']]) as $pp) {
    $r = (int)$pp['id'] === (int)$p['id'] ? $res : resultats_classe((int)$c['id'], (int)$pp['id']);
    foreach ($r['eleves'] as $e) $recap[$e['inscription_id']][] = ['periode' => $pp['libelle'], 'moyenne' => $e['moyenne_generale'], 'rang' => $e['rang'] ?? null, 'ex_aequo' => $e['ex_aequo'] ?? false];
  }

  // Absences de la période (si le bulletin les affiche)
  $absences = [];
  if ($param['afficher_absences'] && $p['date_debut']) {
    foreach (lignes("SELECT inscription_id, COALESCE(SUM(CASE WHEN statut='ABSENT' AND justifiee=1 THEN heures END),0) AS j,
                            COALESCE(SUM(CASE WHEN statut='ABSENT' AND justifiee=0 THEN heures END),0) AS nj, SUM(statut='RETARD') AS r
                     FROM absences WHERE classe_id = ? AND date_absence BETWEEN ? AND ? GROUP BY inscription_id", [$c['id'], $p['date_debut'], $p['date_fin']]) as $a)
      $absences[$a['inscription_id']] = ['justifiees' => (float)$a['j'], 'non_justifiees' => (float)$a['nj'], 'retards' => (int)$a['r']];
  }

  $infos = lignes("SELECT i.id, el.matricule, el.educmaster, el.nom, el.prenoms, el.sexe, el.date_naissance, el.lieu_naissance, i.redoublant
                   FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id WHERE i.classe_id = ? AND i.statut = 'ACTIF'", [$c['id']]);
  $infos = array_column($infos, null, 'id');
  $groupes = [];
  foreach ($matieres as $m) if ($m['groupe_bulletin']) $groupes[$m['groupe_bulletin']] = true;

  $bulletins = [];
  foreach ($res['eleves'] as $e) {
    $id = $e['inscription_id']; $lignes = []; $parGroupe = [];
    foreach ($matieres as $m) {
      $x = $e['matieres'][$m['id']] ?? null;
      $d = $detail[$id][$m['id']] ?? [];
      $dtl = array_values(array_filter($d['dtl'] ?? [], fn($v) => $v !== null));
      $lignes[] = ['matiere' => $m['court'], 'coefficient' => (float)$m['coefficient'], 'professeur' => $m['professeur'], 'conduite' => (bool)$m['est_conduite'],
                   'moy_interros' => $x['moy_interros'] ?? null, 'dtl' => $dtl ? round(array_sum($dtl) / count($dtl), 2) : null,
                   'devoirs' => $d['devoirs'] ?? [], 'moyenne' => $x['moyenne'] ?? null, 'points' => $x['points'] ?? null,
                   'rang' => $x['rang'] ?? null, 'ex_aequo' => $x['ex_aequo'] ?? false, 'appreciation' => appreciation($x['moyenne'] ?? null, $param['appreciations'])];
      if ($m['groupe_bulletin']) $parGroupe[$m['groupe_bulletin']][] = [$x['moyenne'] ?? null, (float)$m['coefficient']];
    }
    $bilans = [];
    foreach (array_keys($groupes) as $g) { $moy = moyenne_ponderee($parGroupe[$g] ?? []); $bilans[] = ['groupe' => $g, 'moyenne' => $moy, 'appreciation' => appreciation($moy, $param['appreciations'])]; }
    $i = $infos[$id] ?? [];
    $bulletins[] = ['eleve' => $i, 'lignes' => $lignes, 'bilans' => $bilans,
      'total_coefficients' => $e['total_coefficients'], 'total_points' => $e['total_points'], 'moyenne' => $e['moyenne_generale'],
      'rang' => $e['rang'] ?? null, 'ex_aequo' => $e['ex_aequo'] ?? false,
      'appreciation' => appreciation($e['moyenne_generale'], $param['appreciations']), 'mention' => mention($e['moyenne_generale'], $param),
      'recap' => $recap[$id] ?? [], 'absences' => $absences[$id] ?? ($param['afficher_absences'] ? ['justifiees' => 0, 'non_justifiees' => 0, 'retards' => 0] : null)];
  }

  $gr = ligne('SELECT g.nom, g.sigle FROM groupes g JOIN ecoles e ON e.groupe_id = g.id WHERE e.id = ?', [$c['ecole_id']]);
  repondre(['ecole' => $ecole, 'parametres' => $param, 'groupe' => $gr, 'annee' => $annee, 'classe' => $c['nom'], 'periode' => $p,
            'effectif' => count($res['eleves']), 'statistiques' => $res['statistiques'], 'colonnes' => ['devoirs' => $nbDevoirs, 'dtl' => $avecDtl],
            'controles' => controles_bulletin((int)$c['id'], (int)$p['id']), 'bulletins' => $bulletins, 'edite_le' => date('Y-m-d')]);
}

// Ce qui empêche d'avoir des bulletins complets et justes
function controles_bulletin(int $classe, int $periode): array {
  $a = [];
  $nv = lignes("SELECT COALESCE(m.libelle_court, m.libelle) AS matiere, e.type, e.numero FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                JOIN matieres m ON m.id = cm.matiere_id WHERE cm.classe_id = ? AND e.periode_id = ? AND e.statut = 'BROUILLON' ORDER BY m.libelle, e.type, e.numero", [$classe, $periode]);
  if ($nv) $a[] = count($nv) . ' évaluation(s) pas encore validée(s), donc non comptée(s) : '
             . implode(', ', array_map(fn($x) => "{$x['matiere']} (" . ['INTERRO' => 'interro', 'DEVOIR' => 'devoir', 'DTL' => 'DTL'][$x['type']] . " {$x['numero']})", array_slice($nv, 0, 8))) . (count($nv) > 8 ? '…' : '') . '.';
  $sans = lignes("SELECT COALESCE(m.libelle_court, m.libelle) AS matiere FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id
                  WHERE cm.classe_id = ? AND cm.actif = 1 AND NOT EXISTS (SELECT 1 FROM evaluations e WHERE e.classe_matiere_id = cm.id AND e.periode_id = ? AND e.statut = 'VALIDEE')", [$classe, $periode]);
  if ($sans) $a[] = 'Matière(s) sans aucune note validée, absentes de la moyenne : ' . implode(', ', array_column($sans, 'matiere')) . '.';
  $abs = (int)ligne("SELECT COUNT(*) AS n FROM notes n JOIN evaluations e ON e.id = n.evaluation_id JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                     WHERE cm.classe_id = ? AND e.periode_id = ? AND n.statut = 'ABSENT'", [$classe, $periode])['n'];
  if ($abs) $a[] = "$abs absence(s) à un devoir ou une interro pas encore traitée(s) par la direction (justifiée ou non) : elles sont ignorées dans le calcul.";
  $coef = (int)ligne('SELECT COUNT(*) AS n FROM classe_matieres WHERE classe_id = ? AND actif = 1 AND coef_a_confirmer = 1', [$classe])['n'];
  if ($coef) $a[] = "$coef coefficient(s) encore provisoire(s) (à confirmer dans Paramètres).";
  return $a;
}

// ---------------------------------------------------------------- Réglages du bulletin
function r_bul_parametres() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  repondre(parametres_bulletin(ecole_cible()));
}

function r_bul_parametres_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible(); parametres_bulletin($e);
  $bareme = champ('appreciations');
  if (!is_array($bareme) || !$bareme) erreur("Le barème des appréciations est vide.");
  $propre = [];
  foreach ($bareme as $l) {
    $s = str_replace(',', '.', (string)($l[0] ?? '')); $lib = trim((string)($l[1] ?? ''));
    if (!is_numeric($s) || $s < 0 || $s > 20 || $lib === '') erreur('Chaque ligne du barème doit avoir un seuil (0 à 20) et un libellé.');
    $propre[] = [(float)$s, mb_substr($lib, 0, 40)];
  }
  usort($propre, fn($a, $b) => $b[0] <=> $a[0]);
  if (end($propre)[0] > 0) $propre[] = [0, 'Insuffisant'];
  $seuil = function ($k) { $v = champ($k, false); if ($v === null || $v === '') return null; $v = str_replace(',', '.', (string)$v); if (!is_numeric($v) || $v < 0 || $v > 20) erreur('Seuil de mention invalide.'); return (float)$v; };
  requete('UPDATE parametres_bulletin SET appreciations = ?, seuil_felicitations = ?, seuil_encouragements = ?, seuil_tableau = ?, afficher_absences = ?,
           titre_signataire = ?, nom_signataire = ?, note_bas = ?, a_confirmer = 0 WHERE ecole_id = ?',
          [json_encode($propre, JSON_UNESCAPED_UNICODE), $seuil('seuil_felicitations'), $seuil('seuil_encouragements'), $seuil('seuil_tableau'),
           (int)(bool)champ('afficher_absences', false), (string)(champ('titre_signataire', false) ?? 'Le Chef d\'établissement'),
           texte_ou_null('nom_signataire'), texte_ou_null('note_bas'), $e]);
  journaliser('MODIFICATION', 'parametres_bulletin', $e, 'Réglages du bulletin');
  repondre();
}
