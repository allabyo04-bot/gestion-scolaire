<?php
// =====================================================================
//  006 — Tarifs 2026-2027 du réseau FVPT (d'après les documents des écoles)
//  Ne crée un tarif que pour les niveaux qui n'en ont pas encore.
// =====================================================================
$annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) { info('  Année 2026-2027 absente : aucun tarif créé.'); return; }

// Tranches de Kandi : inscription, novembre, janvier
$kandi = fn($total, $t1, $t2, $t3) => [$total, null, [
  ['1re tranche (inscription)', $t1, '2026-10-15'],
  ['2e tranche (novembre)', $t2, '2026-11-30'],
  ['3e tranche (janvier)', $t3, '2027-01-31']]];
$unique = fn($total, $obs = null) => [$total, $obs, [['Scolarité', $total, null]]];

$tarifs = [
  'KANDI' => ['6E' => $kandi(70000, 30000, 25000, 15000), '5E' => $kandi(70000, 30000, 25000, 15000),
              '4E' => $kandi(70000, 30000, 25000, 15000), '3E' => $kandi(85000, 40000, 30000, 15000),
              '2NDE' => $kandi(85000, 35000, 30000, 20000), '1ERE' => $kandi(85000, 35000, 30000, 20000),
              'TLE' => $kandi(115000, 55000, 50000, 10000)],
  'SOMO' => ['6E' => $unique(76500), '5E' => $unique(76500), '4E' => $unique(86500), '3E' => $unique(115000),
             '2NDE' => $unique(115000), '1ERE' => $unique(120000), 'TLE' => $unique(145000)],
];
foreach (['STANDRE', 'SEGOVIANA'] as $ep) {
  $tarifs[$ep] = ['MAT1' => $unique(45000, 'Tout frais compris'), 'MAT2' => $unique(45000, 'Tout frais compris')];
  foreach (['CI', 'CP', 'CE1', 'CE2', 'CM1'] as $n) $tarifs[$ep][$n] = $unique(50000, 'Tout frais compris');
  $tarifs[$ep]['CM2'] = $unique(55000, 'Tout frais compris, sauf examen et examens blancs nationaux');
}

$crees = 0;
foreach ($tarifs as $code => $parNiveau) {
  $ecole = ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
  if (!$ecole) continue;
  foreach ($parNiveau as $codeNiveau => [$total, $obs, $tranches]) {
    $niveau = ligne('SELECT id FROM niveaux WHERE code = ?', [$codeNiveau]);
    if (!$niveau || ligne('SELECT id FROM tarifs WHERE ecole_id = ? AND annee_id = ? AND niveau_id = ?', [$ecole['id'], $annee['id'], $niveau['id']])) continue;
    requete('INSERT INTO tarifs (ecole_id, annee_id, niveau_id, montant, observation) VALUES (?,?,?,?,?)',
            [$ecole['id'], $annee['id'], $niveau['id'], $total, $obs]);
    $tid = (int)bd()->lastInsertId();
    foreach ($tranches as $i => [$lib, $mt, $ech])
      requete('INSERT INTO tarif_tranches (tarif_id, numero, libelle, montant, echeance) VALUES (?,?,?,?,?)', [$tid, $i + 1, $lib, $mt, $ech]);
    $crees++;
  }
}
info("  Tarifs : $crees niveau(x) tarifé(s).");
