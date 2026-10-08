<?php
// =====================================================================
//  FINANCES : tarifs, situation d'un élève, encaissements, reçus,
//             annulations, remises, journal de caisse, impayés
// =====================================================================
const ROLES_CAISSE = ['SUPER_ADMIN', 'DIRECTRICE', 'COMPTABLE', 'SECRETARIAT'];
const MODES_PAIEMENT = ['ESPECES', 'MOBILE_MONEY', 'VIREMENT', 'CHEQUE'];

function montant_valide($v, string $nom = 'montant'): int {
  $t = preg_replace('/[\s.]/', '', (string)$v);
  if (!ctype_digit($t) || (int)$t <= 0) erreur("Le $nom doit être un nombre entier positif (en francs CFA).");
  if ((int)$t > 10000000) erreur("Le $nom semble trop élevé.");
  return (int)$t;
}

function inscription_finances(int $id): array {
  $i = ligne("SELECT i.*, c.nom AS classe, c.niveau_id, n.libelle AS niveau, el.nom, el.prenoms, el.sexe, el.matricule, el.educmaster,
                     a.libelle AS annee
              FROM inscriptions i JOIN classes c ON c.id = i.classe_id JOIN niveaux n ON n.id = c.niveau_id
              JOIN eleves el ON el.id = i.eleve_id JOIN annees_scolaires a ON a.id = i.annee_id WHERE i.id = ?", [$id]);
  if (!$i) erreur('Inscription introuvable.', 404);
  verifier_ecole((int)$i['ecole_id']);
  return $i;
}

function tarif_de(int $ecole, int $annee, int $niveau): ?array {
  $t = ligne('SELECT * FROM tarifs WHERE ecole_id = ? AND annee_id = ? AND niveau_id = ?', [$ecole, $annee, $niveau]);
  if ($t) $t['tranches'] = lignes('SELECT * FROM tarif_tranches WHERE tarif_id = ? ORDER BY numero', [$t['id']]);
  return $t;
}

// Situation complète d'un élève : dû, payé, reste, état de chaque tranche
function situation(array $i): array {
  $tarif = tarif_de((int)$i['ecole_id'], (int)$i['annee_id'], (int)$i['niveau_id']);
  $remises = lignes("SELECT r.*, CONCAT(u.nom, ' ', u.prenoms) AS auteur FROM remises r JOIN utilisateurs u ON u.id = r.accordee_par
                     WHERE r.inscription_id = ? ORDER BY r.accordee_le", [$i['id']]);
  $paiements = lignes("SELECT p.*, CONCAT(u.nom, ' ', u.prenoms) AS caissier FROM paiements p JOIN utilisateurs u ON u.id = p.encaisse_par
                       WHERE p.inscription_id = ? ORDER BY p.date_paiement, p.id", [$i['id']]);
  $total = (int)($tarif['montant'] ?? 0);
  $remise = array_sum(array_map('intval', array_column($remises, 'montant')));
  $paye = array_sum(array_map(fn($p) => $p['annule'] ? 0 : (int)$p['montant'], $paiements));
  $du = max(0, $total - $remise);

  // Les remises allègent les dernières tranches ; les paiements couvrent les tranches dans l'ordre
  $tranches = $tarif['tranches'] ?? [];
  $aDeduire = $remise;
  for ($k = count($tranches) - 1; $k >= 0; $k--) {
    $d = min($aDeduire, (int)$tranches[$k]['montant']);
    $tranches[$k]['a_payer'] = (int)$tranches[$k]['montant'] - $d; $aDeduire -= $d;
  }
  $reste_paye = $paye; $retard = 0; $aujourdhui = date('Y-m-d');
  foreach ($tranches as &$t) {
    $t['paye'] = min($reste_paye, $t['a_payer']); $reste_paye -= $t['paye'];
    $t['reste'] = $t['a_payer'] - $t['paye'];
    $t['etat'] = $t['reste'] === 0 ? 'PAYEE' : ($t['echeance'] && $t['echeance'] < $aujourdhui ? 'EN_RETARD' : ($t['paye'] > 0 ? 'PARTIELLE' : 'A_VENIR'));
    if ($t['etat'] === 'EN_RETARD') $retard += $t['reste'];
  }
  unset($t);
  return ['tarif' => $tarif ? ['montant' => $total, 'observation' => $tarif['observation']] : null, 'tranches' => $tranches,
          'remises' => $remises, 'paiements' => $paiements,
          'totaux' => ['tarif' => $total, 'remises' => $remise, 'du' => $du, 'paye' => $paye, 'reste' => max(0, $du - $paye), 'en_retard' => $retard]];
}

// ---------------------------------------------------------------- Tarifs
function r_fin_tarifs() {
  exiger_role(...ROLES_CAISSE);
  $e = ecole_cible(); $a = annee_en_cours();
  $niveaux = lignes('SELECT DISTINCT n.id, n.code, n.libelle, n.cycle, n.ordre FROM classes c JOIN niveaux n ON n.id = c.niveau_id
                     WHERE c.ecole_id = ? AND c.annee_id = ? ORDER BY n.ordre', [$e, $a['id']]);
  foreach ($niveaux as &$n) $n['tarif'] = tarif_de($e, (int)$a['id'], (int)$n['id']);
  repondre(['annee' => $a['libelle'], 'niveaux' => $niveaux]);
}

function r_fin_tarif_enregistrer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible(); $a = annee_en_cours();
  $niveau = entier('niveau_id');
  $tranches = champ('tranches');
  if (!is_array($tranches) || !$tranches) erreur('Il faut au moins une tranche.');
  if (count($tranches) > 12) erreur('Maximum 12 tranches.');
  $propres = []; $total = 0;
  foreach (array_values($tranches) as $k => $t) {
    $lib = trim((string)($t['libelle'] ?? '')) ?: ($k === 0 ? '1re tranche' : ($k + 1) . 'e tranche');
    $mt = montant_valide($t['montant'] ?? '', 'montant de la tranche ' . ($k + 1));
    $ech = ($t['echeance'] ?? '') ?: null;
    if ($ech && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) erreur("Échéance invalide pour « $lib ».");
    $propres[] = [$lib, $mt, $ech]; $total += $mt;
  }
  $obs = texte_ou_null('observation');
  $avant = tarif_de($e, (int)$a['id'], $niveau);
  bd()->beginTransaction();
  if ($avant) {
    requete('UPDATE tarifs SET montant = ?, observation = ? WHERE id = ?', [$total, $obs, $avant['id']]);
    requete('DELETE FROM tarif_tranches WHERE tarif_id = ?', [$avant['id']]);
    $tid = (int)$avant['id'];
  } else {
    requete('INSERT INTO tarifs (ecole_id, annee_id, niveau_id, montant, observation) VALUES (?,?,?,?,?)', [$e, $a['id'], $niveau, $total, $obs]);
    $tid = (int)bd()->lastInsertId();
  }
  foreach ($propres as $k => [$lib, $mt, $ech])
    requete('INSERT INTO tarif_tranches (tarif_id, numero, libelle, montant, echeance) VALUES (?,?,?,?,?)', [$tid, $k + 1, $lib, $mt, $ech]);
  bd()->commit();
  journaliser($avant ? 'MODIFICATION' : 'CREATION', 'tarifs', $tid, "Tarif niveau $niveau : $total F (" . count($propres) . ' tranche(s))',
              $avant ? ['montant' => $avant['montant']] : null, ['montant' => $total]);
  repondre(['montant' => $total]);
}

// ---------------------------------------------------------------- Situation, encaissement, reçu
function r_fin_situation() {
  exiger_role(...ROLES_CAISSE);
  $i = inscription_finances(entier('inscription_id'));
  repondre(['inscription' => $i] + situation($i));
}

function prochain_numero_recu(int $ecole, array $annee): string {
  $code = ligne('SELECT code FROM ecoles WHERE id = ?', [$ecole])['code'];
  $prefixe = $code . '-' . substr($annee['libelle'], 2, 2) . '-R';
  $dernier = ligne('SELECT numero_recu FROM paiements WHERE numero_recu LIKE ? ORDER BY numero_recu DESC LIMIT 1 FOR UPDATE', [$prefixe . '%']);
  $n = $dernier ? (int)substr($dernier['numero_recu'], strlen($prefixe)) + 1 : 1;
  return $prefixe . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

function r_fin_encaisser() {
  global $UTILISATEUR;
  exiger_role(...ROLES_CAISSE);
  $i = inscription_finances(entier('inscription_id'));
  if ($i['statut'] !== 'ACTIF') erreur("Cet élève n'est plus inscrit (" . strtolower($i['statut']) . ').', 409);
  $montant = montant_valide(champ('montant'));
  $mode = strtoupper((string)champ('mode'));
  if (!in_array($mode, MODES_PAIEMENT, true)) erreur('Mode de paiement invalide.');
  $ref = texte_ou_null('reference');
  if (in_array($mode, ['MOBILE_MONEY', 'VIREMENT', 'CHEQUE'], true) && !$ref) erreur('Indiquez la référence (numéro de transaction ou de chèque).');
  $date = champ('date_paiement', false) ?? date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) erreur('Date de paiement invalide (pas de date future).');
  $annee = ligne('SELECT * FROM annees_scolaires WHERE id = ?', [$i['annee_id']]);

  bd()->beginTransaction();
  $s = situation($i);
  if (!$s['tarif']) { bd()->rollBack(); erreur("Aucun tarif n'est défini pour le niveau {$i['niveau']}. La direction doit d'abord le fixer.", 409); }
  if ($montant > $s['totaux']['reste']) { bd()->rollBack(); erreur('Le montant dépasse le reste à payer (' . number_format($s['totaux']['reste'], 0, ',', ' ') . ' F).', 422); }
  $numero = prochain_numero_recu((int)$i['ecole_id'], $annee);
  requete('INSERT INTO paiements (numero_recu, inscription_id, ecole_id, annee_id, montant, mode, reference, verse_par, date_paiement, reste_apres, encaisse_par)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$numero, $i['id'], $i['ecole_id'], $i['annee_id'], $montant, $mode, $ref, texte_ou_null('verse_par'), $date,
           $s['totaux']['reste'] - $montant, $UTILISATEUR['id']]);
  $id = (int)bd()->lastInsertId();
  bd()->commit();
  journaliser('PAIEMENT', 'paiements', $id, "Reçu $numero : " . number_format($montant, 0, ',', ' ') . " F ($mode) pour {$i['nom']} {$i['prenoms']}");
  repondre(['id' => $id, 'numero_recu' => $numero], 201);
}

function r_fin_recu() {
  exiger_role(...ROLES_CAISSE);
  $p = ligne("SELECT p.*, CONCAT(u.nom, ' ', u.prenoms) AS caissier FROM paiements p JOIN utilisateurs u ON u.id = p.encaisse_par WHERE p.id = ?", [entier('id')]);
  if (!$p) erreur('Paiement introuvable.', 404);
  $i = inscription_finances((int)$p['inscription_id']);
  $ecole = ligne('SELECT * FROM ecoles WHERE id = ?', [$i['ecole_id']]);
  $s = situation($i);
  journaliser('IMPRESSION', 'paiements', $p['id'], "Reçu {$p['numero_recu']} affiché");
  repondre(['paiement' => $p, 'inscription' => $i, 'ecole' => $ecole,
            'totaux' => $s['totaux'], 'reste_apres' => (int)$p['reste_apres']]);
}

function r_fin_annuler() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $p = ligne('SELECT * FROM paiements WHERE id = ?', [entier('id')]);
  if (!$p) erreur('Paiement introuvable.', 404);
  verifier_ecole((int)$p['ecole_id']);
  if ($p['annule']) erreur('Ce paiement est déjà annulé.', 409);
  $motif = (string)champ('motif');
  if (mb_strlen($motif) < 5) erreur("Le motif d'annulation est obligatoire (5 caractères minimum).");
  requete('UPDATE paiements SET annule = 1, annule_par = ?, annule_le = NOW(), motif_annulation = ? WHERE id = ?', [$UTILISATEUR['id'], $motif, $p['id']]);
  journaliser('ANNULATION_PAIEMENT', 'paiements', $p['id'], "Reçu {$p['numero_recu']} annulé. Motif : $motif",
              ['annule' => 0, 'montant' => $p['montant']], ['annule' => 1]);
  repondre();
}

function r_fin_remise() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $i = inscription_finances(entier('inscription_id'));
  $montant = montant_valide(champ('montant'), 'montant de la remise');
  $motif = (string)champ('motif');
  if (mb_strlen($motif) < 5) erreur('Le motif de la remise est obligatoire.');
  $s = situation($i);
  if ($montant > $s['totaux']['du'] - $s['totaux']['paye']) erreur('La remise ne peut pas dépasser le reste à payer.', 422);
  requete('INSERT INTO remises (inscription_id, montant, motif, accordee_par) VALUES (?,?,?,?)', [$i['id'], $montant, $motif, $UTILISATEUR['id']]);
  journaliser('REMISE', 'remises', (int)bd()->lastInsertId(), number_format($montant, 0, ',', ' ') . " F pour {$i['nom']} {$i['prenoms']}. Motif : $motif");
  repondre(null, 201);
}

function r_fin_remise_supprimer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $r = ligne('SELECT * FROM remises WHERE id = ?', [entier('id')]);
  if (!$r) erreur('Remise introuvable.', 404);
  $i = inscription_finances((int)$r['inscription_id']);
  requete('DELETE FROM remises WHERE id = ?', [$r['id']]);
  journaliser('SUPPRESSION', 'remises', $r['id'], "Remise de {$r['montant']} F retirée pour {$i['nom']} {$i['prenoms']}", $r, null);
  repondre();
}

// ---------------------------------------------------------------- Journal de caisse
function r_fin_journal() {
  global $UTILISATEUR;
  exiger_role(...ROLES_CAISSE);
  $e = ecole_cible();
  $du = champ('du', false) ?? date('Y-m-d'); $au = champ('au', false) ?? $du;
  $where = 'p.ecole_id = ? AND p.date_paiement BETWEEN ? AND ?'; $par = [$e, $du, $au];
  // Comptable et secrétariat voient leur propre caisse ; la direction voit tout (et peut filtrer)
  if (!in_array($UTILISATEUR['role'], ROLES_ADMIN_ECOLE, true)) { $where .= ' AND p.encaisse_par = ?'; $par[] = $UTILISATEUR['id']; }
  elseif ($c = entier('caissier_id', false)) { $where .= ' AND p.encaisse_par = ?'; $par[] = $c; }
  $l = lignes("SELECT p.id, p.numero_recu, p.date_paiement, p.encaisse_le, p.montant, p.mode, p.reference, p.annule, p.motif_annulation,
                      el.nom, el.prenoms, c.nom AS classe, CONCAT(u.nom, ' ', u.prenoms) AS caissier
               FROM paiements p JOIN inscriptions i ON i.id = p.inscription_id JOIN eleves el ON el.id = i.eleve_id
               JOIN classes c ON c.id = i.classe_id JOIN utilisateurs u ON u.id = p.encaisse_par
               WHERE $where ORDER BY p.encaisse_le DESC", $par);
  $parMode = array_fill_keys(MODES_PAIEMENT, 0); $total = 0;
  foreach ($l as $p) if (!$p['annule']) { $parMode[$p['mode']] += (int)$p['montant']; $total += (int)$p['montant']; }
  $caissiers = lignes("SELECT DISTINCT u.id, CONCAT(u.nom, ' ', u.prenoms) AS nom FROM paiements p JOIN utilisateurs u ON u.id = p.encaisse_par WHERE p.ecole_id = ?", [$e]);
  repondre(['paiements' => $l, 'par_mode' => $parMode, 'total' => $total, 'du' => $du, 'au' => $au, 'caissiers' => $caissiers]);
}

// ---------------------------------------------------------------- Impayés
function r_fin_impayes() {
  exiger_role(...ROLES_CAISSE);
  $e = ecole_cible(); $a = annee_en_cours();
  $cid = entier('classe_id', false);
  $sql = "SELECT i.id FROM inscriptions i JOIN eleves el ON el.id = i.eleve_id JOIN classes c ON c.id = i.classe_id
          JOIN niveaux n ON n.id = c.niveau_id WHERE i.ecole_id = ? AND i.annee_id = ? AND i.statut = 'ACTIF'";
  $ids = $cid ? lignes("$sql AND i.classe_id = ? ORDER BY el.nom, el.prenoms", [$e, $a['id'], $cid])
              : lignes("$sql ORDER BY n.ordre, c.nom, el.nom, el.prenoms", [$e, $a['id']]);
  $res = []; $tot = ['du' => 0, 'paye' => 0, 'reste' => 0, 'en_retard' => 0, 'eleves' => 0, 'sans_tarif' => 0];
  foreach ($ids as $x) {
    $i = inscription_finances((int)$x['id']); $s = situation($i);
    if (!$s['tarif']) { $tot['sans_tarif']++; continue; }
    foreach (['du', 'paye', 'reste', 'en_retard'] as $k) $tot[$k] += $s['totaux'][$k];
    $tot['eleves']++;
    if ($s['totaux']['reste'] > 0) $res[] = ['inscription_id' => $i['id'], 'nom' => $i['nom'], 'prenoms' => $i['prenoms'], 'matricule' => $i['matricule'],
      'classe' => $i['classe'], 'telephone' => ligne('SELECT t.telephone FROM eleve_tuteurs et JOIN tuteurs t ON t.id = et.tuteur_id
        WHERE et.eleve_id = ? ORDER BY et.principal DESC LIMIT 1', [$i['eleve_id']])['telephone'] ?? null] + $s['totaux'];
  }
  usort($res, fn($a, $b) => $b['en_retard'] <=> $a['en_retard'] ?: strcmp($a['classe'] . $a['nom'], $b['classe'] . $b['nom']));
  repondre(['eleves' => $res, 'totaux' => $tot]);
}
