<?php
// =====================================================================
//  023 — Quatre écoles primaires du Réseau des écoles FVPT (nov. 2026)
//  CSP Pierre YERIMA (Kandi), EPP MAMA ALAINE (Banikoara), EPP TATA ALAINE (Kérou),
//  EPP ESTEBAN (Sirarou). Une classe par niveau (les cours multiples sont séparés),
//  3 trimestres, entête et logo des Écoles FVPT. Les comptes du personnel ne sont PAS
//  créés ici (données personnelles) : ils sont ajoutés par SQL directement en base.
// =====================================================================
$annee = ligne("SELECT id FROM annees_scolaires WHERE libelle = '2026-2027'");
$groupe = ligne("SELECT id, logo FROM groupes WHERE code = 'FVPT'");
if (!$annee || !$groupe) { info('  Année 2026-2027 ou groupe FVPT introuvable : rien à faire.'); return; }

$PRIMAIRE = ['CI' => 'CI', 'CP' => 'CP', 'CE1' => 'CE1', 'CE2' => 'CE2', 'CM1' => 'CM1', 'CM2' => 'CM2'];
$ECOLES = [
  'YERIMA'    => ['CSP Pierre YERIMA', 'Kandi', ['MAT1' => 'Maternelle 1', 'MAT2' => 'Maternelle 2'] + $PRIMAIRE],
  'BANIKOARA' => ['EPP MAMA ALAINE', 'Banikoara', ['MAT2' => 'Maternelle 2'] + $PRIMAIRE],
  'KEROU'     => ['EPP TATA ALAINE', 'Kérou', $PRIMAIRE],
  'SIRAROU'   => ['EPP ESTEBAN', 'Sirarou', $PRIMAIRE],
];
$DATES = [1 => ['2026-09-14', '2026-12-18'], 2 => ['2027-01-04', '2027-03-26'], 3 => ['2027-04-12', '2027-07-16']];
$niveaux = array_column(lignes('SELECT id, code FROM niveaux'), 'id', 'code');
$bilan = [];

foreach ($ECOLES as $code => [$nom, $ville, $classes]) {
  $e = ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
  if (!$e) {
    requete('INSERT INTO ecoles (groupe_id, code, nom_officiel, ville, entete_ligne1, entete_ligne2, entete_ligne3, site_web, titre_signataire)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$groupe['id'], $code, $nom, $ville, 'Ministère des Enseignements maternel et primaire',
             'Fondation Vie Pour Tous, Vida para todos', 'Direction nationale des écoles FVPT', 'www.fondation-viepourtous.org', 'Le Directeur']);
    $e = ['id' => (int)bd()->lastInsertId()];
  }
  $eid = (int)$e['id'];
  // Trimestres, règles de l'année et nombre d'évaluations par défaut
  if (!ligne('SELECT id FROM periodes WHERE ecole_id = ? AND annee_id = ?', [$eid, $annee['id']])) {
    requete("INSERT IGNORE INTO parametres_annee (ecole_id, annee_id, type_periode) VALUES (?, ?, 'TRIMESTRE')", [$eid, $annee['id']]);
    foreach (['1er trimestre', '2e trimestre', '3e trimestre'] as $i => $lib)
      requete('INSERT INTO periodes (ecole_id, annee_id, numero, libelle, date_debut, date_fin) VALUES (?,?,?,?,?,?)',
              [$eid, $annee['id'], $i + 1, $lib, $DATES[$i + 1][0], $DATES[$i + 1][1]]);
  }
  if (!ligne('SELECT id FROM config_evaluation_defaut WHERE ecole_id = ? AND niveau_id IS NULL', [$eid]))
    requete('INSERT INTO config_evaluation_defaut (ecole_id, niveau_id, nb_interros, nb_devoirs) VALUES (?, NULL, 3, 2)', [$eid]);
  // Signataire des bulletins : « Le Directeur »
  if (!ligne('SELECT ecole_id FROM parametres_bulletin WHERE ecole_id = ?', [$eid]))
    requete("INSERT INTO parametres_bulletin (ecole_id, appreciations, seuil_felicitations, seuil_encouragements, seuil_tableau, titre_signataire, note_bas)
             VALUES (?, '[[18,\"Excellent\"],[16,\"Très bien\"],[14,\"Bien\"],[12,\"Assez bien\"],[10,\"Passable\"],[8,\"Médiocre\"],[0,\"Insuffisant\"]]', 16, 14, 12, 'Le Directeur',
             'NB : Conservez précieusement ce bulletin. Il ne sera délivré aucun duplicata.')", [$eid]);
  // Logo des Écoles FVPT
  if ($groupe['logo'] && !ligne("SELECT id FROM ecole_images WHERE ecole_id = ? AND type = 'LOGO'", [$eid]))
    requete("INSERT INTO ecole_images (ecole_id, type, donnees) VALUES (?, 'LOGO', ?)", [$eid, $groupe['logo']]);
  // Classes
  $n = 0;
  foreach ($classes as $niv => $nomClasse)
    if (!ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$eid, $annee['id'], $nomClasse])) {
      requete('INSERT INTO classes (ecole_id, annee_id, niveau_id, nom) VALUES (?,?,?,?)', [$eid, $annee['id'], $niveaux[$niv], $nomClasse]);
      $n++;
    }
  $bilan[] = "$nom ($ville) : $n classe(s)";
}
info('  Écoles primaires FVPT : ' . implode(', ', $bilan) . '.');
