<?php
// Règles d'accès : chaque utilisateur (sauf super admin) ne voit que son école,
// et un professeur ne voit que les matières/classes qui lui sont affectées.

function verifier_ecole(int $ecole_id): void {
  global $UTILISATEUR;
  if (est_super_admin()) return;
  if ((int)$UTILISATEUR['ecole_id'] !== $ecole_id) erreur('Accès refusé : cette école ne vous est pas rattachée.', 403);
}

function classe_matiere(int $id): array {
  $cm = ligne('SELECT cm.*, c.ecole_id, c.annee_id, c.nom AS classe_nom, c.prof_principal_id, m.libelle AS matiere
               FROM classe_matieres cm JOIN classes c ON c.id = cm.classe_id JOIN matieres m ON m.id = cm.matiere_id
               WHERE cm.id = ?', [$id]);
  if (!$cm) erreur('Matière de classe introuvable.', 404);
  verifier_ecole((int)$cm['ecole_id']);
  return $cm;
}

function classe(int $id): array {
  $c = ligne('SELECT c.*, n.cycle, n.libelle AS niveau FROM classes c JOIN niveaux n ON n.id = c.niveau_id WHERE c.id = ?', [$id]);
  if (!$c) erreur('Classe introuvable.', 404);
  verifier_ecole((int)$c['ecole_id']);
  return $c;
}

function periode(int $id): array {
  $p = ligne('SELECT * FROM periodes WHERE id = ?', [$id]);
  if (!$p) erreur('Période introuvable.', 404);
  verifier_ecole((int)$p['ecole_id']);
  return $p;
}

function evaluation(int $id): array {
  $e = ligne('SELECT * FROM evaluations WHERE id = ?', [$id]);
  if (!$e) erreur('Évaluation introuvable.', 404);
  $e['cm'] = classe_matiere((int)$e['classe_matiere_id']);
  $e['periode'] = periode((int)$e['periode_id']);
  return $e;
}

// Le professeur ne peut agir que sur ses propres matières ; directrice et super admin sur toute l'école
function verifier_droit_saisie(array $cm): void {
  global $UTILISATEUR;
  if (in_array($UTILISATEUR['role'], ROLES_ADMIN_ECOLE, true)) return;
  if ($UTILISATEUR['role'] === 'PROFESSEUR' && (int)$cm['professeur_id'] === (int)$UTILISATEUR['id']) return;
  erreur("Vous n'êtes pas le professeur de cette matière dans cette classe.", 403);
}

function verifier_periode_ouverte(array $p): void {
  if ($p['statut'] !== 'OUVERTE') erreur('Cette période est clôturée : aucune modification possible.', 409);
}

// Droit de consulter les résultats d'une classe
function verifier_consultation_classe(array $classe): void {
  global $UTILISATEUR;
  if (in_array($UTILISATEUR['role'], ['SUPER_ADMIN', 'DIRECTRICE', 'SECRETARIAT'], true)) return;
  if ($UTILISATEUR['role'] === 'PROFESSEUR' && (int)$classe['prof_principal_id'] === (int)$UTILISATEUR['id']) return;
  erreur("Vous n'avez pas accès aux résultats de cette classe.", 403);
}
