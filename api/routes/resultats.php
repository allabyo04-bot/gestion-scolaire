<?php
function r_resultats_classe() {
  $c = classe(entier('classe_id'));
  verifier_consultation_classe($c);
  $p = periode(entier('periode_id'));
  repondre(['classe' => $c['nom'], 'periode' => $p['libelle']] + resultats_classe($c['id'], $p['id']));
}

function r_journal_liste() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $where = []; $p = [];
  if (!est_super_admin()) { $where[] = 'j.ecole_id = ?'; $p[] = $UTILISATEUR['ecole_id']; }
  elseif ($e = entier('ecole_id', false)) { $where[] = 'j.ecole_id = ?'; $p[] = $e; }
  if ($u = entier('utilisateur_id', false)) { $where[] = 'j.utilisateur_id = ?'; $p[] = $u; }
  if ($a = champ('action', false)) { $where[] = 'j.action = ?'; $p[] = strtoupper($a); }
  if ($d = champ('du', false)) { $where[] = 'j.cree_le >= ?'; $p[] = "$d 00:00:00"; }
  if ($f = champ('au', false)) { $where[] = 'j.cree_le <= ?'; $p[] = "$f 23:59:59"; }
  $limite = min(500, entier('limite', false) ?? 100);
  $sql = 'SELECT j.id, j.cree_le, j.action, j.table_cible, j.enregistrement_id, j.description,
                 j.valeurs_avant, j.valeurs_apres, j.adresse_ip, COALESCE(u.identifiant, j.identifiant_saisi) AS identifiant,
                 CONCAT(u.nom, " ", u.prenoms) AS utilisateur
          FROM journal_audit j LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id'
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY j.id DESC LIMIT $limite";
  repondre(lignes($sql, $p));
}
