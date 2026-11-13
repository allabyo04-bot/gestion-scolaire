<?php
// =====================================================================
//  025 — Trois établissements secondaires du Réseau des écoles FVPT (nov. 2026)
//  Collège Mgr Marcel AGBOTON (Banikoara) : 6e à 3e
//  CPET GALINDO (Banikoara, technique) : 2nde F3, 1ère F3
//  CPEG SOMO 2 (Sirarou) : 6e à 3e (la 1ère attend la confirmation de sa série)
//  Collège : matières et coefficients repris des classes de même nom du Lycée Champagnat (confirmés).
//  F3 : matières techniques ajoutées, coefficients à 1 « à confirmer ».
//  Les comptes du personnel ne sont PAS créés ici (données personnelles).
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
$groupe = ligne("SELECT id, logo FROM groupes WHERE code = 'FVPT'");
$modele = ligne("SELECT id, entete_ligne1, entete_ligne2, entete_ligne3, site_web FROM ecoles WHERE code = 'KANDI'");
if (!$annee || !$groupe || !$modele) { info('  Année, groupe FVPT ou Lycée Champagnat introuvable : rien à faire.'); return; }

// Série F3 et matières techniques
if (!ligne("SELECT id FROM series WHERE code = 'F3'"))
  requete("INSERT INTO series (code, libelle) VALUES ('F3', 'Série F3 — Électrotechnique')");
foreach (['TP' => 'Travaux pratiques', 'MEL' => 'MEL', 'TECHNO' => 'Technologie générale',
          'ELECTROTECH' => 'Électrotechnique', 'SCHEMA' => 'Schéma et automatisme'] as $code => $lib)
  if (!ligne('SELECT id FROM matieres WHERE code = ?', [$code]))
    requete('INSERT INTO matieres (code, libelle) VALUES (?, ?)', [$code, $lib]);

$niveaux = array_column(lignes('SELECT id, code FROM niveaux'), 'id', 'code');
$f3 = (int)ligne("SELECT id FROM series WHERE code = 'F3'")['id'];
$COLLEGE = ['6e' => '6E', '5e' => '5E', '4e' => '4E', '3e' => '3E'];
$ECOLES = [
  'AGBOTON' => ['Collège Mgr Marcel AGBOTON', 'Banikoara', $COLLEGE],
  'GALINDO' => ['CPET GALINDO', 'Banikoara', ['2nde F3' => '2NDE', '1ère F3' => '1ERE']],
  'SOMO2'   => ['CPEG SOMO 2', 'Sirarou', $COLLEGE],
];
$DATES = [1 => ['2026-09-14', '2026-12-18'], 2 => ['2027-01-04', '2027-03-26'], 3 => ['2027-04-12', '2027-07-16']];
$mat = fn(string $c) => (int)(ligne('SELECT id FROM matieres WHERE code = ?', [$c])['id'] ?? 0);
$bilan = [];

foreach ($ECOLES as $code => [$nom, $ville, $classes]) {
  $e = ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
  if (!$e) {
    requete('INSERT INTO ecoles (groupe_id, code, nom_officiel, ville, entete_ligne1, entete_ligne2, entete_ligne3, site_web, titre_signataire)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$groupe['id'], $code, $nom, $ville, $modele['entete_ligne1'], $modele['entete_ligne2'], $modele['entete_ligne3'], $modele['site_web'], 'Le Directeur']);
    $e = ['id' => (int)bd()->lastInsertId()];
  }
  $eid = (int)$e['id'];
  if (!ligne('SELECT id FROM periodes WHERE ecole_id = ? AND annee_id = ?', [$eid, $annee['id']])) {
    requete("INSERT IGNORE INTO parametres_annee (ecole_id, annee_id, type_periode) VALUES (?, ?, 'TRIMESTRE')", [$eid, $annee['id']]);
    foreach (['1er trimestre', '2e trimestre', '3e trimestre'] as $i => $lib)
      requete('INSERT INTO periodes (ecole_id, annee_id, numero, libelle, date_debut, date_fin) VALUES (?,?,?,?,?,?)',
              [$eid, $annee['id'], $i + 1, $lib, $DATES[$i + 1][0], $DATES[$i + 1][1]]);
  }
  if (!ligne('SELECT id FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id IS NULL', [$eid]))
    requete('INSERT INTO config_evaluation_defaut (ecole_id, niveau_id, nb_interros, nb_devoirs) VALUES (?, NULL, 3, 2)', [$eid]);
  if (!ligne('SELECT ecole_id FROM parametres_bulletin WHERE ecole_id = ?', [$eid]))
    requete("INSERT INTO parametres_bulletin (ecole_id, appreciations, seuil_felicitations, seuil_encouragements, seuil_tableau, titre_signataire, note_bas)
             VALUES (?, '[[18,\"Excellent\"],[16,\"Très bien\"],[14,\"Bien\"],[12,\"Assez bien\"],[10,\"Passable\"],[8,\"Médiocre\"],[0,\"Insuffisant\"]]', 16, 14, 12, 'Le Directeur',
             'NB : Conservez précieusement ce bulletin. Il ne sera délivré aucun duplicata.')", [$eid]);
  if ($groupe['logo'] && !ligne("SELECT id FROM ecole_images WHERE ecole_id = ? AND type = 'LOGO'", [$eid]))
    requete("INSERT INTO ecole_images (ecole_id, type, donnees) VALUES (?, 'LOGO', ?)", [$eid, $groupe['logo']]);

  $n = 0;
  foreach ($classes as $nomClasse => $niv) {
    if (ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$eid, $annee['id'], $nomClasse])) continue;
    requete('INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom) VALUES (?,?,?,?,?)',
            [$eid, $annee['id'], $niveaux[$niv], str_contains($nomClasse, 'F3') ? $f3 : null, $nomClasse]);
    $cid = (int)bd()->lastInsertId(); $n++;
    $source = ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$modele['id'], $annee['id'], $nomClasse]);
    if ($source) {
      // Collège : mêmes matières, coefficients, groupes et ordre que Champagnat (sans les professeurs)
      requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage, actif)
               SELECT ?, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage, actif FROM classe_matieres WHERE classe_id = ?', [$cid, $source['id']]);
    } else {
      // Série F3 : matières de la liste du personnel, coefficients à confirmer
      $ordre = 0;
      foreach ([['FRANCAIS', 'Lettres'], ['ANGLAIS', 'Lettres'], ['MATHS', 'Sciences'], ['PCT', 'Sciences'], ['TECHNO', 'Technique'],
                ['ELECTROTECH', 'Technique'], ['SCHEMA', 'Technique'], ['MEL', 'Technique'], ['TP', 'Technique'], ['CONDUITE', 'Autres']] as [$c, $g])
        requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage) VALUES (?,?,1,1,?,?)',
                [$cid, $mat($c), $g, ++$ordre]);
    }
  }
  $bilan[] = "$nom ($ville) : $n classe(s)";
}
// Espagnol enseigné hors 3e (d'après la liste du personnel) : coef 2 en 4e, 1 à confirmer en 6e et 5e
$esp = $mat('ESPAGNOL');
foreach ([['AGBOTON', '6e', 1, 1], ['AGBOTON', '5e', 1, 1], ['AGBOTON', '4e', 2, 0], ['SOMO2', '4e', 2, 0]] as [$code, $nomClasse, $coef, $conf]) {
  $c = ligne('SELECT c.id FROM classes c JOIN ecoles e ON e.id = c.ecole_id WHERE e.code = ? AND c.annee_id = ? AND c.nom = ?', [$code, $annee['id'], $nomClasse]);
  if ($c && !ligne('SELECT id FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$c['id'], $esp]))
    requete("INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, groupe_bulletin, ordre_affichage) VALUES (?,?,?,?,'Lettres',7)",
            [$c['id'], $esp, $coef, $conf]);
}
info('  Secondaires FVPT : ' . implode(', ', $bilan) . '.');
