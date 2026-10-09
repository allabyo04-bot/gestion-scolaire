<?php
// Super admin : tous les rôles, toutes les écoles.
// Directrice : secrétariat, comptable et professeurs de SON école.
function roles_creables(): array {
  return est_super_admin() ? ['SUPER_ADMIN','DIRECTRICE','SECRETARIAT','COMPTABLE','PROFESSEUR']
                           : ['SECRETARIAT','COMPTABLE','PROFESSEUR'];
}

function utilisateur_cible(int $id): array {
  global $UTILISATEUR;
  $u = ligne('SELECT * FROM utilisateurs WHERE id = ?', [$id]);
  if (!$u) erreur('Utilisateur introuvable.', 404);
  if (!est_super_admin()) {
    if ((int)$u['ecole_id'] !== (int)$UTILISATEUR['ecole_id'] || !in_array($u['role'], roles_creables(), true))
      erreur("Vous ne pouvez pas gérer ce compte.", 403);
  }
  if ((int)$u['id'] === (int)$UTILISATEUR['id']) erreur('Vous ne pouvez pas modifier votre propre compte ici.', 403);
  return $u;
}

function mdp_provisoire(): string {
  $c = 'abcdefghjkmnpqrstuvwxyz23456789';
  $m = '';
  for ($i = 0; $i < 10; $i++) $m .= $c[random_int(0, strlen($c) - 1)];
  return $m;
}

function r_utilisateurs_liste() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $ecole = est_super_admin() ? entier('ecole_id', false) : (int)$UTILISATEUR['ecole_id'];
  $sql = 'SELECT u.id, u.ecole_id, e.nom_officiel AS ecole, u.role, u.nom, u.prenoms, u.telephone, u.email,
                 u.identifiant, u.actif, u.derniere_connexion, u.doit_changer_mdp
          FROM utilisateurs u LEFT JOIN ecoles e ON e.id = u.ecole_id';
  $g = est_super_admin() ? entier('groupe_id', false) : null;
  if ($ecole) repondre(lignes("$sql WHERE u.ecole_id = ? ORDER BY u.nom", [$ecole]));
  // Vue d'ensemble : les comptes du groupe choisi, plus les administrateurs généraux (sans école)
  repondre($g ? lignes("$sql WHERE e.groupe_id = ? OR u.ecole_id IS NULL ORDER BY e.nom_officiel IS NOT NULL, e.nom_officiel, u.nom", [$g])
              : lignes("$sql ORDER BY e.nom_officiel IS NOT NULL, e.nom_officiel, u.nom"));
}

function r_utilisateurs_creer() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $role = strtoupper((string)champ('role'));
  if (!in_array($role, roles_creables(), true)) erreur("Vous ne pouvez pas créer un compte de ce type.", 403);
  $ecole = $role === 'SUPER_ADMIN' ? null : (est_super_admin() ? entier('ecole_id') : (int)$UTILISATEUR['ecole_id']);
  if ($ecole && !ligne('SELECT id FROM ecoles WHERE id = ?', [$ecole])) erreur('École introuvable.', 404);
  $identifiant = strtolower((string)champ('identifiant'));
  if (!preg_match('/^[a-z0-9._-]{3,60}$/', $identifiant)) erreur('Identifiant : 3 à 60 caractères (lettres, chiffres, point, tiret).');
  if (ligne('SELECT id FROM utilisateurs WHERE identifiant = ?', [$identifiant])) erreur('Cet identifiant est déjà utilisé.');

  $mdp = mdp_provisoire();
  requete('INSERT INTO utilisateurs (ecole_id, role, nom, prenoms, telephone, email, identifiant, mot_de_passe_hash, cree_par)
           VALUES (?,?,?,?,?,?,?,?,?)', [$ecole, $role, mb_strtoupper((string)champ('nom')), (string)champ('prenoms'),
           champ('telephone', false), champ('email', false), $identifiant, password_hash($mdp, PASSWORD_DEFAULT), $UTILISATEUR['id']]);
  $id = (int)bd()->lastInsertId();
  journaliser('CREATION', 'utilisateurs', $id, "Compte $role créé : $identifiant", null,
              ['role' => $role, 'ecole_id' => $ecole, 'identifiant' => $identifiant]);
  // Le mot de passe provisoire n'est affiché qu'une seule fois ; il devra être changé à la 1re connexion
  repondre(['id' => $id, 'identifiant' => $identifiant, 'mot_de_passe_provisoire' => $mdp], 201);
}

function r_utilisateurs_activer() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $u = utilisateur_cible(entier('id'));
  $actif = (int)(bool)champ('actif');
  requete('UPDATE utilisateurs SET actif = ? WHERE id = ?', [$actif, $u['id']]);
  if (!$actif) requete('UPDATE jetons SET revoque = 1 WHERE utilisateur_id = ?', [$u['id']]);
  journaliser($actif ? 'ACTIVATION' : 'DESACTIVATION', 'utilisateurs', $u['id'], $u['identifiant'],
              ['actif' => (int)$u['actif']], ['actif' => $actif]);
  repondre();
}

function r_utilisateurs_reinitialiser_mdp() {
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $u = utilisateur_cible(entier('id'));
  $mdp = mdp_provisoire();
  requete('UPDATE utilisateurs SET mot_de_passe_hash = ?, doit_changer_mdp = 1, tentatives_echouees = 0, bloque_jusqu_a = NULL
           WHERE id = ?', [password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
  requete('UPDATE jetons SET revoque = 1 WHERE utilisateur_id = ?', [$u['id']]);
  journaliser('REINITIALISATION_MDP', 'utilisateurs', $u['id'], $u['identifiant']);
  repondre(['identifiant' => $u['identifiant'], 'mot_de_passe_provisoire' => $mdp]);
}

// Codes d'accès : nouveau mot de passe provisoire pour chaque compte jamais utilisé de l'école
// (affiché une seule fois, pour impression et distribution en main propre)
function r_utilisateurs_generer_acces() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN', 'DIRECTRICE');
  $e = est_super_admin() ? entier('ecole_id') : (int)$UTILISATEUR['ecole_id'];
  $roles = roles_creables();
  $ids = array_map('intval', (array)(champ('ids', false) ?? []));
  $comptes = lignes('SELECT u.*, ec.nom_officiel AS ecole FROM utilisateurs u JOIN ecoles ec ON ec.id = u.ecole_id
                     WHERE u.ecole_id = ? AND u.actif = 1 AND u.derniere_connexion IS NULL AND u.id <> ? ORDER BY u.role, u.nom, u.prenoms', [$e, $UTILISATEUR['id']]);
  $res = [];
  foreach ($comptes as $u) {
    if (!in_array($u['role'], $roles, true)) continue;
    if ($ids && !in_array((int)$u['id'], $ids, true)) continue;
    $mdp = mdp_provisoire();
    requete('UPDATE utilisateurs SET mot_de_passe_hash = ?, doit_changer_mdp = 1, tentatives_echouees = 0, bloque_jusqu_a = NULL WHERE id = ?',
            [password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
    $res[] = ['nom' => $u['nom'], 'prenoms' => $u['prenoms'], 'role' => $u['role'], 'identifiant' => $u['identifiant'], 'mot_de_passe' => $mdp, 'ecole' => $u['ecole']];
  }
  journaliser('REINITIALISATION_MDP', 'utilisateurs', null, count($res) . " code(s) d'accès générés pour impression");
  repondre($res);
}
