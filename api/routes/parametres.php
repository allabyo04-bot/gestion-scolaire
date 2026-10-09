<?php
// =====================================================================
//  PARAMÉTRAGE : écoles, années, périodes, classes, matières, coefficients
// =====================================================================
function ecole_cible(): int {
  global $UTILISATEUR;
  if (!est_super_admin()) return (int)$UTILISATEUR['ecole_id'];
  $e = entier('ecole_id', false);
  if (!$e) erreur('Choisissez une école.');
  if (!ligne('SELECT id FROM ecoles WHERE id = ?', [$e])) erreur('École introuvable.', 404);
  return $e;
}
function annee_en_cours(): array {
  $a = ligne('SELECT * FROM annees_scolaires WHERE en_cours = 1');
  if (!$a) erreur("Aucune année scolaire n'est ouverte. L'administrateur général doit en créer une.", 409);
  return $a;
}
function texte_ou_null(string $nom): ?string { $v = champ($nom, false); return $v === null ? null : (string)$v; }

// ---------------------------------------------------------------- Écoles
const CHAMPS_ECOLE = ['nom_officiel','sigle','ville','adresse','boite_postale','telephone','email','site_web',
                      'entete_ligne1','entete_ligne2','entete_ligne3','devise','nom_directrice','titre_signataire'];

function r_param_ecole() {
  $e = ligne('SELECT e.*, g.nom AS groupe FROM ecoles e LEFT JOIN groupes g ON g.id = e.groupe_id WHERE e.id = ?', [ecole_cible()]);
  repondre($e);
}

// Super admin : crée ou modifie toute école. Directrice : modifie la fiche de SON école.
function r_param_ecole_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $id = entier('id', false);
  if (!$id && !est_super_admin()) erreur("Seul l'administrateur général peut créer une école.", 403);
  if ($id) verifier_ecole($id);
  $v = [];
  foreach (CHAMPS_ECOLE as $c) $v[$c] = texte_ou_null($c);
  if (!$v['nom_officiel'] || !$v['ville']) erreur('Le nom officiel et la ville sont obligatoires.');
  $v['titre_signataire'] ??= 'La Directrice';
  $groupe = est_super_admin() ? entier('groupe_id', false) : null;
  if ($groupe && !ligne('SELECT id FROM groupes WHERE id = ?', [$groupe])) erreur('Groupe introuvable.', 404);
  if ($id) {
    if ($groupe) requete('UPDATE ecoles SET groupe_id = ? WHERE id = ?', [$groupe, $id]);
    $avant = ligne('SELECT ' . implode(',', CHAMPS_ECOLE) . ' FROM ecoles WHERE id = ?', [$id]);
    requete('UPDATE ecoles SET ' . implode(' = ?, ', CHAMPS_ECOLE) . ' = ? WHERE id = ?', [...array_values($v), $id]);
    journaliser('MODIFICATION', 'ecoles', $id, $v['nom_officiel'], $avant, $v);
  } else {
    $code = strtoupper((string)champ('code'));
    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) erreur('Code école : 2 à 20 lettres majuscules ou chiffres (ex. KANDI).');
    if (ligne('SELECT id FROM ecoles WHERE code = ?', [$code])) erreur('Ce code école existe déjà.');
    if (!$groupe) erreur('Choisissez le groupe de la nouvelle école.');
    requete('INSERT INTO ecoles (groupe_id, code, ' . implode(',', CHAMPS_ECOLE) . ') VALUES (?,?' . str_repeat(',?', count(CHAMPS_ECOLE)) . ')',
            [$groupe, $code, ...array_values($v)]);
    $id = (int)bd()->lastInsertId();
    journaliser('CREATION', 'ecoles', $id, "École créée : {$v['nom_officiel']}");
  }
  repondre(['id' => $id]);
}

// ---------------------------------------------------------------- Années scolaires (réseau)
function r_param_annees() { repondre(lignes('SELECT * FROM annees_scolaires ORDER BY date_debut DESC')); }

function r_param_annee_creer() {
  exiger_role('SUPER_ADMIN');
  $libelle = (string)champ('libelle');
  if (!preg_match('/^\d{4}-\d{4}$/', $libelle)) erreur('Libellé attendu : 2026-2027.');
  [$a, $b] = explode('-', $libelle);
  if ((int)$b !== (int)$a + 1) erreur('Les deux années doivent se suivre (ex. 2026-2027).');
  if (ligne('SELECT id FROM annees_scolaires WHERE libelle = ?', [$libelle])) erreur('Cette année existe déjà.');
  requete('INSERT INTO annees_scolaires (libelle, date_debut, date_fin) VALUES (?,?,?)',
          [$libelle, champ('date_debut', false) ?? "$a-09-15", champ('date_fin', false) ?? "$b-07-15"]);
  $id = (int)bd()->lastInsertId();
  journaliser('CREATION', 'annees_scolaires', $id, "Année $libelle créée");
  repondre(['id' => $id], 201);
}

// Une seule année « en cours » pour tout le réseau
function r_param_annee_activer() {
  exiger_role('SUPER_ADMIN');
  $a = ligne('SELECT * FROM annees_scolaires WHERE id = ?', [entier('id')]);
  if (!$a) erreur('Année introuvable.', 404);
  bd()->beginTransaction();
  requete('UPDATE annees_scolaires SET en_cours = 0');
  requete('UPDATE annees_scolaires SET en_cours = 1 WHERE id = ?', [$a['id']]);
  bd()->commit();
  journaliser('MODIFICATION', 'annees_scolaires', $a['id'], "Année {$a['libelle']} devient l'année en cours");
  repondre();
}

// ---------------------------------------------------------------- Périodes et paramètres de calcul
function r_param_periodes() {
  $e = ecole_cible(); $a = annee_en_cours();
  repondre([
    'annee' => $a,
    'parametres' => ligne('SELECT * FROM parametres_annee WHERE ecole_id = ? AND annee_id = ?', [$e, $a['id']]),
    'periodes' => lignes('SELECT * FROM periodes WHERE ecole_id = ? AND annee_id = ? ORDER BY numero', [$e, $a['id']]),
  ]);
}

// Crée le découpage de l'année (3 trimestres ou 2 semestres) pour l'école
function r_param_periodes_creer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible(); $a = annee_en_cours();
  if (ligne('SELECT id FROM periodes WHERE ecole_id = ? AND annee_id = ?', [$e, $a['id']]))
    erreur('Le découpage de cette année existe déjà.', 409);
  $type = strtoupper((string)champ('type_periode'));
  if (!in_array($type, ['TRIMESTRE', 'SEMESTRE'], true)) erreur('Découpage : TRIMESTRE ou SEMESTRE.');
  $nb = $type === 'TRIMESTRE' ? 3 : 2;
  bd()->beginTransaction();
  requete('INSERT INTO parametres_annee (ecole_id, annee_id, type_periode) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE type_periode = VALUES(type_periode)', [$e, $a['id'], $type]);
  for ($i = 1; $i <= $nb; $i++) {
    $lib = ($i === 1 ? '1er' : "{$i}e") . ($type === 'TRIMESTRE' ? ' trimestre' : ' semestre');
    requete('INSERT INTO periodes (ecole_id, annee_id, numero, libelle) VALUES (?,?,?,?)', [$e, $a['id'], $i, $lib]);
  }
  bd()->commit();
  journaliser('CREATION', 'periodes', null, "{$a['libelle']} : $nb périodes ($type)");
  repondre(null, 201);
}

function r_param_parametres_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible(); $a = annee_en_cours();
  $avant = ligne('SELECT * FROM parametres_annee WHERE ecole_id = ? AND annee_id = ?', [$e, $a['id']]);
  if (!$avant) erreur("Créez d'abord le découpage de l'année.", 409);
  $mode = strtoupper((string)champ('mode_moy_annuelle'));
  if (!in_array($mode, ['SIMPLE', 'PONDEREE'], true)) erreur('Mode : SIMPLE ou PONDEREE.');
  $passage = note_valide(champ('moyenne_passage'));
  $hm = (float)str_replace(',', '.', (string)(champ('heures_matin', false) ?? $avant['heures_matin']));
  $ha = (float)str_replace(',', '.', (string)(champ('heures_apres_midi', false) ?? $avant['heures_apres_midi']));
  if ($hm <= 0 || $hm > 8 || $ha <= 0 || $ha > 8) erreur("Durée d'une demi-journée : entre 0,5 et 8 heures.");
  bd()->beginTransaction();
  requete('UPDATE parametres_annee SET mode_moy_annuelle = ?, moyenne_passage = ?, heures_matin = ?, heures_apres_midi = ? WHERE id = ?',
          [$mode, $passage, $hm, $ha, $avant['id']]);
  foreach ((array)(champ('poids', false) ?? []) as $pid => $poids) {
    $p = str_replace(',', '.', (string)$poids);
    if (!is_numeric($p) || $p <= 0 || $p > 10) { bd()->rollBack(); erreur('Poids de période invalide.'); }
    requete('UPDATE periodes SET poids = ? WHERE id = ? AND ecole_id = ? AND annee_id = ?', [$p, (int)$pid, $e, $a['id']]);
  }
  bd()->commit();
  journaliser('MODIFICATION', 'parametres_annee', $avant['id'], 'Paramètres de calcul',
              ['mode' => $avant['mode_moy_annuelle'], 'passage' => $avant['moyenne_passage']], ['mode' => $mode, 'passage' => $passage]);
  repondre();
}

function r_param_periode_dates() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $p = periode(entier('id'));
  $d = (string)champ('date_debut'); $f = (string)champ('date_fin');
  foreach ([$d, $f] as $x) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) erreur('Date invalide.');
  if ($f <= $d) erreur('La date de fin doit être après la date de début.');
  if (ligne('SELECT id FROM periodes WHERE ecole_id = ? AND annee_id = ? AND id <> ? AND date_debut <= ? AND date_fin >= ?',
            [$p['ecole_id'], $p['annee_id'], $p['id'], $f, $d])) erreur('Ces dates chevauchent une autre période.');
  requete('UPDATE periodes SET date_debut = ?, date_fin = ? WHERE id = ?', [$d, $f, $p['id']]);
  journaliser('MODIFICATION', 'periodes', $p['id'], "{$p['libelle']} : du $d au $f", ['du' => $p['date_debut'], 'au' => $p['date_fin']], ['du' => $d, 'au' => $f]);
  repondre();
}

function r_param_periode_statut() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $p = periode(entier('id'));
  $statut = strtoupper((string)champ('statut'));
  if (!in_array($statut, ['OUVERTE', 'CLOTUREE'], true)) erreur('Statut : OUVERTE ou CLOTUREE.');
  requete('UPDATE periodes SET statut = ? WHERE id = ?', [$statut, $p['id']]);
  journaliser($statut === 'CLOTUREE' ? 'CLOTURE_PERIODE' : 'REOUVERTURE_PERIODE', 'periodes', $p['id'], $p['libelle'],
              ['statut' => $p['statut']], ['statut' => $statut]);
  repondre();
}

// ---------------------------------------------------------------- Nombre d'interros / devoirs par défaut
function r_param_config_eval() {
  $e = ecole_cible();
  repondre(lignes('SELECT c.*, n.libelle AS niveau FROM config_evaluation_defaut c LEFT JOIN niveaux n ON n.id = c.niveau_id
                   WHERE c.ecole_id = ? ORDER BY n.ordre IS NOT NULL, n.ordre', [$e]));
}

function r_param_config_eval_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible();
  $niveau = entier('niveau_id', false);
  $ni = entier('nb_interros'); $nd = entier('nb_devoirs');
  if ($ni > 10 || $nd > 5) erreur('Maximum 10 interros et 5 devoirs par période.');
  if ($ni + $nd === 0) erreur('Il faut au moins une interro ou un devoir.');
  // UNIQUE ne protège pas les NULL : on gère la ligne « tous niveaux » à la main
  $exist = $niveau ? ligne('SELECT id FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id = ?', [$e, $niveau])
                   : ligne('SELECT id FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id IS NULL', [$e]);
  if ($exist) requete('UPDATE config_evaluation_defaut SET nb_interros = ?, nb_devoirs = ? WHERE id = ?', [$ni, $nd, $exist['id']]);
  else requete('INSERT INTO config_evaluation_defaut (ecole_id, niveau_id, nb_interros, nb_devoirs) VALUES (?,?,?,?)', [$e, $niveau, $ni, $nd]);
  journaliser('MODIFICATION', 'config_evaluation_defaut', $exist['id'] ?? (int)bd()->lastInsertId(),
              ($niveau ? "Niveau $niveau" : 'Tous niveaux') . " : $ni interro(s), $nd devoir(s)");
  repondre();
}

function r_param_config_eval_supprimer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $c = ligne('SELECT * FROM config_evaluation_defaut WHERE id = ?', [entier('id')]);
  if (!$c) erreur('Paramétrage introuvable.', 404);
  verifier_ecole((int)$c['ecole_id']);
  if ($c['niveau_id'] === null) erreur('La valeur « tous niveaux » ne peut pas être supprimée, seulement modifiée.');
  requete('DELETE FROM config_evaluation_defaut WHERE id = ?', [$c['id']]);
  journaliser('SUPPRESSION', 'config_evaluation_defaut', $c['id'], "Exception du niveau {$c['niveau_id']} supprimée");
  repondre();
}

// ---------------------------------------------------------------- Référentiels
function r_param_niveaux() { repondre(lignes('SELECT * FROM niveaux ORDER BY ordre')); }
function r_param_series()  { repondre(lignes('SELECT * FROM series ORDER BY code')); }
function r_param_matieres() { repondre(lignes('SELECT * FROM matieres ORDER BY libelle')); }

function r_param_matiere_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $id = entier('id', false);
  $libelle = (string)champ('libelle');
  $court = texte_ou_null('libelle_court');
  $conduite = (int)(bool)champ('est_conduite', false);
  $doublon = ligne('SELECT id FROM matieres WHERE libelle = ? AND id <> ?', [$libelle, $id ?? 0]);
  if ($doublon) erreur('Une matière porte déjà ce nom.');
  if ($id) {
    requete('UPDATE matieres SET libelle = ?, libelle_court = ?, est_conduite = ? WHERE id = ?', [$libelle, $court, $conduite, $id]);
    journaliser('MODIFICATION', 'matieres', $id, $libelle);
  } else {
    $base = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $court ?: $libelle)));
    $code = substr($base ?: 'MAT', 0, 12); $n = 1; $essai = $code;
    while (ligne('SELECT id FROM matieres WHERE code = ?', [$essai])) $essai = $code . (++$n);
    requete('INSERT INTO matieres (code, libelle, libelle_court, est_conduite) VALUES (?,?,?,?)', [$essai, $libelle, $court, $conduite]);
    $id = (int)bd()->lastInsertId();
    journaliser('CREATION', 'matieres', $id, "Matière créée : $libelle");
  }
  repondre(['id' => $id]);
}

function r_param_professeurs() {
  $e = ecole_cible();
  repondre(lignes("SELECT id, nom, prenoms, role FROM utilisateurs WHERE ecole_id = ? AND role IN ('PROFESSEUR','DIRECTRICE') AND actif = 1 ORDER BY role = 'DIRECTRICE', nom, prenoms", [$e]));
}

// ---------------------------------------------------------------- Classes
function r_param_classes() {
  $e = ecole_cible(); $a = annee_en_cours();
  repondre(lignes("SELECT c.*, n.libelle AS niveau, n.cycle, s.code AS serie, CONCAT(u.nom, ' ', u.prenoms) AS prof_principal,
                     (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = 'ACTIF') AS effectif,
                     (SELECT COUNT(*) FROM classe_matieres cm WHERE cm.classe_id = c.id AND cm.actif = 1) AS nb_matieres
                   FROM classes c JOIN niveaux n ON n.id = c.niveau_id LEFT JOIN series s ON s.id = c.serie_id
                   LEFT JOIN utilisateurs u ON u.id = c.prof_principal_id
                   WHERE c.ecole_id = ? AND c.annee_id = ? ORDER BY n.ordre, c.nom", [$e, $a['id']]));
}

function verifier_prof(?int $prof, int $ecole): void {
  // Un membre de la direction peut aussi enseigner (ex. directrice qui tient une classe)
  if ($prof && !ligne("SELECT id FROM utilisateurs WHERE id = ? AND ecole_id = ? AND role IN ('PROFESSEUR','DIRECTRICE')", [$prof, $ecole]))
    erreur("Ce professeur n'appartient pas à l'école.");
}

function r_param_classe_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $id = entier('id', false);
  $nom = trim((string)champ('nom'));
  $niveau = entier('niveau_id'); $serie = entier('serie_id', false); $pp = entier('prof_principal_id', false);
  $max = entier('effectif_max', false);
  $n = ligne('SELECT * FROM niveaux WHERE id = ?', [$niveau]);
  if (!$n) erreur('Niveau inconnu.');
  if ($serie && !in_array($n['code'], ['2NDE', '1ERE', 'TLE'], true)) erreur('Les séries ne concernent que le second cycle.');
  if ($id) {
    $c = classe($id);
    verifier_prof($pp, (int)$c['ecole_id']);
    if (ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ? AND id <> ?', [$c['ecole_id'], $c['annee_id'], $nom, $id]))
      erreur('Une autre classe porte déjà ce nom.');
    requete('UPDATE classes SET nom = ?, niveau_id = ?, serie_id = ?, prof_principal_id = ?, effectif_max = ? WHERE id = ?',
            [$nom, $niveau, $serie, $pp, $max, $id]);
    journaliser('MODIFICATION', 'classes', $id, $nom, ['nom' => $c['nom'], 'prof_principal_id' => $c['prof_principal_id']],
                ['nom' => $nom, 'prof_principal_id' => $pp]);
  } else {
    $e = ecole_cible(); $a = annee_en_cours();
    verifier_prof($pp, $e);
    if (ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$e, $a['id'], $nom]))
      erreur('Une classe porte déjà ce nom.');
    requete('INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom, prof_principal_id, effectif_max) VALUES (?,?,?,?,?,?,?)',
            [$e, $a['id'], $niveau, $serie, $nom, $pp, $max]);
    $id = (int)bd()->lastInsertId();
    journaliser('CREATION', 'classes', $id, "Classe créée : $nom");
  }
  repondre(['id' => $id]);
}

function r_param_classe_supprimer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $c = classe(entier('id'));
  if (ligne('SELECT id FROM inscriptions WHERE classe_id = ? LIMIT 1', [$c['id']])) erreur('Impossible : des élèves sont inscrits dans cette classe.', 409);
  if (ligne('SELECT e.id FROM evaluations e JOIN classe_matieres cm ON cm.id = e.classe_matiere_id WHERE cm.classe_id = ? LIMIT 1', [$c['id']]))
    erreur('Impossible : des évaluations existent pour cette classe.', 409);
  bd()->beginTransaction();
  requete('DELETE FROM classe_matieres WHERE classe_id = ?', [$c['id']]);
  requete('DELETE FROM classes WHERE id = ?', [$c['id']]);
  bd()->commit();
  journaliser('SUPPRESSION', 'classes', $c['id'], "Classe supprimée : {$c['nom']}");
  repondre();
}

// ---------------------------------------------------------------- Matières d'une classe
function r_param_classe_matieres() {
  $c = classe(entier('classe_id'));
  repondre(lignes("SELECT cm.*, m.libelle, m.est_conduite, CONCAT(u.nom, ' ', u.prenoms) AS professeur,
                     (SELECT COUNT(*) FROM evaluations e WHERE e.classe_matiere_id = cm.id) AS nb_evaluations
                   FROM classe_matieres cm JOIN matieres m ON m.id = cm.matiere_id LEFT JOIN utilisateurs u ON u.id = cm.professeur_id
                   WHERE cm.classe_id = ? AND cm.actif = 1 ORDER BY cm.ordre_affichage, m.libelle", [$c['id']]));
}

function r_param_classe_matiere_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $c = classe(entier('classe_id'));
  $matiere = entier('matiere_id');
  if (!ligne('SELECT id FROM matieres WHERE id = ?', [$matiere])) erreur('Matière inconnue.');
  $coef = str_replace(',', '.', (string)champ('coefficient'));
  if (!is_numeric($coef) || $coef <= 0 || $coef > 20) erreur('Coefficient invalide (entre 0,5 et 20).');
  $prof = entier('professeur_id', false);
  // Maternelle et primaire : sans choix explicite, la matière est confiée au titulaire de la classe
  if (!$prof && in_array($c['cycle'], ['MATERNELLE', 'PRIMAIRE'], true) && $c['prof_principal_id']) $prof = (int)$c['prof_principal_id'];
  elseif ($prof) verifier_prof($prof, (int)$c['ecole_id']);
  $groupe = texte_ou_null('groupe_bulletin');
  $avant = ligne('SELECT * FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $matiere]);
  if ($avant) {
    requete('UPDATE classe_matieres SET coefficient = ?, coef_a_confirmer = 0, professeur_id = ?, groupe_bulletin = ?, actif = 1 WHERE id = ?',
            [$coef, $prof, $groupe, $avant['id']]);
    journaliser('MODIFICATION', 'classe_matieres', $avant['id'], "{$c['nom']} : matière $matiere",
                ['coefficient' => $avant['coefficient'], 'professeur_id' => $avant['professeur_id']], ['coefficient' => $coef, 'professeur_id' => $prof]);
    $id = (int)$avant['id'];
  } else {
    $ordre = (int)ligne('SELECT COALESCE(MAX(ordre_affichage),0) + 1 AS o FROM classe_matieres WHERE classe_id = ?', [$c['id']])['o'];
    requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, professeur_id, groupe_bulletin, ordre_affichage) VALUES (?,?,?,?,?,?)',
            [$c['id'], $matiere, $coef, $prof, $groupe, $ordre]);
    $id = (int)bd()->lastInsertId();
    journaliser('CREATION', 'classe_matieres', $id, "{$c['nom']} : matière $matiere ajoutée, coef. $coef");
  }
  repondre(['id' => $id]);
}

function r_param_classe_matiere_retirer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $cm = classe_matiere(entier('id'));
  if (ligne('SELECT id FROM evaluations WHERE classe_matiere_id = ? LIMIT 1', [$cm['id']])) {
    requete('UPDATE classe_matieres SET actif = 0 WHERE id = ?', [$cm['id']]);   // on garde l'historique des notes
  } else {
    requete('DELETE FROM classe_matieres WHERE id = ?', [$cm['id']]);
  }
  journaliser('SUPPRESSION', 'classe_matieres', $cm['id'], "{$cm['classe_nom']} : {$cm['matiere']} retirée");
  repondre();
}

function r_param_classe_matieres_ordre() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $c = classe(entier('classe_id'));
  $ids = (array)champ('ordre');
  foreach (array_values($ids) as $i => $id) requete('UPDATE classe_matieres SET ordre_affichage = ? WHERE id = ? AND classe_id = ?', [$i + 1, (int)$id, $c['id']]);
  repondre();
}

// Copie les matières, coefficients et professeurs d'une classe vers une autre (gain de temps : 6e A → 6e B)
function r_param_classe_copier_matieres() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $src = classe(entier('source_id')); $dst = classe(entier('classe_id'));
  if ((int)$src['ecole_id'] !== (int)$dst['ecole_id']) erreur('Les deux classes doivent être de la même école.');
  $avec_profs = (bool)champ('avec_professeurs', false);
  $n = 0;
  bd()->beginTransaction();
  foreach (lignes('SELECT * FROM classe_matieres WHERE classe_id = ? AND actif = 1', [$src['id']]) as $m) {
    $st = requete('INSERT IGNORE INTO classe_matieres (classe_id, matiere_id, coefficient, professeur_id, groupe_bulletin, ordre_affichage)
                   VALUES (?,?,?,?,?,?)', [$dst['id'], $m['matiere_id'], $m['coefficient'], $avec_profs ? $m['professeur_id'] : null,
                   $m['groupe_bulletin'], $m['ordre_affichage']]);
    $n += $st->rowCount();
  }
  bd()->commit();
  journaliser('CREATION', 'classe_matieres', null, "{$n} matière(s) copiée(s) de {$src['nom']} vers {$dst['nom']}");
  repondre(['copiees' => $n]);
}

// ---------------------------------------------------------------- Logo, cachet, signature
const TYPES_IMAGES = ['LOGO', 'LOGO_FONDATION', 'CACHET', 'SIGNATURE'];
function r_param_images() {
  $e = ecole_cible();
  repondre(array_column(lignes('SELECT type, donnees FROM ecole_images WHERE ecole_id = ?', [$e]), 'donnees', 'type'));
}
function r_param_image_enregistrer() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible();
  $type = strtoupper((string)champ('type'));
  if (!in_array($type, TYPES_IMAGES, true)) erreur('Type d\'image invalide.');
  $d = (string)champ('donnees');
  if (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $d)) erreur('Image invalide (PNG, JPEG ou WebP attendu).');
  if (strlen($d) > 600000) erreur('Image trop lourde : choisissez une image plus petite.');
  requete('INSERT INTO ecole_images (ecole_id, type, donnees, modifie_par) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE donnees = VALUES(donnees), modifie_par = VALUES(modifie_par)',
          [$e, $type, $d, $UTILISATEUR['id']]);
  journaliser('MODIFICATION', 'ecole_images', $e, strtolower($type) . ' de l\'école mis à jour');
  repondre();
}
function r_param_image_supprimer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible();
  requete('DELETE FROM ecole_images WHERE ecole_id = ? AND type = ?', [$e, strtoupper((string)champ('type'))]);
  journaliser('SUPPRESSION', 'ecole_images', $e, strtolower((string)champ('type')) . ' de l\'école retiré');
  repondre();
}

// ---------------------------------------------------------------- Groupes d'écoles (administrateur général)
function r_param_groupe_enregistrer() {
  exiger_role('SUPER_ADMIN');
  $id = entier('id', false);
  $nom = trim((string)champ('nom'));
  if (mb_strlen($nom) < 3) erreur('Nom du groupe trop court.');
  $sigle = texte_ou_null('sigle');
  if ($id) {
    $avant = ligne('SELECT * FROM groupes WHERE id = ?', [$id]);
    if (!$avant) erreur('Groupe introuvable.', 404);
    requete('UPDATE groupes SET nom = ?, sigle = ? WHERE id = ?', [$nom, $sigle, $id]);
    journaliser('MODIFICATION', 'groupes', $id, "Groupe renommé : $nom", ['nom' => $avant['nom'], 'sigle' => $avant['sigle']], ['nom' => $nom, 'sigle' => $sigle]);
  } else {
    $code = strtoupper((string)champ('code'));
    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) erreur('Code du groupe : 2 à 20 lettres majuscules ou chiffres.');
    if (ligne('SELECT id FROM groupes WHERE code = ?', [$code])) erreur('Ce code de groupe existe déjà.');
    requete('INSERT INTO groupes (code, nom, sigle) VALUES (?,?,?)', [$code, $nom, $sigle]);
    $id = (int)bd()->lastInsertId();
    journaliser('CREATION', 'groupes', $id, "Groupe créé : $nom");
  }
  repondre(['id' => $id]);
}
