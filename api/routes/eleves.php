<?php
// =====================================================================
//  ÉLÈVES, TUTEURS, INSCRIPTIONS  (secrétariat + direction)
// =====================================================================
const ROLES_ELEVES = ['SUPER_ADMIN', 'DIRECTRICE', 'SECRETARIAT'];

function educmaster_valide($v): ?string {
  if ($v === null || $v === '') return null;
  $v = preg_replace('/\s+/', '', (string)$v);
  if (!preg_match('/^[0-9]{12,13}$/', $v)) erreur("Le numéro Educmaster doit comporter 12 ou 13 chiffres (reçu : $v).");
  return $v;
}
// Le 1er chiffre du numéro Educmaster indique le sexe : 1 = garçon, 2 = fille
function avertissement_sexe(?string $educmaster, string $sexe): ?string {
  if (!$educmaster) return null;
  $attendu = $educmaster[0] === '1' ? 'M' : ($educmaster[0] === '2' ? 'F' : null);
  if ($attendu && $attendu !== $sexe)
    return "Attention : le numéro Educmaster commence par {$educmaster[0]}, ce qui correspond à " . ($attendu === 'M' ? 'un garçon' : 'une fille') . ". Vérifiez le sexe ou le numéro.";
  return null;
}
function date_valide($v): ?string {
  if (!$v) return null;
  $v = trim((string)$v);
  if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  $d = DateTime::createFromFormat('Y-m-d', (string)$v);
  if (!$d || $d->format('Y-m-d') !== $v) erreur('Date invalide.');
  if ($d > new DateTime() || (int)$d->format('Y') < 1990) erreur('Date de naissance invalide.');
  return $v;
}
function telephone_valide($v): string {
  $t = preg_replace('/[^0-9+]/', '', (string)$v);
  if (strlen(ltrim($t, '+')) < 8) erreur('Numéro de téléphone du tuteur invalide (8 chiffres minimum).');
  return $t;
}

// L'élève doit avoir (ou avoir eu) une inscription dans l'école de l'utilisateur
function eleve_accessible(int $id): array {
  global $UTILISATEUR;
  $e = ligne('SELECT * FROM eleves WHERE id = ?', [$id]);
  if (!$e) erreur('Élève introuvable.', 404);
  if (!est_super_admin() && !ligne('SELECT id FROM inscriptions WHERE eleve_id = ? AND ecole_id = ? LIMIT 1', [$id, $UTILISATEUR['ecole_id']]))
    erreur("Cet élève n'est pas inscrit dans votre école.", 403);
  return $e;
}

function r_eleves_liste() {
  exiger_role(...ROLES_ELEVES, ...['COMPTABLE']);
  $sql = "SELECT el.id, el.matricule, el.educmaster, el.nom, el.prenoms, el.sexe, el.date_naissance,
                 i.id AS inscription_id, i.statut, i.redoublant, c.id AS classe_id, c.nom AS classe,
                 (SELECT t.telephone FROM eleve_tuteurs et JOIN tuteurs t ON t.id = et.tuteur_id
                   WHERE et.eleve_id = el.id ORDER BY et.principal DESC LIMIT 1) AS telephone_tuteur
          FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id JOIN classes c ON c.id = i.classe_id
          JOIN annees_scolaires a ON a.id = i.annee_id AND a.en_cours = 1";
  if ($cid = entier('classe_id', false)) {
    $c = classe($cid);
    repondre(lignes("$sql WHERE i.classe_id = ? ORDER BY i.statut = 'ACTIF' DESC, el.nom, el.prenoms", [$c['id']]));
  }
  $e = ecole_cible();
  $q = trim((string)(champ('q', false) ?? ''));
  if (mb_strlen($q) < 2) erreur('Saisissez au moins 2 caractères pour rechercher.');
  $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
  repondre(lignes("$sql WHERE i.ecole_id = ? AND (el.nom LIKE ? OR el.prenoms LIKE ? OR CONCAT(el.nom,' ',el.prenoms) LIKE ?
                   OR el.matricule LIKE ? OR el.educmaster LIKE ?) ORDER BY el.nom, el.prenoms LIMIT 50",
                  [$e, $like, $like, $like, $like, $like]));
}

function r_eleves_fiche() {
  exiger_role(...ROLES_ELEVES);
  $e = eleve_accessible(entier('id'));
  $e['tuteurs'] = lignes('SELECT t.*, et.lien, et.principal FROM eleve_tuteurs et JOIN tuteurs t ON t.id = et.tuteur_id
                          WHERE et.eleve_id = ? ORDER BY et.principal DESC, t.nom', [$e['id']]);
  $e['inscriptions'] = lignes('SELECT i.*, c.nom AS classe, a.libelle AS annee, a.en_cours, ec.nom_officiel AS ecole
                               FROM inscriptions i JOIN classes c ON c.id = i.classe_id JOIN annees_scolaires a ON a.id = i.annee_id
                               JOIN ecoles ec ON ec.id = i.ecole_id WHERE i.eleve_id = ? ORDER BY a.date_debut DESC', [$e['id']]);
  repondre($e);
}

// Avant une inscription : l'élève existe-t-il déjà dans le réseau ? (transfert entre écoles, réinscription)
function r_eleves_chercher_educmaster() {
  exiger_role(...ROLES_ELEVES);
  $num = educmaster_valide(champ('educmaster'));
  $e = ligne('SELECT id, matricule, nom, prenoms, sexe, date_naissance FROM eleves WHERE educmaster = ?', [$num]);
  if ($e) $e['derniere_inscription'] = ligne('SELECT c.nom AS classe, a.libelle AS annee, ec.nom_officiel AS ecole, i.annee_id, a.en_cours
                     FROM inscriptions i JOIN classes c ON c.id = i.classe_id JOIN annees_scolaires a ON a.id = i.annee_id
                     JOIN ecoles ec ON ec.id = i.ecole_id WHERE i.eleve_id = ? ORDER BY a.date_debut DESC LIMIT 1', [$e['id']]);
  repondre($e);
}

function nouveau_matricule(int $ecole_id, array $annee): string {
  $code = ligne('SELECT code FROM ecoles WHERE id = ?', [$ecole_id])['code'];
  $prefixe = $code . '-' . substr($annee['libelle'], 2, 2) . '-';
  $dernier = ligne('SELECT matricule FROM eleves WHERE matricule LIKE ? ORDER BY matricule DESC LIMIT 1', [$prefixe . '%']);
  $n = $dernier ? (int)substr($dernier['matricule'], strlen($prefixe)) + 1 : 1;
  return $prefixe . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function lire_identite(): array {
  $sexe = strtoupper((string)champ('sexe'));
  if (!in_array($sexe, ['M', 'F'], true)) erreur('Sexe : M ou F.');
  return [
    'educmaster' => educmaster_valide(champ('educmaster', false)),
    'nom' => mb_strtoupper(trim((string)champ('nom'))),
    'prenoms' => trim(preg_replace('/\s+/', ' ', (string)champ('prenoms'))),
    'sexe' => $sexe,
    'date_naissance' => date_valide(champ('date_naissance', false)),
    'lieu_naissance' => texte_ou_null('lieu_naissance'),
    'nationalite' => texte_ou_null('nationalite') ?? 'Béninoise',
  ];
}

// Rattache un tuteur à l'élève ; un même téléphone = un même tuteur (frères et sœurs)
function lier_tuteur(int $eleve_id, array $t): void {
  $tel = telephone_valide($t['telephone'] ?? '');
  $nom = mb_strtoupper(trim((string)($t['nom'] ?? '')));
  if ($nom === '') erreur('Le nom du tuteur est obligatoire.');
  $lien = strtoupper((string)($t['lien'] ?? 'TUTEUR'));
  if (!in_array($lien, ['PERE', 'MERE', 'TUTEUR', 'AUTRE'], true)) $lien = 'TUTEUR';
  $exist = ligne('SELECT id FROM tuteurs WHERE telephone = ?', [$tel]);
  if ($exist) {
    $tid = (int)$exist['id'];
    requete('UPDATE tuteurs SET nom = ?, prenoms = COALESCE(?, prenoms), email = COALESCE(?, email), profession = COALESCE(?, profession) WHERE id = ?',
            [$nom, $t['prenoms'] ?: null, $t['email'] ?: null, $t['profession'] ?: null, $tid]);
  } else {
    requete('INSERT INTO tuteurs (nom, prenoms, telephone, email, profession) VALUES (?,?,?,?,?)',
            [$nom, $t['prenoms'] ?: null, $tel, $t['email'] ?: null, $t['profession'] ?: null]);
    $tid = (int)bd()->lastInsertId();
  }
  $principal = !empty($t['principal']) ? 1 : 0;
  if ($principal) requete('UPDATE eleve_tuteurs SET principal = 0 WHERE eleve_id = ?', [$eleve_id]);
  requete('INSERT INTO eleve_tuteurs (eleve_id, tuteur_id, lien, principal) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE lien = VALUES(lien), principal = VALUES(principal)', [$eleve_id, $tid, $lien, $principal]);
}

// Nouvelle inscription : élève nouveau OU élève déjà connu du réseau (eleve_id)
function r_eleves_inscrire() {
  global $UTILISATEUR;
  exiger_role(...ROLES_ELEVES);
  $c = classe(entier('classe_id'));
  $a = annee_en_cours();
  if ((int)$c['annee_id'] !== (int)$a['id']) erreur("Cette classe n'appartient pas à l'année en cours.");
  $eleve_id = entier('eleve_id', false);

  bd()->beginTransaction();
  if ($eleve_id) {
    $e = ligne('SELECT * FROM eleves WHERE id = ?', [$eleve_id]);
    if (!$e) { bd()->rollBack(); erreur('Élève introuvable.', 404); }
  } else {
    $v = lire_identite();
    if ($v['educmaster'] && ligne('SELECT id FROM eleves WHERE educmaster = ?', [$v['educmaster']])) {
      bd()->rollBack(); erreur('Ce numéro Educmaster est déjà enregistré. Recherchez l\'élève pour le réinscrire.', 409);
    }
    $sim = ligne('SELECT id FROM eleves WHERE nom = ? AND prenoms = ? AND date_naissance <=> ?', [$v['nom'], $v['prenoms'], $v['date_naissance']]);
    if ($sim && !champ('confirmer_homonyme', false)) {
      bd()->rollBack(); erreur("Un élève {$v['nom']} {$v['prenoms']} né le même jour existe déjà. Vérifiez qu'il ne s'agit pas du même élève.", 409);
    }
    $v['matricule'] = nouveau_matricule((int)$c['ecole_id'], $a);
    requete('INSERT INTO eleves (educmaster, nom, prenoms, sexe, date_naissance, lieu_naissance, nationalite, matricule) VALUES (?,?,?,?,?,?,?,?)',
            array_values($v));
    $eleve_id = (int)bd()->lastInsertId();
    $e = $v + ['id' => $eleve_id];
  }
  if (ligne('SELECT id FROM inscriptions WHERE eleve_id = ? AND annee_id = ?', [$eleve_id, $a['id']])) {
    bd()->rollBack(); erreur('Cet élève est déjà inscrit pour cette année scolaire.', 409);
  }
  foreach ((array)(champ('tuteurs', false) ?? []) as $t) lier_tuteur($eleve_id, (array)$t);
  requete("INSERT INTO inscriptions (eleve_id, classe_id, ecole_id, annee_id, date_inscription, redoublant) VALUES (?,?,?,?,CURDATE(),?)",
          [$eleve_id, $c['id'], $c['ecole_id'], $a['id'], (int)(bool)champ('redoublant', false)]);
  $insc = (int)bd()->lastInsertId();
  bd()->commit();
  journaliser('INSCRIPTION', 'inscriptions', $insc, "{$e['nom']} {$e['prenoms']} inscrit(e) en {$c['nom']}");
  repondre(['eleve_id' => $eleve_id, 'inscription_id' => $insc, 'matricule' => $e['matricule'],
            'avertissement' => avertissement_sexe($e['educmaster'] ?? null, $e['sexe'])], 201);
}

function r_eleves_modifier() {
  exiger_role(...ROLES_ELEVES);
  $e = eleve_accessible(entier('id'));
  $v = lire_identite();
  if ($v['educmaster'] && ligne('SELECT id FROM eleves WHERE educmaster = ? AND id <> ?', [$v['educmaster'], $e['id']]))
    erreur('Ce numéro Educmaster appartient déjà à un autre élève.', 409);
  requete('UPDATE eleves SET educmaster = ?, nom = ?, prenoms = ?, sexe = ?, date_naissance = ?, lieu_naissance = ?, nationalite = ? WHERE id = ?',
          [...array_values($v), $e['id']]);
  $avant = array_intersect_key($e, $v);
  $diff = array_filter($v, fn($x, $k) => (string)$x !== (string)$avant[$k], ARRAY_FILTER_USE_BOTH);
  if ($diff) journaliser('MODIFICATION', 'eleves', $e['id'], "{$v['nom']} {$v['prenoms']}", array_intersect_key($avant, $diff), $diff);
  repondre(['avertissement' => avertissement_sexe($v['educmaster'], $v['sexe'])]);
}

function r_eleves_tuteur_enregistrer() {
  exiger_role(...ROLES_ELEVES);
  $e = eleve_accessible(entier('eleve_id'));
  lier_tuteur($e['id'], entree());
  journaliser('MODIFICATION', 'eleve_tuteurs', $e['id'], "Tuteur enregistré pour {$e['nom']} {$e['prenoms']}");
  repondre();
}

function r_eleves_tuteur_retirer() {
  exiger_role(...ROLES_ELEVES);
  $e = eleve_accessible(entier('eleve_id'));
  requete('DELETE FROM eleve_tuteurs WHERE eleve_id = ? AND tuteur_id = ?', [$e['id'], entier('tuteur_id')]);
  journaliser('SUPPRESSION', 'eleve_tuteurs', $e['id'], "Tuteur retiré pour {$e['nom']} {$e['prenoms']}");
  repondre();
}

// Changement de classe, de statut (transfert, abandon, exclusion) ou de la mention redoublant
function r_eleves_inscription_modifier() {
  exiger_role(...ROLES_ELEVES);
  $i = ligne('SELECT i.*, c.nom AS classe FROM inscriptions i JOIN classes c ON c.id = i.classe_id WHERE i.id = ?', [entier('id')]);
  if (!$i) erreur('Inscription introuvable.', 404);
  verifier_ecole((int)$i['ecole_id']);
  $classe = entier('classe_id', false) ?? (int)$i['classe_id'];
  $statut = strtoupper((string)(champ('statut', false) ?? $i['statut']));
  $red = champ('redoublant', false) === null ? (int)$i['redoublant'] : (int)(bool)champ('redoublant');
  if (!in_array($statut, ['ACTIF', 'TRANSFERE', 'ABANDON', 'EXCLU'], true)) erreur('Statut invalide.');
  if ($statut === 'EXCLU' && !in_array($GLOBALS['UTILISATEUR']['role'], ROLES_ADMIN_ECOLE, true)) erreur("Seule la direction peut prononcer une exclusion.", 403);
  if ($classe !== (int)$i['classe_id']) {
    $c = classe($classe);
    if ((int)$c['annee_id'] !== (int)$i['annee_id'] || (int)$c['ecole_id'] !== (int)$i['ecole_id']) erreur('La nouvelle classe doit être de la même école et de la même année.');
    if (ligne('SELECT id FROM notes WHERE inscription_id = ? LIMIT 1', [$i['id']]))
      erreur("Cet élève a déjà des notes dans sa classe actuelle : le changement de classe doit être fait par la direction avant toute saisie.", 409);
  }
  requete('UPDATE inscriptions SET classe_id = ?, statut = ?, redoublant = ? WHERE id = ?', [$classe, $statut, $red, $i['id']]);
  journaliser('MODIFICATION', 'inscriptions', $i['id'], 'Inscription modifiée',
              ['classe_id' => $i['classe_id'], 'statut' => $i['statut'], 'redoublant' => $i['redoublant']],
              ['classe_id' => $classe, 'statut' => $statut, 'redoublant' => $red]);
  repondre();
}

// =====================================================================
//  IMPORT D'UNE LISTE D'ÉLÈVES (Excel, CSV ou tableau copié depuis Word)
//  Le site lit le fichier et envoie des lignes déjà découpées :
//    { educmaster, nom, prenoms, sexe, date_naissance, lieu_naissance }
//  confirmer = false : aperçu seul (rien n'est enregistré)
//  confirmer = true  : enregistrement des lignes valides, en une seule transaction
// =====================================================================
const MAX_LIGNES_IMPORT = 400;

function sexe_normalise($v): ?string {
  $v = mb_strtoupper(trim((string)$v));
  if (in_array($v, ['M', 'G', 'H', 'MASCULIN', 'GARÇON', 'GARCON', 'HOMME'], true)) return 'M';
  if (in_array($v, ['F', 'FÉMININ', 'FEMININ', 'FILLE', 'FEMME'], true)) return 'F';
  return null;
}

function analyser_ligne(array $l, array $a): array {
  $r = ['nom' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string)($l['nom'] ?? '')))),
        'prenoms' => trim(preg_replace('/\s+/', ' ', (string)($l['prenoms'] ?? ''))),
        'sexe' => sexe_normalise($l['sexe'] ?? ''),
        'educmaster' => null, 'date_naissance' => null,
        'lieu_naissance' => trim((string)($l['lieu_naissance'] ?? '')) ?: null,
        'statut' => 'NOUVEAU', 'erreurs' => [], 'avertissements' => [], 'eleve_id' => null];
  if ($r['nom'] === '') $r['erreurs'][] = 'Nom manquant';
  if ($r['prenoms'] === '') $r['erreurs'][] = 'Prénoms manquants';
  if (!$r['sexe']) $r['erreurs'][] = 'Sexe manquant ou illisible (« ' . ($l['sexe'] ?? '') . ' »)';

  $num = preg_replace('/\s+/', '', (string)($l['educmaster'] ?? ''));
  if ($num !== '') {
    if (!ctype_digit($num)) $r['avertissements'][] = "Numéro Educmaster ignoré (« {$l['educmaster']} »)";
    elseif (!preg_match('/^[0-9]{12,13}$/', $num)) $r['erreurs'][] = 'Numéro Educmaster à ' . strlen($num) . ' chiffres (12 ou 13 attendus)';
    else {
      $r['educmaster'] = $num;
      if ($r['sexe'] && ($w = avertissement_sexe($num, $r['sexe']))) $r['avertissements'][] = 'Le numéro ne correspond pas au sexe indiqué';
    }
  }
  if (!empty($l['date_naissance'])) {
    try { $r['date_naissance'] = date_valide($l['date_naissance']); }
    catch (ErreurApi $e) { $r['avertissements'][] = "Date de naissance ignorée (« {$l['date_naissance']} »)"; }
  }
  if ($r['erreurs']) { $r['statut'] = 'ERREUR'; return $r; }

  // Élève déjà connu dans le réseau ?
  $exist = $r['educmaster'] ? ligne('SELECT id FROM eleves WHERE educmaster = ?', [$r['educmaster']])
         : ($r['date_naissance'] ? ligne('SELECT id FROM eleves WHERE nom = ? AND prenoms = ? AND date_naissance = ? AND educmaster IS NULL',
                                         [$r['nom'], $r['prenoms'], $r['date_naissance']]) : null);
  if ($exist) {
    $r['eleve_id'] = (int)$exist['id'];
    $insc = ligne('SELECT c.nom FROM inscriptions i JOIN classes c ON c.id = i.classe_id WHERE i.eleve_id = ? AND i.annee_id = ?', [$exist['id'], $a['id']]);
    $r['statut'] = $insc ? 'DEJA_INSCRIT' : 'REINSCRIPTION';
    if ($insc) $r['avertissements'][] = "Déjà inscrit(e) cette année en {$insc['nom']}";
  }
  return $r;
}

function r_eleves_importer() {
  exiger_role(...ROLES_ELEVES);
  $c = classe(entier('classe_id'));
  $a = annee_en_cours();
  if ((int)$c['annee_id'] !== (int)$a['id']) erreur("Cette classe n'appartient pas à l'année en cours.");
  $lignes = champ('lignes');
  if (!is_array($lignes) || !$lignes) erreur('Aucune ligne à importer.');
  if (count($lignes) > MAX_LIGNES_IMPORT) erreur('Maximum ' . MAX_LIGNES_IMPORT . ' élèves par import : importez classe par classe.');
  $confirmer = (bool)champ('confirmer', false);

  $resultats = []; $vus = [];
  foreach (array_values($lignes) as $i => $l) {
    $r = analyser_ligne((array)$l, $a);
    // Doublons à l'intérieur du fichier
    $cle = $r['educmaster'] ?: ($r['nom'] . '|' . $r['prenoms']);
    if ($r['statut'] !== 'ERREUR' && isset($vus[$cle])) { $r['statut'] = 'ERREUR'; $r['erreurs'][] = 'Doublon de la ligne ' . ($vus[$cle] + 1); }
    $vus[$cle] ??= $i;
    $r['ligne'] = $i + 1;
    $resultats[] = $r;
  }

  $compte = array_count_values(array_column($resultats, 'statut'));
  if (!$confirmer) repondre(['lignes' => $resultats, 'compte' => $compte, 'classe' => $c['nom']]);

  $importes = 0;
  bd()->beginTransaction();
  try {
    foreach ($resultats as &$r) {
      if (!in_array($r['statut'], ['NOUVEAU', 'REINSCRIPTION'], true)) continue;
      if ($r['statut'] === 'NOUVEAU') {
        requete('INSERT INTO eleves (educmaster, nom, prenoms, sexe, date_naissance, lieu_naissance, matricule) VALUES (?,?,?,?,?,?,?)',
                [$r['educmaster'], $r['nom'], $r['prenoms'], $r['sexe'], $r['date_naissance'], $r['lieu_naissance'],
                 nouveau_matricule((int)$c['ecole_id'], $a)]);
        $r['eleve_id'] = (int)bd()->lastInsertId();
      }
      requete('INSERT INTO inscriptions (eleve_id, classe_id, ecole_id, annee_id, date_inscription) VALUES (?,?,?,?,CURDATE())',
              [$r['eleve_id'], $c['id'], $c['ecole_id'], $a['id']]);
      $importes++;
    }
    unset($r);
    bd()->commit();
  } catch (Throwable $e) {
    bd()->rollBack();
    throw $e;
  }
  journaliser('IMPORT', 'classes', $c['id'], "{$c['nom']} : $importes élève(s) importé(s) sur " . count($resultats) . ' ligne(s)');
  repondre(['importes' => $importes, 'compte' => $compte, 'classe' => $c['nom']], 201);
}
