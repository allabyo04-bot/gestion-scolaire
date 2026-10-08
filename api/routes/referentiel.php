<?php
// Listes de référence pour l'interface (filtrées par école selon le rôle)
function ecole_demandee(): ?int {
  global $UTILISATEUR;
  if (!est_super_admin()) return (int)$UTILISATEUR['ecole_id'];
  return entier('ecole_id', false);
}

function r_ref_ecoles() {
  global $UTILISATEUR;
  if (est_super_admin()) repondre(lignes('SELECT id, code, nom_officiel, ville FROM ecoles WHERE actif = 1 ORDER BY nom_officiel'));
  repondre(lignes('SELECT id, code, nom_officiel, ville FROM ecoles WHERE id = ?', [$UTILISATEUR['ecole_id']]));
}

function r_ref_periodes() {
  $e = ecole_demandee();
  if (!$e) erreur('Choisissez une école.');
  repondre(lignes('SELECT p.id, p.numero, p.libelle, p.statut FROM periodes p
                   JOIN annees_scolaires a ON a.id = p.annee_id AND a.en_cours = 1
                   WHERE p.ecole_id = ? ORDER BY p.numero', [$e]));
}

function r_ref_classes() {
  global $UTILISATEUR;
  $e = ecole_demandee();
  if (!$e) erreur('Choisissez une école.');
  $sql = 'SELECT c.id, c.nom, n.cycle, c.prof_principal_id,
                 (SELECT COUNT(*) FROM inscriptions i WHERE i.classe_id = c.id AND i.statut = "ACTIF") AS effectif
          FROM classes c JOIN niveaux n ON n.id = c.niveau_id
          JOIN annees_scolaires a ON a.id = c.annee_id AND a.en_cours = 1 WHERE c.ecole_id = ?';
  if ($UTILISATEUR['role'] === 'PROFESSEUR')
    repondre(lignes("$sql AND c.prof_principal_id = ? ORDER BY n.ordre, c.nom", [$e, $UTILISATEUR['id']]));
  repondre(lignes("$sql ORDER BY n.ordre, c.nom", [$e]));
}
