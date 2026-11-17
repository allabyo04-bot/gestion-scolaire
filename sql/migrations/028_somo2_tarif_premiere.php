<?php
// =====================================================================
//  028 — CPEG SOMO 2 : tarif de la 1ère 2026-2027 (85 000 F, comme la 2nde : 35 000 / 30 000 / 20 000)
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
$ecole = ligne("SELECT id FROM ecoles WHERE code = 'SOMO2'");
$niveau = ligne("SELECT id FROM niveaux WHERE code = '1ERE'");
if (!$annee || !$ecole || !$niveau) { info('  SOMO 2 / 1ère introuvable : rien à faire.'); return; }
if (ligne('SELECT id FROM tarifs WHERE ecole_id = ? AND annee_id = ? AND niveau_id = ?', [$ecole['id'], $annee['id'], $niveau['id']])) { info('  Tarif de la 1ère déjà présent.'); return; }
requete('INSERT INTO tarifs (ecole_id, annee_id, niveau_id, montant) VALUES (?,?,?,85000)', [$ecole['id'], $annee['id'], $niveau['id']]);
$tid = (int)bd()->lastInsertId();
foreach ([['1re tranche (inscription)', 35000, '2026-10-15'], ['2e tranche (décembre)', 30000, '2026-12-31'], ['3e tranche (février)', 20000, '2027-02-28']] as $i => [$lib, $mt, $ech])
  requete('INSERT INTO tarif_tranches (tarif_id, numero, libelle, montant, echeance) VALUES (?,?,?,?,?)', [$tid, $i + 1, $lib, $mt, $ech]);
info('  SOMO 2 : tarif de la 1ère (85 000 F) ajouté.');
