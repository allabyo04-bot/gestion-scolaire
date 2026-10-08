<?php
// =====================================================================
//  004 — Chargement des élèves 2026-2027 du réseau FVPT
//  Source : listes transmises par les écoles (sql/donnees/eleves_2026_2027.json)
//  Mêmes contrôles que l'import manuel (fonction analyser_ligne).
//  Différence : un numéro Educmaster invalide n'empêche pas le chargement,
//  l'élève est enregistré sans numéro (à compléter dans sa fiche).
//  Les élèves déjà inscrits cette année ne sont pas touchés.
// =====================================================================
$api = is_dir(__DIR__ . '/../../html/api') ? __DIR__ . '/../../html/api' : __DIR__ . '/../../api';
require_once $api . '/routes/parametres.php';
require_once $api . '/routes/eleves.php';

$annee = ligne("SELECT * FROM annees_scolaires WHERE libelle = '2026-2027'");
if (!$annee) { info('  Année 2026-2027 absente : aucun élève chargé.'); return; }
$donnees = json_decode(file_get_contents(__DIR__ . '/../donnees/eleves_2026_2027.json'), true);

$bilan = ['charges' => 0, 'sans_numero' => 0, 'deja' => 0, 'ignores' => 0, 'classes_absentes' => []];
bd()->beginTransaction();
try {
  foreach ($donnees as $codeEcole => $classes) {
    $ecole = ligne('SELECT id FROM ecoles WHERE code = ?', [$codeEcole]);
    if (!$ecole) { $bilan['classes_absentes'][] = "école $codeEcole"; continue; }
    foreach ($classes as $nomClasse => $eleves) {
      $classe = ligne('SELECT id FROM classes WHERE ecole_id = ? AND annee_id = ? AND nom = ?', [$ecole['id'], $annee['id'], $nomClasse]);
      if (!$classe) { $bilan['classes_absentes'][] = "$codeEcole $nomClasse"; continue; }
      foreach ($eleves as $l) {
        $r = analyser_ligne($l, $annee);
        // Numéro Educmaster invalide : on charge quand même l'élève, sans numéro
        if ($r['statut'] === 'ERREUR' && count($r['erreurs']) === 1 && str_starts_with($r['erreurs'][0], 'Numéro Educmaster')) {
          $l['educmaster'] = '';
          $r = analyser_ligne($l, $annee);
          $bilan['sans_numero']++;
        }
        if ($r['statut'] === 'DEJA_INSCRIT') { $bilan['deja']++; continue; }
        if ($r['statut'] === 'ERREUR') { $bilan['ignores']++; continue; }
        if ($r['statut'] === 'NOUVEAU') {
          requete('INSERT INTO eleves (educmaster, nom, prenoms, sexe, date_naissance, lieu_naissance, matricule) VALUES (?,?,?,?,?,?,?)',
                  [$r['educmaster'], $r['nom'], $r['prenoms'], $r['sexe'], $r['date_naissance'], $r['lieu_naissance'],
                   nouveau_matricule((int)$ecole['id'], $annee)]);
          $r['eleve_id'] = (int)bd()->lastInsertId();
        }
        requete('INSERT INTO inscriptions (eleve_id, classe_id, ecole_id, annee_id, date_inscription) VALUES (?,?,?,?,CURDATE())',
                [$r['eleve_id'], $classe['id'], $ecole['id'], $annee['id']]);
        $bilan['charges']++;
      }
    }
  }
  bd()->commit();
} catch (Throwable $e) {
  bd()->rollBack();
  throw $e;
}
journaliser('IMPORT', 'eleves', null, "Chargement initial FVPT 2026-2027 : {$bilan['charges']} élève(s) inscrit(s)", null, null, []);
info("  Élèves : {$bilan['charges']} inscrits ({$bilan['sans_numero']} sans numéro Educmaster valide), "
   . "{$bilan['deja']} déjà inscrits, {$bilan['ignores']} écartés.");
if ($bilan['classes_absentes']) info('  Classes introuvables : ' . implode(', ', $bilan['classes_absentes']));
