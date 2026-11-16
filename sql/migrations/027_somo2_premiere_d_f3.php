<?php
// =====================================================================
//  027 — Compléments FVPT (nov. 2026)
//  CPEG SOMO 2 (Sirarou) : classe de 1ère D (matières et coefficients repris du Lycée Champagnat)
//                          et tarifs 2026-2027 (trois tranches : inscription, décembre, février).
//  CPET GALINDO : coefficients de la série F3 transmis par l'école, matières Hygiène et Dessin ajoutées.
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) { info('  Année 2026-2027 introuvable : rien à faire.'); return; }
$aid = (int)$annee['id'];
$ecole = fn(string $code) => ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
$niveau = fn(string $code) => (int)(ligne('SELECT id FROM niveaux WHERE code = ?', [$code])['id'] ?? 0);
$bilan = [];

// --- SOMO 2 : 1ère D ---
$somo2 = $ecole('SOMO2');
$serieD = ligne("SELECT id FROM series WHERE code = 'D'");
if ($somo2 && $serieD && !ligne("SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = '1ère D'", [$somo2['id'], $aid])) {
  requete("INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom) VALUES (?,?,?,?, '1ère D')", [$somo2['id'], $aid, $niveau('1ERE'), $serieD['id']]);
  $cid = (int)bd()->lastInsertId();
  $source = ligne("SELECT c.id FROM classes c JOIN ecoles e ON e.id = c.ecole_id WHERE e.code = 'KANDI' AND c.annee_id = ? AND c.nom = '1ère D'", [$aid]);
  if ($source)
    requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage, actif)
             SELECT ?, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage, actif FROM classe_matieres WHERE classe_id = ?', [$cid, $source['id']]);
  $bilan[] = 'SOMO 2 : 1ère D créée';
}

// --- SOMO 2 : tarifs ---
if ($somo2) {
  $t = fn($total, $t1, $t2, $t3) => [$total, [
    ['1re tranche (inscription)', $t1, '2026-10-15'],
    ['2e tranche (décembre)', $t2, '2026-12-31'],
    ['3e tranche (février)', $t3, '2027-02-28']]];
  $tarifs = ['6E' => $t(60000, 25000, 20000, 15000), '5E' => $t(60000, 25000, 20000, 15000),
             '4E' => $t(65000, 30000, 20000, 15000), '3E' => $t(75000, 30000, 30000, 15000),
             '2NDE' => $t(85000, 35000, 30000, 20000)];
  $n = 0;
  foreach ($tarifs as $codeNiveau => [$total, $tranches]) {
    $nid = $niveau($codeNiveau);
    if (!$nid || ligne('SELECT id FROM tarifs WHERE ecole_id = ? AND annee_id = ? AND niveau_id = ?', [$somo2['id'], $aid, $nid])) continue;
    requete('INSERT INTO tarifs (ecole_id, annee_id, niveau_id, montant) VALUES (?,?,?,?)', [$somo2['id'], $aid, $nid, $total]);
    $tid = (int)bd()->lastInsertId();
    foreach ($tranches as $i => [$lib, $mt, $ech])
      requete('INSERT INTO tarif_tranches (tarif_id, numero, libelle, montant, echeance) VALUES (?,?,?,?,?)', [$tid, $i + 1, $lib, $mt, $ech]);
    $n++;
  }
  $bilan[] = "SOMO 2 : $n tarif(s)";
}

// --- GALINDO : coefficients F3 (fiche de notes de l'école) ---
foreach (['HYGIENE' => 'Hygiène', 'DESSIN' => 'Dessin technique'] as $code => $lib)
  if (!ligne('SELECT id FROM matieres WHERE code = ?', [$code]))
    requete('INSERT INTO matieres (code, libelle) VALUES (?, ?)', [$code, $lib]);
$F3 = [['MATHS', 4, 'Sciences'], ['HYGIENE', 2, 'Sciences'], ['ANGLAIS', 2, 'Lettres'], ['PCT', 3, 'Sciences'],
       ['FRANCAIS', 2, 'Lettres'], ['TP', 5, 'Technique'], ['MEL', 4, 'Technique'], ['SCHEMA', 3, 'Technique'],
       ['TECHNO', 2, 'Technique'], ['ELECTROTECH', 4, 'Technique'], ['DESSIN', 4, 'Technique'], ['CONDUITE', 1, 'Autres']];
$galindo = $ecole('GALINDO');
if ($galindo) {
  foreach (lignes("SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom LIKE '%F3'", [$galindo['id'], $aid]) as $c)
    foreach ($F3 as $rang => [$code, $coef, $groupe]) {
      $mid = (int)ligne('SELECT id FROM matieres WHERE code = ?', [$code])['id'];
      if (ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $mid]))
        requete('UPDATE classe_matieres SET coefficient = ?, coef_a_confirmer = 0, ordre_affichage = ? WHERE classe_id = ? AND matiere_id = ?', [$coef, $rang + 1, $c['id'], $mid]);
      else
        requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage) VALUES (?,?,?,0,?,?)', [$c['id'], $mid, $coef, $groupe, $rang + 1]);
    }
  $bilan[] = 'GALINDO : coefficients F3 appliqués';
}
info('  ' . implode(' ; ', $bilan) . '.');
