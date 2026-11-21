<?php
// =====================================================================
//  029 — Lycée Champagnat : classe de 2nde C (liste de l'école, nov. 2026)
//  Matières reprises de la 2nde D, coefficients marqués « à confirmer ».
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
$ecole = ligne("SELECT id FROM ecoles WHERE code = 'KANDI'");
$serie = ligne("SELECT id FROM series WHERE code = 'C'");
$niveau = ligne("SELECT id FROM niveaux WHERE code = '2NDE'");
if (!$annee || !$ecole || !$serie || !$niveau) { info('  Champagnat / 2nde C : référentiel incomplet, rien à faire.'); return; }
if (ligne("SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = '2nde C'", [$ecole['id'], $annee['id']])) { info('  2nde C déjà présente.'); return; }
requete("INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom) VALUES (?,?,?,?, '2nde C')", [$ecole['id'], $annee['id'], $niveau['id'], $serie['id']]);
$cid = (int)bd()->lastInsertId();
$source = ligne("SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = '2nde D'", [$ecole['id'], $annee['id']]);
if ($source)
  requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage, actif)
           SELECT ?, matiere_id, coefficient, 1, groupe_bulletin, ordre_affichage, actif FROM classe_matieres WHERE classe_id = ?', [$cid, $source['id']]);
info('  Champagnat : 2nde C créée.');
