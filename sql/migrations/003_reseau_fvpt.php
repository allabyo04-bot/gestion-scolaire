<?php
// =====================================================================
//  003 — Écoles et classes du Réseau des écoles FVPT (année 2026-2027)
//  D'après les documents transmis par les écoles en octobre 2026.
//  Ne crée que ce qui n'existe pas encore : rien de ce qui a déjà été
//  saisi dans le logiciel n'est modifié ni supprimé.
// =====================================================================

$creees = ['ecoles' => 0, 'classes' => 0, 'matieres' => 0];

// ---------------------------------------------------------------- Année scolaire
$annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) {
  $enCours = ligne('SELECT id FROM annees_scolaires WHERE en_cours = 1') ? 0 : 1;
  requete("INSERT INTO annees_scolaires (libelle, date_debut, date_fin, en_cours) VALUES ('2026-2027', '2026-09-14', '2027-07-16', ?)", [$enCours]);
  $annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
} elseif (!ligne('SELECT id FROM annees_scolaires WHERE en_cours = 1')) {
  requete('UPDATE annees_scolaires SET en_cours = 1 WHERE id = ?', [$annee['id']]);
}

// ---------------------------------------------------------------- Écoles
$ENTETE_SECONDAIRE = 'Ministère des Enseignements secondaire, technique et de la formation professionnelle';
$ENTETE_PRIMAIRE   = 'Ministère des Enseignements maternel et primaire';
$ecoles = [
  'KANDI' => ['nom_officiel' => 'CPEG Champagnat', 'ville' => 'Kandi',
              'entete_ligne2' => $ENTETE_SECONDAIRE, 'entete_ligne3' => 'Fondation Vie Pour Tous',
              'nom_directrice' => 'MOUSSA Zaliatou', 'niveau' => 'secondaire',
              'classes' => ['6e' => ['6E'], '5e' => ['5E'], '4e' => ['4E'], '3e' => ['3E'], '2nde D' => ['2NDE', 'D'],
                            '1ère A2' => ['1ERE', 'A2'], '1ère D' => ['1ERE', 'D'], 'Tle A2' => ['TLE', 'A2'], 'Tle D' => ['TLE', 'D']]],
  'SOMO' => ['nom_officiel' => 'CPEG Somo', 'ville' => 'Parakou', 'telephone' => '60 10 41 08 / 60 10 41 21',
             'entete_ligne2' => $ENTETE_SECONDAIRE, 'entete_ligne3' => 'Fondation Vie Pour Tous',
             'nom_directrice' => 'TAMPOUHOUROU Jeanne', 'niveau' => 'secondaire',
             'classes' => ['6e' => ['6E'], '5e' => ['5E'], '4e' => ['4E'], '3e' => ['3E'], '2nde A' => ['2NDE'], '2nde D' => ['2NDE', 'D'],
                           '1ère A' => ['1ERE'], '1ère D' => ['1ERE', 'D'], 'Tle A' => ['TLE'], 'Tle D' => ['TLE', 'D']]],
  'STANDRE' => ['nom_officiel' => 'EP Saint André de Tibona', 'ville' => 'Parakou',
                'entete_ligne2' => $ENTETE_PRIMAIRE, 'entete_ligne3' => 'Fondation Vie Pour Tous',
                'nom_directrice' => 'KPANGON Maia', 'niveau' => 'primaire'],
  'SEGOVIANA' => ['nom_officiel' => 'EP La Ségoviana', 'ville' => 'À préciser',
                  'entete_ligne2' => $ENTETE_PRIMAIRE, 'entete_ligne3' => 'Fondation Vie Pour Tous',
                  'nom_directrice' => 'YAROU Débora', 'niveau' => 'primaire'],
];
$PRIMAIRE = ['Maternelle 1' => ['MAT1'], 'Maternelle 2' => ['MAT2'], 'CI' => ['CI'], 'CP' => ['CP'],
             'CE1' => ['CE1'], 'CE2' => ['CE2'], 'CM1' => ['CM1'], 'CM2' => ['CM2']];

$niveaux = [];
foreach (lignes('SELECT id, code FROM niveaux') as $n) $niveaux[$n['code']] = (int)$n['id'];
$series = [];
foreach (lignes('SELECT id, code FROM series') as $s) $series[$s['code']] = (int)$s['id'];

foreach ($ecoles as $code => $e) {
  $ecole = ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
  if (!$ecole) {
    requete('INSERT INTO ecoles (code, nom_officiel, ville, telephone, entete_ligne1, entete_ligne2, entete_ligne3, nom_directrice, titre_signataire)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$code, $e['nom_officiel'], $e['ville'], $e['telephone'] ?? null, 'République du Bénin',
             $e['entete_ligne2'], $e['entete_ligne3'], $e['nom_directrice'], 'La Directrice']);
    $ecole = ['id' => (int)bd()->lastInsertId()];
    $creees['ecoles']++;
  }
  $eid = (int)$ecole['id'];

  // Découpage en 3 trimestres, si l'école n'en a pas encore pour cette année
  if (!ligne('SELECT id FROM periodes WHERE ecole_id = ? AND annee_id = ?', [$eid, $annee['id']])) {
    requete("INSERT IGNORE INTO parametres_annee (ecole_id, annee_id, type_periode) VALUES (?, ?, 'TRIMESTRE')", [$eid, $annee['id']]);
    foreach (['1er trimestre', '2e trimestre', '3e trimestre'] as $i => $lib)
      requete('INSERT INTO periodes (ecole_id, annee_id, numero, libelle) VALUES (?,?,?,?)', [$eid, $annee['id'], $i + 1, $lib]);
  }
  // Nombre d'interros et de devoirs par défaut (modifiable dans Paramètres)
  if (!ligne('SELECT id FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id IS NULL', [$eid]))
    requete('INSERT INTO config_evaluation_defaut (ecole_id, niveau_id, nb_interros, nb_devoirs) VALUES (?, NULL, 3, 2)', [$eid]);

  // Classes
  foreach ($e['classes'] ?? $PRIMAIRE as $nom => $def) {
    if (ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$eid, $annee['id'], $nom])) continue;
    if (!isset($niveaux[$def[0]])) continue;
    requete('INSERT INTO classes (ecole_id, annee_id, niveau_id, serie_id, nom) VALUES (?,?,?,?,?)',
            [$eid, $annee['id'], $niveaux[$def[0]], isset($def[1]) ? ($series[$def[1]] ?? null) : null, $nom]);
    $creees['classes']++;
  }
}

// ---------------------------------------------------------------- Matières du secondaire
// (les coefficients seront fixés classe par classe ; les matières du primaire viendront avec les bulletins)
$matieres = [['Français', null, 0], ['Anglais', null, 0], ['Espagnol', null, 0], ['Histoire-Géographie', 'Hist-Géo', 0],
             ['Sciences de la vie et de la terre', 'SVT', 0], ['Physique, chimie et technologie', 'PCT', 0],
             ['Mathématiques', 'Maths', 0], ['Philosophie', 'Philo', 0], ['Éducation physique et sportive', 'EPS', 0], ['Conduite', null, 1]];
foreach ($matieres as [$lib, $court, $conduite]) {
  if (ligne('SELECT id FROM matieres WHERE libelle = ? OR (libelle_court IS NOT NULL AND libelle_court = ?)', [$lib, $court ?? $lib])) continue;
  $base = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $court ?? $lib)));
  $codeM = substr($base, 0, 12); $n = 1; $essai = $codeM;
  while (ligne('SELECT id FROM matieres WHERE code = ?', [$essai])) $essai = $codeM . (++$n);
  requete('INSERT INTO matieres (code, libelle, libelle_court, est_conduite) VALUES (?,?,?,?)', [$essai, $lib, $court, $conduite]);
  $creees['matieres']++;
}

journaliser('CREATION', 'ecoles', null,
  "Mise en place du réseau FVPT : {$creees['ecoles']} école(s), {$creees['classes']} classe(s), {$creees['matieres']} matière(s)", null, null, []);
info("  Réseau FVPT : {$creees['ecoles']} école(s), {$creees['classes']} classe(s), {$creees['matieres']} matière(s) créées.");
