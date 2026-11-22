<?php
// =====================================================================
//  PRIMAIRE et MATERNELLE
//  Primaire : 5 évaluations formatives (réparties 2 + 2 + 1 par trimestre, réglable),
//  chacune = une évaluation de type EVAL par matière. Pas de coefficient.
//    moyenne  = somme des notes ÷ matières composées
//    seuil    = nombre de matières réussies (note ≥ note de réussite, 10 par défaut)
//    requis   = arrondi supérieur de (matières composées × 2/3), soit 6 sur 9
//    trimestre : moyenne des moyennes, seuil = moyenne des seuils arrondie
//    année     : moyenne des trimestres ; passage si au moins 3 évaluations réussies sur 5
//  Seules les évaluations VALIDÉES comptent dans les bilans et les bulletins.
//  Maternelle : grille trimestrielle d'observation, 11 domaines notés TS / S / PS.
// =====================================================================
require_once __DIR__ . '/eleves.php';

const DOMAINES_MATERNELLE = [
  'Fréquentation',
  "Activités d'éducation pour la santé (activité de vie pratique)",
  "Activités d'éducation pour la santé (éducation à des réflexes de santé)",
  "Activités d'expression orale",
  "Activités d'expression corporelle",
  "Activités d'expression plastique",
  "Éducation d'éveil scientifique et technologique",
  'Pré-lecture',
  'Pré-mathématique',
  'Pré-écriture',
  'Activités libres',
];

function params_primaire(int $ecole): array {
  requete('INSERT IGNORE INTO parametres_primaire (ecole_id) VALUES (?)', [$ecole]);
  $p = ligne('SELECT * FROM parametres_primaire WHERE ecole_id = ?', [$ecole]);
  $rep = array_values(array_filter(array_map('intval', explode(',', $p['repartition'])), fn($n) => $n > 0)) ?: [2, 2, 1];
  return ['note_reussite' => (float)$p['note_reussite'], 'seuil_num' => (int)$p['seuil_num'], 'seuil_den' => max(1, (int)$p['seuil_den']),
          'repartition' => $rep, 'nb_evaluations' => array_sum($rep), 'passage_min' => (int)$p['passage_min'], 'a_confirmer' => (bool)$p['a_confirmer']];
}

function prim_trimestre_de(array $p, int $numero): int {
  $cumul = 0;
  foreach ($p['repartition'] as $i => $n) { $cumul += $n; if ($numero <= $cumul) return $i + 1; }
  return count($p['repartition']);
}

function prim_numeros(array $p, int $trimestre): array {
  if (!isset($p['repartition'][$trimestre - 1])) return [];
  $debut = array_sum(array_slice($p['repartition'], 0, $trimestre - 1));
  return range($debut + 1, $debut + $p['repartition'][$trimestre - 1]);
}

function classe_cycle(int $id, string $cycle): array {
  $c = classe($id);
  if ($c['cycle'] !== $cycle) erreur($cycle === 'PRIMAIRE' ? "Cette classe n'est pas une classe du primaire." : "Cette classe n'est pas une classe de maternelle.");
  return $c;
}

// Saisie : direction, coordination pédagogique, ou le titulaire de la classe
function verifier_droit_primaire(array $c): void {
  global $UTILISATEUR;
  if (in_array($UTILISATEUR['role'], ROLES_NOTES, true)) return;
  if ($UTILISATEUR['role'] === 'PROFESSEUR' && (int)$c['prof_principal_id'] === (int)$UTILISATEUR['id']) return;
  erreur("Vous n'êtes pas le titulaire de cette classe.", 403);
}

function periode_trimestre(array $c, int $t): array {
  $p = ligne('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? AND numero = ?', [$c['ecole_id'], $c['annee_id'], $t]);
  if (!$p) erreur("Le trimestre $t n'existe pas pour cette école (Paramètres > Année et périodes).");
  return $p;
}

function prim_matieres(int $classe): array {
  return lignes('SELECT cm.id, m.libelle, COALESCE(m.libelle_court, m.libelle) AS court
                 FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id
                 WHERE cm.classe_id = ? AND cm.actif = 1 ORDER BY cm.ordre_affichage, m.libelle', [$classe]);
}

function prim_eleves(int $classe): array {
  return lignes("SELECT i.id AS inscription_id, el.id AS eleve_id, el.matricule, el.nom, el.prenoms, el.sexe, el.date_naissance, el.lieu_naissance
                 FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                 WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY el.nom, el.prenoms", [$classe]);
}

// Évaluations (une par matière) d'une évaluation formative, indexées par matière de classe
function prim_evaluations(int $classe, int $numero): array {
  $r = [];
  foreach (lignes("SELECT e.id, e.classe_matiere_id, e.statut, e.periode_id FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                   WHERE cm.classe_id = ? AND e.type = 'EVAL' AND e.numero = ?", [$classe, $numero]) as $e) $r[(int)$e['classe_matiere_id']] = $e;
  return $r;
}

// Calcul d'une évaluation pour un élève. $notes : [ ['statut' => …, 'valeur' => …], … ] une par matière
function prim_calcul(array $notes, array $p): ?array {
  $composees = 0; $somme = 0.0; $reussies = 0;
  foreach ($notes as $n) {
    if ($n['statut'] === 'NOTE') { $composees++; $somme += (float)$n['valeur']; if ((float)$n['valeur'] >= $p['note_reussite']) $reussies++; }
    elseif ($n['statut'] === 'ABSENT_NON_JUSTIFIE') $composees++;                 // compte 0
    // ABSENT (à traiter), ABSENT_JUSTIFIE, DISPENSE : matière non composée
  }
  if ($composees === 0) return null;
  $requis = intdiv($composees * $p['seuil_num'] + $p['seuil_den'] - 1, $p['seuil_den']);
  return ['moyenne' => round($somme / $composees, 2), 'seuil' => $reussies, 'requis' => $requis,
          'composees' => $composees, 'reussi' => $reussies >= $requis];
}

// Moyenne et seuil d'un ensemble d'évaluations (trimestre) : seuil = moyenne des seuils, arrondie
function prim_bilan(array $calculs): ?array {
  $calculs = array_values(array_filter($calculs));
  if (!$calculs) return null;
  $k = count($calculs);
  $brut = array_sum(array_column($calculs, 'seuil')) / $k;
  $seuil = (int)round($brut);
  $requis = (int)round(array_sum(array_column($calculs, 'requis')) / $k);
  return ['moyenne' => round(array_sum(array_column($calculs, 'moyenne')) / $k, 2), 'seuil' => $seuil, 'seuil_brut' => round($brut, 2),
          'requis' => $requis, 'reussi' => $seuil >= $requis, 'nb_evaluations' => $k];
}

// ---------------------------------------------------------------------
function r_prim_mes_classes() {
  global $UTILISATEUR;
  $sql = "SELECT c.id, c.nom, nv.cycle, c.ecole_id, e.nom_officiel AS ecole, CONCAT(u.nom, ' ', u.prenoms) AS titulaire,
                 (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'ACTIF') AS effectif
          FROM classes c JOIN niveaux nv ON nv.id = c.niveau_id JOIN ecoles e ON e.id = c.ecole_id
          JOIN annees_scolaires a ON a.id = c.annee_id AND a.en_cours = 1
          LEFT JOIN utilisateurs u ON u.id = c.prof_principal_id
          WHERE nv.cycle IN ('MATERNELLE','PRIMAIRE')";
  $ordre = ' ORDER BY e.nom_officiel, nv.ordre, c.nom';
  if ($UTILISATEUR['role'] === 'PROFESSEUR') repondre(lignes("$sql AND c.prof_principal_id = ?$ordre", [$UTILISATEUR['id']]));
  if (est_transversal()) {
    $g = entier('groupe_id', false);
    repondre($g ? lignes("$sql AND e.groupe_id = ?$ordre", [$g]) : lignes($sql . $ordre));
  }
  if ($UTILISATEUR['role'] === 'DIRECTRICE') repondre(lignes("$sql AND c.ecole_id = ?$ordre", [$UTILISATEUR['ecole_id']]));
  erreur("Vous n'avez pas les droits pour cette action.", 403);
}

// Grille de saisie d'une évaluation formative : élèves × matières
function r_prim_grille() {
  $c = classe_cycle(entier('classe_id'), 'PRIMAIRE');
  verifier_droit_primaire($c);
  $p = params_primaire((int)$c['ecole_id']);
  $num = entier('numero');
  if ($num < 1 || $num > $p['nb_evaluations']) erreur("L'évaluation doit être numérotée de 1 à {$p['nb_evaluations']}.");
  $t = prim_trimestre_de($p, $num);
  $per = periode_trimestre($c, $t);
  $matieres = prim_matieres((int)$c['id']);
  if (!$matieres) erreur("Aucune matière dans cette classe (Paramètres > Classes et matières).");
  $evals = prim_evaluations((int)$c['id'], $num);
  foreach ($matieres as &$m) { $m['evaluation_id'] = $evals[(int)$m['id']]['id'] ?? null; $m['statut'] = $evals[(int)$m['id']]['statut'] ?? null; }
  unset($m);
  $nbVal = count(array_filter($evals, fn($e) => $e['statut'] === 'VALIDEE'));
  $statut = !$evals ? 'NOUVELLE' : ($nbVal === count($matieres) ? 'VALIDEE' : 'BROUILLON');

  $notes = [];
  foreach (lignes("SELECT n.inscription_id, e.classe_matiere_id, n.valeur, n.statut FROM notes n JOIN evaluations e ON e.id = n.evaluation_id
                   JOIN classe_matieres cm ON cm.id = e.classe_matiere_id WHERE cm.classe_id = ? AND e.type = 'EVAL' AND e.numero = ?", [$c['id'], $num]) as $n)
    $notes[(int)$n['inscription_id']][(int)$n['classe_matiere_id']] = ['valeur' => $n['valeur'], 'statut' => $n['statut']];
  $eleves = prim_eleves((int)$c['id']);
  foreach ($eleves as &$e) {
    $e['notes'] = (object)($notes[(int)$e['inscription_id']] ?? []);
    $e['calcul'] = prim_calcul(array_values($notes[(int)$e['inscription_id']] ?? []), $p);
  }
  unset($e);

  $etats = [];
  foreach (lignes("SELECT e.numero, COUNT(*) AS nb, SUM(e.statut = 'VALIDEE') AS validees FROM evaluations e
                   JOIN classe_matieres cm ON cm.id = e.classe_matiere_id WHERE cm.classe_id = ? AND e.type = 'EVAL' GROUP BY e.numero", [$c['id']]) as $r)
    $etats[(int)$r['numero']] = (int)$r['validees'] === count($matieres) ? 'VALIDEE' : 'BROUILLON';
  $liste = [];
  for ($i = 1; $i <= $p['nb_evaluations']; $i++) $liste[] = ['numero' => $i, 'trimestre' => prim_trimestre_de($p, $i), 'statut' => $etats[$i] ?? 'NOUVELLE'];

  repondre(['classe' => ['id' => (int)$c['id'], 'nom' => $c['nom'], 'ecole_id' => (int)$c['ecole_id']], 'numero' => $num, 'trimestre' => $t,
            'periode' => ['id' => (int)$per['id'], 'libelle' => $per['libelle'], 'statut' => $per['statut']],
            'parametres' => $p, 'statut' => $statut, 'evaluations' => $liste, 'matieres' => $matieres, 'eleves' => $eleves]);
}

// Enregistre les notes d'une évaluation formative tant qu'elle n'est pas validée
// notes = [ {inscription_id, classe_matiere_id, valeur} | {…, statut:"ABSENT"} ]
function r_prim_enregistrer() {
  global $UTILISATEUR;
  $c = classe_cycle(entier('classe_id'), 'PRIMAIRE');
  verifier_droit_primaire($c);
  $p = params_primaire((int)$c['ecole_id']);
  $num = entier('numero');
  if ($num < 1 || $num > $p['nb_evaluations']) erreur("Numéro d'évaluation invalide.");
  $per = periode_trimestre($c, prim_trimestre_de($p, $num));
  verifier_periode_ouverte($per);
  $notes = champ('notes');
  if (!is_array($notes) || !$notes) erreur('Aucune note reçue.');
  $matieres = array_flip(array_map(fn($m) => (int)$m['id'], prim_matieres((int)$c['id'])));
  foreach (prim_evaluations((int)$c['id'], $num) as $e)
    if ($e['statut'] === 'VALIDEE') erreur('Cette évaluation est validée et verrouillée. Toute modification passe par le Secrétariat général.', 409);
  $inscrits = array_flip(array_map('intval', array_column(lignes("SELECT id FROM inscriptions WHERE classe_id = ? AND statut = 'ACTIF'", [$c['id']]), 'id')));
  $permis = $UTILISATEUR['role'] === 'PROFESSEUR' ? ['NOTE', 'ABSENT'] : ['NOTE', 'ABSENT', 'ABSENT_JUSTIFIE', 'ABSENT_NON_JUSTIFIE', 'DISPENSE'];

  bd()->beginTransaction();
  $creer = bd()->prepare("INSERT IGNORE INTO evaluations (classe_matiere_id, periode_id, type, numero) VALUES (?, ?, 'EVAL', ?)");
  foreach (array_keys($matieres) as $cm) $creer->execute([$cm, $per['id'], $num]);
  $evals = prim_evaluations((int)$c['id'], $num);
  $n = 0;
  foreach ($notes as $l) {
    $insc = (int)($l['inscription_id'] ?? 0); $cm = (int)($l['classe_matiere_id'] ?? 0);
    if (!isset($inscrits[$insc])) { bd()->rollBack(); erreur("Élève $insc absent de cette classe."); }
    if (!isset($matieres[$cm])) { bd()->rollBack(); erreur("Matière $cm absente de cette classe."); }
    $statut = strtoupper((string)($l['statut'] ?? 'NOTE'));
    if (!in_array($statut, $permis, true)) { bd()->rollBack(); erreur("Statut « $statut » non autorisé."); }
    if ($statut === 'NOTE' && (($l['valeur'] ?? '') === '' || $l['valeur'] === null)) continue;   // case laissée vide
    $valeur = $statut === 'NOTE' ? note_valide($l['valeur']) : null;
    requete('INSERT INTO notes (evaluation_id, inscription_id, valeur, statut, saisie_par) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), statut = VALUES(statut), modifie_par = ?, modifie_le = NOW()',
            [$evals[$cm]['id'], $insc, $valeur, $statut, $UTILISATEUR['id'], $UTILISATEUR['id']]);
    $n++;
  }
  bd()->commit();
  journaliser('SAISIE_NOTES', 'classes', $c['id'], "{$c['nom']} / Évaluation $num : $n note(s) enregistrée(s)");
  repondre(['notes_enregistrees' => $n]);
}

// Validation : toutes les notes (ou absences) doivent être saisies ; ensuite tout est verrouillé
function r_prim_valider() {
  global $UTILISATEUR;
  $c = classe_cycle(entier('classe_id'), 'PRIMAIRE');
  verifier_droit_primaire($c);
  $p = params_primaire((int)$c['ecole_id']);
  $num = entier('numero');
  verifier_periode_ouverte(periode_trimestre($c, prim_trimestre_de($p, $num)));
  $matieres = prim_matieres((int)$c['id']);
  $evals = prim_evaluations((int)$c['id'], $num);
  if (count($evals) < count($matieres)) erreur("Saisissez et enregistrez d'abord les notes de toutes les matières.", 422);
  if (!array_filter($evals, fn($e) => $e['statut'] !== 'VALIDEE')) erreur('Cette évaluation est déjà validée.', 409);
  $ids = array_values(array_map(fn($e) => (int)$e['id'], $evals));
  $place = implode(',', array_fill(0, count($ids), '?'));
  $manquants = lignes("SELECT el.nom, el.prenoms, m.libelle FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                       JOIN evaluations e ON e.id IN ($place) JOIN classe_matieres cm ON cm.id = e.classe_matiere_id JOIN matieres m ON m.id = cm.matiere_id
                       LEFT JOIN notes n ON n.evaluation_id = e.id AND n.inscription_id = i.id
                       WHERE i.classe_id = ? AND i.statut = 'ACTIF' AND n.id IS NULL ORDER BY el.nom, el.prenoms, cm.ordre_affichage", [...$ids, $c['id']]);
  if ($manquants) {
    $liste = implode(', ', array_map(fn($m) => "{$m['nom']} {$m['prenoms']} ({$m['libelle']})", array_slice($manquants, 0, 5)));
    erreur(count($manquants) . " note(s) manquante(s) : $liste" . (count($manquants) > 5 ? '…' : '') . '. Saisissez une note ou une absence.', 422);
  }
  requete("UPDATE evaluations SET statut = 'VALIDEE', validee_par = ?, validee_le = NOW() WHERE id IN ($place) AND statut = 'BROUILLON'", [$UTILISATEUR['id'], ...$ids]);
  journaliser('VALIDATION', 'classes', $c['id'], "{$c['nom']} / Évaluation $num validée");
  repondre();
}

// La direction (ou la coordination) rouvre une évaluation validée pour que le titulaire la reprenne
function r_prim_rouvrir() {
  exiger_role(...ROLES_CORRECTION_NOTES);
  $c = classe_cycle(entier('classe_id'), 'PRIMAIRE');
  $p = params_primaire((int)$c['ecole_id']);
  $num = entier('numero');
  verifier_periode_ouverte(periode_trimestre($c, prim_trimestre_de($p, $num)));
  $motif = trim((string)champ('motif'));
  if (mb_strlen($motif) < 5) erreur('Le motif est obligatoire (5 caractères minimum).');
  $ids = array_values(array_map(fn($e) => (int)$e['id'], array_filter(prim_evaluations((int)$c['id'], $num), fn($e) => $e['statut'] === 'VALIDEE')));
  if (!$ids) erreur("Cette évaluation n'est pas verrouillée.", 409);
  $place = implode(',', array_fill(0, count($ids), '?'));
  requete("UPDATE evaluations SET statut = 'BROUILLON', validee_par = NULL, validee_le = NULL WHERE id IN ($place)", $ids);
  journaliser('REOUVERTURE', 'classes', $c['id'], "{$c['nom']} / Évaluation $num rouverte. Motif : $motif", ['statut' => 'VALIDEE'], ['statut' => 'BROUILLON']);
  repondre();
}

// Bilans : chaque évaluation validée, chaque trimestre, l'année et la décision de passage
function r_prim_bilan() {
  $c = classe_cycle(entier('classe_id'), 'PRIMAIRE');
  verifier_consultation_classe($c);
  $p = params_primaire((int)$c['ecole_id']);
  $matieres = prim_matieres((int)$c['id']);
  $nbMat = count($matieres);

  // Une évaluation formative compte quand toutes ses matières sont validées
  $valides = [];
  foreach (lignes("SELECT e.numero, SUM(e.statut = 'VALIDEE') AS v FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id
                   WHERE cm.classe_id = ? AND e.type = 'EVAL' GROUP BY e.numero", [$c['id']]) as $r)
    if ($nbMat && (int)$r['v'] === $nbMat) $valides[(int)$r['numero']] = true;

  $notes = [];
  foreach (lignes("SELECT n.inscription_id, e.classe_matiere_id, e.numero, n.valeur, n.statut FROM notes n JOIN evaluations e ON e.id = n.evaluation_id
                   JOIN classe_matieres cm ON cm.id = e.classe_matiere_id WHERE cm.classe_id = ? AND e.type = 'EVAL' AND e.statut = 'VALIDEE'", [$c['id']]) as $n)
    if (isset($valides[(int)$n['numero']]))
      $notes[(int)$n['inscription_id']][(int)$n['numero']][(int)$n['classe_matiere_id']] = ['valeur' => $n['valeur'], 'statut' => $n['statut']];

  $nbTrim = count($p['repartition']);
  $eleves = prim_eleves((int)$c['id']);
  foreach ($eleves as &$e) {
    $evs = [];
    for ($i = 1; $i <= $p['nb_evaluations']; $i++) {
      if (!isset($valides[$i])) continue;
      $ns = $notes[(int)$e['inscription_id']][$i] ?? [];
      $evs[$i] = ['notes' => (object)$ns, 'calcul' => prim_calcul(array_values($ns), $p)];
    }
    $trims = [];
    for ($t = 1; $t <= $nbTrim; $t++)
      $trims[$t] = prim_bilan(array_map(fn($i) => $evs[$i]['calcul'] ?? null, prim_numeros($p, $t)));
    $annuel = prim_bilan(array_filter($trims));
    $reussies = count(array_filter($evs, fn($x) => $x['calcul']['reussi'] ?? false));
    $complet = count($valides) >= $p['nb_evaluations'];
    $e['evaluations'] = (object)$evs;
    $e['trimestres'] = (object)$trims;
    $e['annee'] = $annuel ? $annuel + ['reussies' => $reussies, 'decision' => $complet ? ($reussies >= $p['passage_min'] ? 'PASSE' : 'REDOUBLE') : null] : null;
    $e['reussies'] = $reussies;
  }
  unset($e);

  $info = ligne("SELECT a.libelle AS annee, CONCAT(u.nom, ' ', u.prenoms) AS titulaire FROM classes c JOIN annees_scolaires a ON a.id = c.annee_id
                 LEFT JOIN utilisateurs u ON u.id = c.prof_principal_id WHERE c.id = ?", [$c['id']]);
  $periodes = lignes('SELECT id, numero, libelle, statut FROM periodes WHERE ecole_id = ? AND annee_id = ? ORDER BY numero', [$c['ecole_id'], $c['annee_id']]);
  repondre(['classe' => ['id' => (int)$c['id'], 'nom' => $c['nom'], 'niveau' => $c['niveau']] + $info,
            'ecole' => entete_ecole((int)$c['ecole_id']), 'parametres' => $p, 'periodes' => $periodes,
            'evaluations_validees' => array_keys($valides), 'matieres' => $matieres, 'eleves' => $eleves]);
}

// Règles du primaire de l'école (lecture et réglage par la direction)
function r_prim_parametres() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  global $UTILISATEUR;
  $ecole = est_super_admin() ? entier('ecole_id') : (int)$UTILISATEUR['ecole_id'];
  verifier_ecole($ecole);
  repondre(params_primaire($ecole));
}

function r_prim_parametres_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  global $UTILISATEUR;
  $ecole = est_super_admin() ? entier('ecole_id') : (int)$UTILISATEUR['ecole_id'];
  verifier_ecole($ecole);
  $avant = params_primaire($ecole);
  $note = note_valide(champ('note_reussite'));
  $num = entier('seuil_num'); $den = entier('seuil_den');
  if ($num < 1 || $den < 1 || $num > $den) erreur('Seuil : fraction invalide (par exemple 2 sur 3).');
  $rep = array_map('intval', array_filter(explode(',', str_replace(' ', '', (string)champ('repartition'))), 'strlen'));
  if (!$rep || count($rep) > 3 || min($rep) < 1 || array_sum($rep) > 9) erreur('Répartition invalide (par exemple 2,2,1).');
  $passage = entier('passage_min');
  if ($passage < 1 || $passage > array_sum($rep)) erreur("Évaluations réussies pour passer : entre 1 et " . array_sum($rep) . '.');
  requete('UPDATE parametres_primaire SET note_reussite = ?, seuil_num = ?, seuil_den = ?, repartition = ?, passage_min = ?, a_confirmer = 0 WHERE ecole_id = ?',
          [$note, $num, $den, implode(',', $rep), $passage, $ecole]);
  journaliser('MODIFICATION', 'parametres_primaire', $ecole, 'Règles du primaire', $avant, params_primaire($ecole));
  repondre();
}

// ---------------------------------------------------------------------
// MATERNELLE : grille d'observation d'un trimestre pour toute la classe
// ---------------------------------------------------------------------
function r_mat_grille() {
  $c = classe_cycle(entier('classe_id'), 'MATERNELLE');
  verifier_consultation_classe($c);
  $per = periode_trimestre($c, entier('trimestre'));
  $eleves = lignes("SELECT i.id AS inscription_id, el.matricule, el.nom, el.prenoms, el.sexe, el.date_naissance, el.lieu_naissance,
                           g.valeurs, g.observation
                    FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                    LEFT JOIN maternelle_grilles g ON g.inscription_id = i.id AND g.periode_id = ?
                    WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY el.nom, el.prenoms", [$per['id'], $c['id']]);
  $n = count(DOMAINES_MATERNELLE);
  foreach ($eleves as &$e) $e['valeurs'] = array_slice(array_pad($e['valeurs'] === null ? [] : explode(',', $e['valeurs']), $n, ''), 0, $n);
  unset($e);
  $info = ligne("SELECT a.libelle AS annee, CONCAT(u.nom, ' ', u.prenoms) AS titulaire FROM classes c JOIN annees_scolaires a ON a.id = c.annee_id
                 LEFT JOIN utilisateurs u ON u.id = c.prof_principal_id WHERE c.id = ?", [$c['id']]);
  $periodes = lignes('SELECT id, numero, libelle, statut FROM periodes WHERE ecole_id = ? AND annee_id = ? ORDER BY numero', [$c['ecole_id'], $c['annee_id']]);
  global $UTILISATEUR;
  $modifiable = $per['statut'] === 'OUVERTE' && (in_array($UTILISATEUR['role'], ROLES_NOTES, true)
                || ($UTILISATEUR['role'] === 'PROFESSEUR' && (int)$c['prof_principal_id'] === (int)$UTILISATEUR['id']));
  repondre(['classe' => ['id' => (int)$c['id'], 'nom' => $c['nom']] + $info, 'ecole' => entete_ecole((int)$c['ecole_id']),
            'periode' => $per, 'periodes' => $periodes, 'domaines' => DOMAINES_MATERNELLE, 'modifiable' => $modifiable, 'eleves' => $eleves]);
}

// grilles = [ {inscription_id, valeurs: ["TS","S",…], observation} ]
function r_mat_enregistrer() {
  global $UTILISATEUR;
  $c = classe_cycle(entier('classe_id'), 'MATERNELLE');
  verifier_droit_primaire($c);
  $per = periode_trimestre($c, entier('trimestre'));
  verifier_periode_ouverte($per);
  $grilles = champ('grilles');
  if (!is_array($grilles) || !$grilles) erreur('Aucune grille reçue.');
  $inscrits = array_flip(array_map('intval', array_column(lignes("SELECT id FROM inscriptions WHERE classe_id = ? AND statut = 'ACTIF'", [$c['id']]), 'id')));
  $n = count(DOMAINES_MATERNELLE);
  bd()->beginTransaction();
  foreach ($grilles as $g) {
    $insc = (int)($g['inscription_id'] ?? 0);
    if (!isset($inscrits[$insc])) { bd()->rollBack(); erreur("Élève $insc absent de cette classe."); }
    $v = array_slice(array_pad(is_array($g['valeurs'] ?? null) ? $g['valeurs'] : [], $n, ''), 0, $n);
    foreach ($v as $x) if (!in_array($x, ['', 'TS', 'S', 'PS'], true)) { bd()->rollBack(); erreur("Appréciation invalide : « $x »."); }
    $obs = trim((string)($g['observation'] ?? ''));
    if (mb_strlen($obs) > 500) { bd()->rollBack(); erreur('Observation trop longue (500 caractères au plus).'); }
    requete('INSERT INTO maternelle_grilles (inscription_id, periode_id, valeurs, observation, saisie_par) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE valeurs = VALUES(valeurs), observation = VALUES(observation), modifie_par = ?, modifie_le = NOW()',
            [$insc, $per['id'], implode(',', $v), $obs === '' ? null : $obs, $UTILISATEUR['id'], $UTILISATEUR['id']]);
  }
  bd()->commit();
  journaliser('SAISIE_NOTES', 'classes', $c['id'], "{$c['nom']} / {$per['libelle']} : " . count($grilles) . " grille(s) d'observation enregistrée(s)");
  repondre();
}
