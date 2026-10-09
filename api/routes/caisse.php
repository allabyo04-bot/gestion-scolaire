<?php
// =====================================================================
//  REMISE DE CAISSE : récap journalier par caissier, espèces comptées,
//  écart, bordereau, confirmation par la direction.
//  Rien ne dépend d'une « fermeture » : le récap se calcule toujours par jour.
// =====================================================================
require_once __DIR__ . '/finances.php';

function recap_jour(int $ecole, int $caissier, string $date): array {
  $p = lignes("SELECT p.id, p.numero_recu, p.montant, p.mode, p.reference, p.annule, p.encaisse_le, p.annule_le, p.motif_annulation,
                      el.nom, el.prenoms, c.nom AS classe
               FROM paiements p JOIN inscriptions i ON i.id = p.inscription_id JOIN eleves el ON el.id = i.eleve_id JOIN classes c ON c.id = i.classe_id
               WHERE p.ecole_id = ? AND p.encaisse_par = ? AND p.date_paiement = ? ORDER BY p.numero_recu", [$ecole, $caissier, $date]);
  $parMode = array_fill_keys(MODES_PAIEMENT, 0); $total = 0; $nb = 0;
  foreach ($p as $x) if (!$x['annule']) { $parMode[$x['mode']] += (int)$x['montant']; $total += (int)$x['montant']; $nb++; }
  return ['paiements' => $p, 'par_mode' => $parMode, 'total' => $total, 'nb_recus' => $nb];
}

function caissier_vise(): int {
  global $UTILISATEUR;
  if (in_array($UTILISATEUR['role'], ROLES_ADMIN_ECOLE, true) && ($c = entier('caissier_id', false))) return $c;
  return (int)$UTILISATEUR['id'];
}

function date_caisse(): string {
  $d = champ('date', false) ?? date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > date('Y-m-d')) erreur('Date invalide.');
  return $d;
}

// Récap d'une journée, avec la remise éventuelle et ce qui a changé depuis
function r_caisse_jour() {
  exiger_role(...ROLES_CAISSE);
  $e = ecole_cible(); $caissier = caissier_vise(); $date = date_caisse();
  $r = recap_jour($e, $caissier, $date);
  $remise = ligne("SELECT rc.*, CONCAT(u.nom, ' ', u.prenoms) AS confirmee_par_nom FROM remises_caisse rc LEFT JOIN utilisateurs u ON u.id = rc.confirmee_par
                   WHERE rc.ecole_id = ? AND rc.caissier_id = ? AND rc.date_caisse = ?", [$e, $caissier, $date]);
  if ($remise) {
    $remise['par_mode'] = json_decode($remise['par_mode'], true);
    $remise['changements'] = $r['total'] - (int)$remise['total_systeme'];        // encaissé ou annulé après la remise
  }
  $u = ligne("SELECT CONCAT(nom, ' ', prenoms) AS nom FROM utilisateurs WHERE id = ?", [$caissier]);
  repondre($r + ['remise' => $remise, 'date' => $date, 'caissier' => $u['nom'] ?? '', 'caissier_id' => $caissier,
                 'ecole' => ligne('SELECT nom_officiel, ville FROM ecoles WHERE id = ?', [$e]) + ['images' => images_ecole($e)]]);
}

// Le caissier remet sa caisse du jour (ou corrige sa remise tant qu'elle n'est pas confirmée)
function r_caisse_remettre() {
  global $UTILISATEUR;
  exiger_role(...ROLES_CAISSE);
  $e = ecole_cible(); $date = date_caisse(); $moi = (int)$UTILISATEUR['id'];
  $exist = ligne('SELECT * FROM remises_caisse WHERE ecole_id = ? AND caissier_id = ? AND date_caisse = ?', [$e, $moi, $date]);
  if ($exist && $exist['statut'] === 'CONFIRMEE') erreur('Cette remise a déjà été confirmée par la direction : elle ne peut plus être modifiée.', 409);
  $comptees = preg_replace('/[\s.]/', '', (string)champ('especes_comptees'));
  if (!ctype_digit($comptees)) erreur('Indiquez le montant des espèces comptées (0 si aucune).');
  $comptees = (int)$comptees;
  $r = recap_jour($e, $moi, $date);
  if (!$r['nb_recus']) erreur("Aucun encaissement ce jour-là : rien à remettre.", 409);
  $ecart = $comptees - $r['par_mode']['ESPECES'];
  $commentaire = texte_ou_null('commentaire');
  if ($ecart !== 0 && mb_strlen((string)$commentaire) < 5) erreur('Il y a un écart de ' . number_format($ecart, 0, ',', ' ') . ' F : expliquez-le dans le commentaire.', 422);
  $val = [$r['nb_recus'], $r['total'], $r['par_mode']['ESPECES'], json_encode($r['par_mode']), $comptees, $ecart, $commentaire];
  if ($exist) {
    requete('UPDATE remises_caisse SET nb_recus = ?, total_systeme = ?, total_especes = ?, par_mode = ?, especes_comptees = ?, ecart = ?, commentaire = ?, remise_le = NOW() WHERE id = ?',
            [...$val, $exist['id']]);
    $id = (int)$exist['id'];
  } else {
    requete('INSERT INTO remises_caisse (nb_recus, total_systeme, total_especes, par_mode, especes_comptees, ecart, commentaire, ecole_id, caissier_id, date_caisse) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [...$val, $e, $moi, $date]);
    $id = (int)bd()->lastInsertId();
  }
  journaliser('REMISE_CAISSE', 'remises_caisse', $id, "Caisse du $date : " . number_format($r['total'], 0, ',', ' ') . " F, espèces comptées " . number_format($comptees, 0, ',', ' ') . " F, écart $ecart F");
  repondre(['id' => $id, 'ecart' => $ecart]);
}

function r_caisse_confirmer() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $rc = ligne('SELECT * FROM remises_caisse WHERE id = ?', [entier('id')]);
  if (!$rc) erreur('Remise introuvable.', 404);
  verifier_ecole((int)$rc['ecole_id']);
  if ($rc['statut'] === 'CONFIRMEE') erreur('Remise déjà confirmée.', 409);
  requete("UPDATE remises_caisse SET statut = 'CONFIRMEE', confirmee_par = ?, confirmee_le = NOW(), commentaire_direction = ? WHERE id = ?",
          [$UTILISATEUR['id'], texte_ou_null('commentaire'), $rc['id']]);
  journaliser('CONFIRMATION_CAISSE', 'remises_caisse', $rc['id'], "Remise du {$rc['date_caisse']} confirmée (" . number_format($rc['especes_comptees'], 0, ',', ' ') . ' F en espèces)');
  repondre();
}

// Direction : remises de la période et journées encaissées sans remise
function r_caisse_remises() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = ecole_cible();
  $du = champ('du', false) ?? date('Y-m-d', strtotime('-30 day')); $au = champ('au', false) ?? date('Y-m-d');
  $remises = lignes("SELECT rc.*, CONCAT(u.nom, ' ', u.prenoms) AS caissier FROM remises_caisse rc JOIN utilisateurs u ON u.id = rc.caissier_id
                     WHERE rc.ecole_id = ? AND rc.date_caisse BETWEEN ? AND ? ORDER BY rc.date_caisse DESC, u.nom", [$e, $du, $au]);
  foreach ($remises as &$x) { $x['par_mode'] = json_decode($x['par_mode'], true); $x['changements'] = recap_jour($e, (int)$x['caissier_id'], $x['date_caisse'])['total'] - (int)$x['total_systeme']; }
  unset($x);
  repondre(['remises' => $remises, 'non_remises' => jours_non_remis($e, $du, $au), 'du' => $du, 'au' => $au]);
}

function jours_non_remis(int $ecole, string $du, string $au): array {
  return lignes("SELECT p.date_paiement AS date_caisse, p.encaisse_par AS caissier_id, CONCAT(u.nom, ' ', u.prenoms) AS caissier,
                        COUNT(*) AS nb_recus, SUM(p.montant) AS total
                 FROM paiements p JOIN utilisateurs u ON u.id = p.encaisse_par
                 LEFT JOIN remises_caisse rc ON rc.ecole_id = p.ecole_id AND rc.caissier_id = p.encaisse_par AND rc.date_caisse = p.date_paiement
                 WHERE p.ecole_id = ? AND p.annule = 0 AND p.date_paiement BETWEEN ? AND ? AND rc.id IS NULL
                 GROUP BY p.date_paiement, p.encaisse_par ORDER BY p.date_paiement DESC", [$ecole, $du, $au]);
}
