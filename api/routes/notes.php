<?php
// ---------------------------------------------------------------------
// Ce que l'utilisateur peut saisir : un prof voit SES matières/classes
// ---------------------------------------------------------------------
function r_notes_mes_affectations() {
  global $UTILISATEUR;
  $sql = 'SELECT cm.id AS classe_matiere_id, c.id AS classe_id, c.nom AS classe, m.libelle AS matiere, cm.coefficient,
                 c.ecole_id, ec.nom_officiel AS ecole, a.libelle AS annee, cm.professeur_id,
                 CONCAT(u.nom, " ", u.prenoms) AS professeur
          FROM classe_matieres cm JOIN classes c ON c.id = cm.classe_id JOIN matieres m ON m.id = cm.matiere_id
          JOIN ecoles ec ON ec.id = c.ecole_id
          JOIN annees_scolaires a ON a.id = c.annee_id LEFT JOIN utilisateurs u ON u.id = cm.professeur_id
          WHERE cm.actif = 1 AND a.en_cours = 1';
  if ($UTILISATEUR['role'] === 'PROFESSEUR')  $l = lignes("$sql AND cm.professeur_id = ? ORDER BY c.nom, m.libelle", [$UTILISATEUR['id']]);
  elseif (est_super_admin())                  $l = lignes("$sql ORDER BY ec.nom_officiel, c.nom, m.libelle");
  else                                         $l = lignes("$sql AND c.ecole_id = ? ORDER BY c.nom, m.libelle", [$UTILISATEUR['ecole_id']]);
  repondre($l);
}

// ---------------------------------------------------------------------
// Évaluations (interros / devoirs) d'une matière pour une période
// ---------------------------------------------------------------------
function r_notes_evaluations() {
  $cm = classe_matiere(entier('classe_matiere_id'));
  verifier_droit_saisie($cm);
  $p = periode(entier('periode_id'));
  repondre(lignes("SELECT e.*, (SELECT COUNT(*) FROM notes n WHERE n.evaluation_id = e.id) AS nb_notes,
                          (SELECT m.est_conduite FROM matieres m WHERE m.id = ?) AS est_conduite
                   FROM evaluations e WHERE e.classe_matiere_id = ? AND e.periode_id = ?
                   ORDER BY FIELD(e.type,'INTERRO','DTL','DEVOIR'), e.numero", [$cm['matiere_id'], $cm['id'], $p['id']]));
}

// Crée les évaluations d'une classe pour une période, d'après le paramétrage par défaut
// (niveau précis d'abord, sinon valeur de l'école). Ne touche pas à ce qui existe déjà.
function r_notes_generer_evaluations() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $c = classe(entier('classe_id'));
  $p = periode(entier('periode_id'));
  verifier_periode_ouverte($p);
  if ((int)$p['ecole_id'] !== (int)$c['ecole_id'] || (int)$p['annee_id'] !== (int)$c['annee_id'])
    erreur("Cette période n'appartient pas à l'école / l'année de la classe.");
  $cfg = ligne('SELECT nb_interros, nb_devoirs FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id = ?', [$c['ecole_id'], $c['niveau_id']])
      ?? ligne('SELECT nb_interros, nb_devoirs FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id IS NULL', [$c['ecole_id']]);
  if (!$cfg) erreur("Aucun paramétrage par défaut (nombre d'interros/devoirs) pour cette école.");

  $crees = 0;
  $ins = bd()->prepare('INSERT IGNORE INTO evaluations (classe_matiere_id, periode_id, type, numero) VALUES (?,?,?,?)');
  bd()->beginTransaction();
  foreach (lignes('SELECT cm.id, m.est_conduite FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id
                   WHERE cm.classe_id = ? AND cm.actif = 1', [$c['id']]) as $cm) {
    // Conduite : une seule note par période
    if ($cm['est_conduite']) { $ins->execute([$cm['id'], $p['id'], 'DEVOIR', 1]); $crees += $ins->rowCount(); continue; }
    for ($i = 1; $i <= $cfg['nb_interros']; $i++) { $ins->execute([$cm['id'], $p['id'], 'INTERRO', $i]); $crees += $ins->rowCount(); }
    for ($i = 1; $i <= $cfg['nb_devoirs'];  $i++) { $ins->execute([$cm['id'], $p['id'], 'DEVOIR',  $i]); $crees += $ins->rowCount(); }
  }
  bd()->commit();
  journaliser('GENERATION_EVALUATIONS', 'classes', $c['id'], "{$c['nom']} — {$p['libelle']} : $crees évaluation(s) créée(s)");
  repondre(['evaluations_creees' => $crees]);
}

// Ajoute une interro ou un devoir supplémentaire pour UNE matière (cas particulier)
function r_notes_ajouter_evaluation() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $cm = classe_matiere(entier('classe_matiere_id'));
  $p = periode(entier('periode_id'));
  verifier_periode_ouverte($p);
  $type = strtoupper((string)champ('type'));
  if (!in_array($type, ['INTERRO', 'DEVOIR', 'DTL'], true)) erreur('Type : INTERRO, DEVOIR ou DTL.');
  $num = (int)(ligne('SELECT COALESCE(MAX(numero),0) n FROM evaluations WHERE classe_matiere_id = ? AND periode_id = ? AND type = ?',
                     [$cm['id'], $p['id'], $type])['n']) + 1;
  requete('INSERT INTO evaluations (classe_matiere_id, periode_id, type, numero) VALUES (?,?,?,?)', [$cm['id'], $p['id'], $type, $num]);
  $id = (int)bd()->lastInsertId();
  journaliser('CREATION', 'evaluations', $id, "{$cm['classe_nom']} / {$cm['matiere']} : $type $num ajouté(e)");
  repondre(['id' => $id, 'numero' => $num], 201);
}

// Supprime une évaluation encore vide (erreur de paramétrage)
function r_notes_supprimer_evaluation() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = evaluation(entier('evaluation_id'));
  verifier_periode_ouverte($e['periode']);
  if (ligne('SELECT id FROM notes WHERE evaluation_id = ? LIMIT 1', [$e['id']])) erreur('Impossible : des notes sont déjà saisies pour cette évaluation.', 409);
  requete('DELETE FROM evaluations WHERE id = ?', [$e['id']]);
  journaliser('SUPPRESSION', 'evaluations', $e['id'], "{$e['cm']['classe_nom']} / {$e['cm']['matiere']} : {$e['type']} {$e['numero']}",
              ['type' => $e['type'], 'numero' => $e['numero']]);
  repondre();
}

// ---------------------------------------------------------------------
// Feuille de notes : tous les élèves de la classe + leur note
// ---------------------------------------------------------------------
function r_notes_feuille() {
  $e = evaluation(entier('evaluation_id'));
  verifier_droit_saisie($e['cm']);
  $eleves = lignes("SELECT i.id AS inscription_id, el.matricule, el.educmaster, el.nom, el.prenoms,
                           n.id AS note_id, n.valeur, n.statut
                    FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                    LEFT JOIN notes n ON n.inscription_id = i.id AND n.evaluation_id = ?
                    WHERE i.classe_id = ? AND i.statut = 'ACTIF' ORDER BY el.nom, el.prenoms",
                   [$e['id'], $e['cm']['classe_id']]);
  unset($e['cm']['professeur_id']);
  repondre(['evaluation' => $e, 'eleves' => $eleves]);
}

// Enregistre (ou complète) les notes d'une évaluation tant qu'elle est en BROUILLON.
// Format : notes = [ {inscription_id, valeur} | {inscription_id, statut:"ABSENT"} ]
function r_notes_enregistrer() {
  global $UTILISATEUR;
  $e = evaluation(entier('evaluation_id'));
  verifier_droit_saisie($e['cm']);
  verifier_periode_ouverte($e['periode']);
  if ($e['statut'] !== 'BROUILLON')
    erreur('Ces notes sont validées et verrouillées. Seule la direction peut les corriger.', 409);
  $notes = champ('notes');
  if (!is_array($notes) || !$notes) erreur('Aucune note reçue.');

  $inscrits = array_column(lignes("SELECT id FROM inscriptions WHERE classe_id = ? AND statut = 'ACTIF'", [$e['cm']['classe_id']]), 'id');
  $inscrits = array_flip(array_map('intval', $inscrits));
  $prof = $UTILISATEUR['role'] === 'PROFESSEUR';

  bd()->beginTransaction();
  $n = 0;
  foreach ($notes as $l) {
    $insc = (int)($l['inscription_id'] ?? 0);
    if (!isset($inscrits[$insc])) { bd()->rollBack(); erreur("Élève $insc absent de cette classe."); }
    $statut = strtoupper($l['statut'] ?? 'NOTE');
    $permis = $prof ? ['NOTE', 'ABSENT'] : ['NOTE', 'ABSENT', 'ABSENT_JUSTIFIE', 'ABSENT_NON_JUSTIFIE', 'DISPENSE'];
    if (!in_array($statut, $permis, true)) { bd()->rollBack(); erreur("Statut « $statut » non autorisé."); }
    if ($statut === 'NOTE' && (($l['valeur'] ?? '') === '' || $l['valeur'] === null)) continue; // case laissée vide
    $valeur = $statut === 'NOTE' ? note_valide($l['valeur']) : null;
    requete('INSERT INTO notes (evaluation_id, inscription_id, valeur, statut, saisie_par) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), statut = VALUES(statut), modifie_par = ?, modifie_le = NOW()',
            [$e['id'], $insc, $valeur, $statut, $UTILISATEUR['id'], $UTILISATEUR['id']]);
    $n++;
  }
  bd()->commit();
  journaliser('SAISIE_NOTES', 'evaluations', $e['id'],
              "{$e['cm']['classe_nom']} / {$e['cm']['matiere']} / {$e['type']} {$e['numero']} : $n note(s) enregistrée(s)");
  repondre(['notes_enregistrees' => $n]);
}

// Le prof valide : les notes deviennent définitives et verrouillées pour lui
function r_notes_valider() {
  global $UTILISATEUR;
  $e = evaluation(entier('evaluation_id'));
  verifier_droit_saisie($e['cm']);
  verifier_periode_ouverte($e['periode']);
  if ($e['statut'] === 'VALIDEE') erreur('Ces notes sont déjà validées.', 409);
  $manquants = lignes("SELECT el.nom, el.prenoms FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id
                       LEFT JOIN notes n ON n.inscription_id = i.id AND n.evaluation_id = ?
                       WHERE i.classe_id = ? AND i.statut = 'ACTIF' AND n.id IS NULL", [$e['id'], $e['cm']['classe_id']]);
  if ($manquants) {
    $noms = implode(', ', array_map(fn($m) => "{$m['nom']} {$m['prenoms']}", array_slice($manquants, 0, 5)));
    erreur(count($manquants) . " élève(s) sans note ni absence : $noms" . (count($manquants) > 5 ? '…' : '') . '.', 422);
  }
  requete("UPDATE evaluations SET statut = 'VALIDEE', validee_par = ?, validee_le = NOW() WHERE id = ?", [$UTILISATEUR['id'], $e['id']]);
  journaliser('VALIDATION', 'evaluations', $e['id'], "{$e['cm']['classe_nom']} / {$e['cm']['matiere']} / {$e['type']} {$e['numero']} validé(e)");
  repondre();
}

// Correction d'une note VALIDÉE : directrice ou super admin, motif obligatoire, historique conservé
function r_notes_corriger() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = evaluation(entier('evaluation_id'));
  verifier_periode_ouverte($e['periode']);
  if ($e['statut'] !== 'VALIDEE') erreur("Cette évaluation n'est pas encore validée : utilisez la saisie normale.", 409);
  $motif = (string)champ('motif');
  if (mb_strlen($motif) < 5) erreur('Le motif de correction est obligatoire (5 caractères minimum).');
  $insc = entier('inscription_id');
  $statut = strtoupper((string)(champ('statut', false) ?? 'NOTE'));
  if (!in_array($statut, ['NOTE', 'ABSENT', 'ABSENT_JUSTIFIE', 'ABSENT_NON_JUSTIFIE', 'DISPENSE'], true)) erreur('Statut invalide.');
  $valeur = $statut === 'NOTE' ? note_valide(champ('valeur')) : null;

  $avant = ligne('SELECT * FROM notes WHERE evaluation_id = ? AND inscription_id = ?', [$e['id'], $insc]);
  if (!$avant) erreur('Aucune note existante pour cet élève dans cette évaluation.', 404);
  if ($avant['statut'] === $statut && (float)$avant['valeur'] === (float)$valeur && ($avant['valeur'] === null) === ($valeur === null))
    erreur('La nouvelle valeur est identique à l\'ancienne.');

  bd()->beginTransaction();
  requete('UPDATE notes SET valeur = ?, statut = ?, modifie_par = ?, modifie_le = NOW() WHERE id = ?',
          [$valeur, $statut, $UTILISATEUR['id'], $avant['id']]);
  requete('INSERT INTO notes_historique (note_id, ancienne_valeur, ancien_statut, nouvelle_valeur, nouveau_statut, motif, modifie_par)
           VALUES (?,?,?,?,?,?,?)', [$avant['id'], $avant['valeur'], $avant['statut'], $valeur, $statut, $motif, $UTILISATEUR['id']]);
  bd()->commit();
  journaliser('CORRECTION_NOTE', 'notes', $avant['id'], "Motif : $motif",
              ['valeur' => $avant['valeur'], 'statut' => $avant['statut']], ['valeur' => $valeur, 'statut' => $statut]);
  repondre();
}

// La direction rouvre une évaluation validée pour que le prof puisse la reprendre
function r_notes_rouvrir() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = evaluation(entier('evaluation_id'));
  verifier_periode_ouverte($e['periode']);
  $motif = (string)champ('motif');
  if (mb_strlen($motif) < 5) erreur('Le motif est obligatoire (5 caractères minimum).');
  if ($e['statut'] !== 'VALIDEE') erreur("Cette évaluation n'est pas verrouillée.", 409);
  requete("UPDATE evaluations SET statut = 'BROUILLON', validee_par = NULL, validee_le = NULL WHERE id = ?", [$e['id']]);
  journaliser('REOUVERTURE', 'evaluations', $e['id'], "Motif : $motif", ['statut' => 'VALIDEE'], ['statut' => 'BROUILLON']);
  repondre();
}

function r_notes_historique() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = evaluation(entier('evaluation_id'));
  repondre(lignes('SELECT h.*, el.nom, el.prenoms, CONCAT(u.nom, " ", u.prenoms) AS auteur
                   FROM notes_historique h JOIN notes n ON n.id = h.note_id
                   JOIN inscriptions i ON i.id = n.inscription_id JOIN eleves el ON el.id = i.eleve_id
                   JOIN utilisateurs u ON u.id = h.modifie_par
                   WHERE n.evaluation_id = ? ORDER BY h.modifie_le DESC', [$e['id']]));
}
