<?php
// =====================================================================
//  ABSENCES ET RETARDS : appel par demi-journée, justification, bilans
// =====================================================================
const ROLES_VIE_SCOLAIRE = ['SUPER_ADMIN', 'DIRECTRICE', 'SECRETARIAT'];

// Faire l'appel : direction, secrétariat, ou professeur enseignant dans la classe
function verifier_droit_appel(array $c): void {
  global $UTILISATEUR;
  if (in_array($UTILISATEUR['role'], ROLES_VIE_SCOLAIRE, true)) return;
  if ($UTILISATEUR['role'] === 'PROFESSEUR' && ((int)$c['prof_principal_id'] === (int)$UTILISATEUR['id']
      || ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND professeur_id = ? AND actif = 1 LIMIT 1', [$c['id'], $UTILISATEUR['id']]))) return;
  erreur("Vous n'enseignez pas dans cette classe.", 403);
}

function periode_de_la_date(int $ecole, int $annee, string $date): ?array {
  return ligne('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? AND date_debut <= ? AND date_fin >= ? LIMIT 1', [$ecole, $annee, $date, $date]);
}

function date_appel(): string {
  $d = (string)champ('date');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) erreur('Date invalide.');
  if ($d > date('Y-m-d')) erreur("On ne peut pas faire l'appel d'un jour à venir.");
  return $d;
}

function creneau_appel(): string {
  $c = strtoupper((string)champ('creneau'));
  if (!in_array($c, ['MATIN', 'APRES_MIDI'], true)) erreur('Demi-journée : MATIN ou APRES_MIDI.');
  return $c;
}

// Classes où l'utilisateur peut faire l'appel
function r_abs_mes_classes() {
  global $UTILISATEUR;
  $a = annee_en_cours();
  if ($UTILISATEUR['role'] === 'PROFESSEUR') {
    repondre(lignes("SELECT DISTINCT c.id, c.nom, c.ecole_id FROM classes c JOIN niveaux n ON n.id = c.niveau_id
                     LEFT JOIN classe_matieres cm ON cm.classe_id = c.id AND cm.actif = 1
                     WHERE c.annee_id = ? AND (cm.professeur_id = ? OR c.prof_principal_id = ?) ORDER BY n.ordre, c.nom",
                    [$a['id'], $UTILISATEUR['id'], $UTILISATEUR['id']]));
  }
  exiger_role(...ROLES_VIE_SCOLAIRE);
  $e = ecole_cible();
  repondre(lignes('SELECT c.id, c.nom, c.ecole_id FROM classes c JOIN niveaux n ON n.id = c.niveau_id WHERE c.ecole_id = ? AND c.annee_id = ? ORDER BY n.ordre, c.nom', [$e, $a['id']]));
}

function r_abs_appel() {
  $c = classe(entier('classe_id'));
  verifier_droit_appel($c);
  $date = date_appel(); $creneau = creneau_appel();
  $param = ligne('SELECT heures_matin, heures_apres_midi FROM parametres_annee WHERE ecole_id = ? AND annee_id = ?', [$c['ecole_id'], $c['annee_id']]);
  $periode = periode_de_la_date((int)$c['ecole_id'], (int)$c['annee_id'], $date);
  $eleves = lignes("SELECT i.id AS inscription_id, el.nom, el.prenoms, el.sexe, a.id AS absence_id, a.statut, a.heures, a.minutes, a.justifiee, a.motif
                    FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                    LEFT JOIN absences a ON a.inscription_id = i.id AND a.date_absence = ? AND a.creneau = ?
                    WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY el.nom, el.prenoms", [$date, $creneau, $c['id']]);
  $deja = (bool)ligne('SELECT id FROM journal_audit WHERE action = ? AND table_cible = ? AND enregistrement_id = ? AND description LIKE ? LIMIT 1',
                      ['APPEL', 'classes', $c['id'], "%$date $creneau%"]);
  repondre(['classe' => $c['nom'], 'date' => $date, 'creneau' => $creneau, 'eleves' => $eleves, 'appel_deja_fait' => $deja,
            'periode' => $periode ? ['libelle' => $periode['libelle'], 'statut' => $periode['statut']] : null,
            'heures_defaut' => (float)($creneau === 'MATIN' ? ($param['heures_matin'] ?? 4) : ($param['heures_apres_midi'] ?? 2))]);
}

// Enregistre l'appel : la liste complète des absents et retards de la demi-journée
function r_abs_appel_enregistrer() {
  global $UTILISATEUR;
  $c = classe(entier('classe_id'));
  verifier_droit_appel($c);
  $date = date_appel(); $creneau = creneau_appel();
  if ($date < ligne('SELECT date_debut FROM annees_scolaires WHERE id = ?', [$c['annee_id']])['date_debut']) erreur("Cette date est avant le début de l'année scolaire.");
  $p = periode_de_la_date((int)$c['ecole_id'], (int)$c['annee_id'], $date);
  if ($p && $p['statut'] !== 'OUVERTE') erreur("Le {$p['libelle']} est clôturé : l'appel de cette date ne peut plus être modifié.", 409);
  $lignes = champ('lignes', false) ?? [];
  if (!is_array($lignes)) erreur('Liste invalide.');
  $inscrits = array_flip(array_map('intval', array_column(lignes("SELECT id FROM inscriptions WHERE classe_id = ? AND statut = 'ACTIF'", [$c['id']]), 'id')));

  $voulus = [];
  foreach ($lignes as $l) {
    $id = (int)($l['inscription_id'] ?? 0);
    if (!isset($inscrits[$id])) erreur("Élève $id absent de cette classe.");
    $statut = strtoupper((string)($l['statut'] ?? ''));
    if ($statut === 'ABSENT') {
      $h = (float)str_replace(',', '.', (string)($l['heures'] ?? 0));
      if ($h <= 0 || $h > 12) erreur("Nombre d'heures d'absence invalide (entre 0,5 et 12).");
      $voulus[$id] = ['ABSENT', round($h * 2) / 2, null];
    } elseif ($statut === 'RETARD') {
      $m = (int)($l['minutes'] ?? 0);
      if ($m <= 0 || $m > 240) erreur('Durée du retard invalide (en minutes, 1 à 240).');
      $voulus[$id] = ['RETARD', null, $m];
    }
  }
  $existants = [];
  foreach (lignes('SELECT a.* FROM absences a JOIN inscriptions i ON i.id = a.inscription_id WHERE i.classe_id = ? AND a.date_absence = ? AND a.creneau = ?',
                  [$c['id'], $date, $creneau]) as $x) $existants[(int)$x['inscription_id']] = $x;

  bd()->beginTransaction();
  $nA = 0; $nR = 0;
  foreach ($voulus as $id => [$statut, $h, $m]) {
    $statut === 'ABSENT' ? $nA++ : $nR++;
    if (isset($existants[$id])) {
      $x = $existants[$id];
      if ($x['statut'] !== $statut || (float)$x['heures'] !== (float)$h || (int)$x['minutes'] !== (int)$m)
        requete('UPDATE absences SET statut = ?, heures = ?, minutes = ?, modifie_par = ?, modifie_le = NOW() WHERE id = ?', [$statut, $h, $m, $UTILISATEUR['id'], $x['id']]);
    } else {
      requete('INSERT INTO absences (inscription_id, ecole_id, classe_id, date_absence, creneau, statut, heures, minutes, saisi_par) VALUES (?,?,?,?,?,?,?,?,?)',
              [$id, $c['ecole_id'], $c['id'], $date, $creneau, $statut, $h, $m, $UTILISATEUR['id']]);
    }
  }
  // Élève finalement présent : l'absence enregistrée est retirée (sauf si elle a déjà été justifiée)
  foreach ($existants as $id => $x) {
    if (isset($voulus[$id])) continue;
    if ($x['justifiee']) { bd()->rollBack(); erreur("Une absence déjà justifiée ne peut pas être retirée de l'appel. Demandez à la direction.", 409); }
    requete('DELETE FROM absences WHERE id = ?', [$x['id']]);
  }
  bd()->commit();
  journaliser('APPEL', 'classes', $c['id'], "{$c['nom']} $date $creneau : $nA absent(s), $nR retard(s)");
  repondre(['absents' => $nA, 'retards' => $nR]);
}

function r_abs_justifier() {
  global $UTILISATEUR;
  exiger_role(...ROLES_VIE_SCOLAIRE);
  $x = ligne('SELECT a.*, el.nom, el.prenoms FROM absences a JOIN inscriptions i ON i.id = a.inscription_id JOIN eleves el ON el.id = i.eleve_id WHERE a.id = ?', [entier('id')]);
  if (!$x) erreur('Absence introuvable.', 404);
  verifier_ecole((int)$x['ecole_id']);
  $j = (int)(bool)champ('justifiee');
  $motif = texte_ou_null('motif');
  if ($j && mb_strlen((string)$motif) < 3) erreur('Indiquez le motif (maladie, deuil, convocation…).');
  if (!$j && $x['justifiee'] && !in_array($UTILISATEUR['role'], ROLES_ADMIN_ECOLE, true)) erreur("Seule la direction peut annuler une justification.", 403);
  requete('UPDATE absences SET justifiee = ?, motif = ?, justifiee_par = ?, justifiee_le = ? WHERE id = ?',
          [$j, $motif, $j ? $UTILISATEUR['id'] : null, $j ? date('Y-m-d H:i:s') : null, $x['id']]);
  journaliser($j ? 'JUSTIFICATION' : 'MODIFICATION', 'absences', $x['id'],
              "{$x['nom']} {$x['prenoms']}, {$x['date_absence']} : " . ($j ? "justifiée ($motif)" : 'justification retirée'),
              ['justifiee' => (int)$x['justifiee']], ['justifiee' => $j]);
  repondre();
}

// Absences en attente de justification (les plus récentes d'abord)
function r_abs_a_justifier() {
  exiger_role(...ROLES_VIE_SCOLAIRE);
  $e = ecole_cible();
  repondre(lignes("SELECT a.id, a.date_absence, a.creneau, a.statut, a.heures, a.minutes, el.nom, el.prenoms, c.nom AS classe, i.id AS inscription_id
                   FROM absences a JOIN inscriptions i ON i.id = a.inscription_id JOIN eleves el ON el.id = i.eleve_id JOIN classes c ON c.id = a.classe_id
                   WHERE a.ecole_id = ? AND a.justifiee = 0 AND a.statut = 'ABSENT' ORDER BY a.date_absence DESC, c.nom, el.nom LIMIT 300", [$e]));
}

// Totaux d'absences par élève sur une période (ce qui ira sur le bulletin)
function totaux_absences(string $condition, array $params): array {
  return lignes("SELECT a.inscription_id,
                   COALESCE(SUM(CASE WHEN a.statut = 'ABSENT' AND a.justifiee = 1 THEN a.heures END), 0) AS heures_justifiees,
                   COALESCE(SUM(CASE WHEN a.statut = 'ABSENT' AND a.justifiee = 0 THEN a.heures END), 0) AS heures_non_justifiees,
                   SUM(a.statut = 'RETARD') AS retards
                 FROM absences a WHERE $condition GROUP BY a.inscription_id", $params);
}

function r_abs_bilan_classe() {
  $c = classe(entier('classe_id'));
  verifier_droit_appel($c);
  $p = periode(entier('periode_id'));
  if (!$p['date_debut'] || !$p['date_fin']) erreur("Les dates du {$p['libelle']} ne sont pas renseignées (Paramètres > Année et périodes).", 409);
  $tot = []; foreach (totaux_absences('a.classe_id = ? AND a.date_absence BETWEEN ? AND ?', [$c['id'], $p['date_debut'], $p['date_fin']]) as $t) $tot[$t['inscription_id']] = $t;
  $eleves = lignes("SELECT i.id AS inscription_id, el.id AS eleve_id, el.nom, el.prenoms FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                    WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY el.nom, el.prenoms", [$c['id']]);
  foreach ($eleves as &$e) { $t = $tot[$e['inscription_id']] ?? null;
    $e['heures_justifiees'] = (float)($t['heures_justifiees'] ?? 0); $e['heures_non_justifiees'] = (float)($t['heures_non_justifiees'] ?? 0); $e['retards'] = (int)($t['retards'] ?? 0); }
  repondre(['classe' => $c['nom'], 'periode' => $p['libelle'], 'du' => $p['date_debut'], 'au' => $p['date_fin'], 'eleves' => $eleves]);
}

// Historique d'un élève, avec totaux par période
function r_abs_eleve() {
  global $UTILISATEUR;
  $i = ligne('SELECT * FROM inscriptions WHERE id = ?', [entier('inscription_id')]);
  if (!$i) erreur('Inscription introuvable.', 404);
  verifier_ecole((int)$i['ecole_id']);
  if (!in_array($UTILISATEUR['role'], ROLES_VIE_SCOLAIRE, true)) verifier_droit_appel(classe((int)$i['classe_id']));
  $liste = lignes("SELECT a.*, CONCAT(u.nom, ' ', u.prenoms) AS saisi_par_nom FROM absences a JOIN utilisateurs u ON u.id = a.saisi_par
                   WHERE a.inscription_id = ? ORDER BY a.date_absence DESC, a.creneau", [$i['id']]);
  $periodes = lignes('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? ORDER BY numero', [$i['ecole_id'], $i['annee_id']]);
  foreach ($periodes as &$p) {
    $t = $p['date_debut'] ? (totaux_absences('a.inscription_id = ? AND a.date_absence BETWEEN ? AND ?', [$i['id'], $p['date_debut'], $p['date_fin']])[0] ?? null) : null;
    $p = ['libelle' => $p['libelle'], 'heures_justifiees' => (float)($t['heures_justifiees'] ?? 0),
          'heures_non_justifiees' => (float)($t['heures_non_justifiees'] ?? 0), 'retards' => (int)($t['retards'] ?? 0)];
  }
  repondre(['absences' => $liste, 'periodes' => $periodes]);
}
