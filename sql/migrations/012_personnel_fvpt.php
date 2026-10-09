<?php
// =====================================================================
//  012 — Personnel 2026-2027 du réseau FVPT (d'après les documents des écoles)
//  - Comptes créés VERROUILLÉS : aucun mot de passe connu. La direction les
//    active en imprimant les codes d'accès (onglet Comptes).
//  - Secondaire : matières de chaque classe + professeur (coefficient 1 « à confirmer »).
//  - Primaire : titulaire = professeur principal de la classe.
//  Ce qui existe déjà (compte, professeur choisi à la main) n'est jamais écrasé.
// =====================================================================
$annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) { info('  Année 2026-2027 absente : personnel non chargé.'); return; }
$D = json_decode(file_get_contents(__DIR__ . '/../donnees/personnel_2026_2027.json'), true);

$slug = fn($t) => strtolower(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $t)));
$bilan = ['comptes' => 0, 'existants' => 0, 'matieres_classes' => 0, 'profs_affectes' => 0, 'titulaires' => 0, 'conflits' => 0];

foreach ($D as $code => $d) {
  $ecole = ligne('SELECT id FROM ecoles WHERE code = ?', [$code]);
  if (!$ecole) continue;
  $eid = (int)$ecole['id'];
  $ids = [];   // « NOM Prénoms » → id utilisateur

  // ---- Comptes
  foreach ($d['comptes'] as $c) {
    $cle = $c['nom'] . ' ' . $c['prenoms'];
    $exist = ligne('SELECT id FROM utilisateurs WHERE ecole_id = ? AND UPPER(nom) = UPPER(?) AND UPPER(prenoms) = UPPER(?)', [$eid, $c['nom'], $c['prenoms']]);
    if ($exist) { $ids[$cle] = (int)$exist['id']; $bilan['existants']++; continue; }
    $prenom = '';
    foreach (preg_split('/\s+/', $c['prenoms']) as $p) if (!str_contains($p, '.')) { $prenom = $p; break; }
    $base = ($slug($prenom ?: $c['prenoms']) ?: 'x') . '.' . $slug($c['nom']);
    $ident = $base; $n = 1;
    while (ligne('SELECT id FROM utilisateurs WHERE identifiant = ?', [$ident])) $ident = $base . (++$n);
    requete('INSERT INTO utilisateurs (ecole_id, role, nom, prenoms, telephone, identifiant, mot_de_passe_hash, doit_changer_mdp) VALUES (?,?,?,?,?,?,?,1)',
            [$eid, $c['role'], mb_strtoupper($c['nom']), $c['prenoms'], $c['telephone'], $ident, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
    $ids[$cle] = (int)bd()->lastInsertId();
    $bilan['comptes']++;
  }

  // ---- Secondaire : matières des classes et professeurs
  $poses = [];   // classe_matiere_id déjà attribuée par ce chargement
  foreach ($d['affectations'] as [$prof, $mat, $classes]) {
    $m = ligne('SELECT id FROM matieres WHERE libelle = ? OR libelle_court = ?', [$mat, $mat]);
    if (!$m || !isset($ids[$prof])) continue;
    foreach ($classes as $nomClasse) {
      $cl = ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$eid, $annee['id'], $nomClasse]);
      if (!$cl) continue;
      $cm = ligne('SELECT * FROM classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$cl['id'], $m['id']]);
      if (!$cm) {
        $ordre = (int)ligne('SELECT COALESCE(MAX(ordre_affichage),0) + 1 AS o FROM classe_matieres WHERE classe_id = ?', [$cl['id']])['o'];
        requete('INSERT INTO classe_matieres (classe_id, matiere_id, coefficient, coef_a_confirmer, professeur_id, ordre_affichage) VALUES (?,?,1,1,?,?)',
                [$cl['id'], $m['id'], $ids[$prof], $ordre]);
        $poses[(int)bd()->lastInsertId()] = true;
        $bilan['matieres_classes']++; $bilan['profs_affectes']++;
      } elseif (!$cm['professeur_id'] || isset($poses[(int)$cm['id']])) {
        if (isset($poses[(int)$cm['id']]) && (int)$cm['professeur_id'] !== $ids[$prof]) $bilan['conflits']++;   // deux professeurs cités : le plus précis l'emporte
        requete('UPDATE classe_matieres SET professeur_id = ?, actif = 1 WHERE id = ?', [$ids[$prof], $cm['id']]);
        if (!isset($poses[(int)$cm['id']])) $bilan['profs_affectes']++;
        $poses[(int)$cm['id']] = true;
      }
    }
  }

  // ---- Primaire : titulaire de la classe
  foreach ($d['titulaires'] as $nomClasse => $prof) {
    if (!isset($ids[$prof])) continue;
    $st = requete('UPDATE classes SET prof_principal_id = ? WHERE ecole_id = ? AND annee_id = ? AND nom = ? AND prof_principal_id IS NULL',
                  [$ids[$prof], $eid, $annee['id'], $nomClasse]);
    $bilan['titulaires'] += $st->rowCount();
  }
}
journaliser('CREATION', 'utilisateurs', null, "Chargement du personnel FVPT : {$bilan['comptes']} compte(s), {$bilan['matieres_classes']} matière(s) de classe", null, null, []);
info("  Personnel : {$bilan['comptes']} compte(s) créé(s) ({$bilan['existants']} déjà présent(s)), {$bilan['matieres_classes']} matière(s) de classe, "
   . "{$bilan['profs_affectes']} affectation(s), {$bilan['titulaires']} titulaire(s), {$bilan['conflits']} doublon(s) d'affectation résolu(s).");
