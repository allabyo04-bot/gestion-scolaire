<?php
// Utilisateur connecté pour la requête en cours
$UTILISATEUR = null;

const ROLES_ADMIN_ECOLE = ['SUPER_ADMIN', 'DIRECTRICE'];

function lire_jeton(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
  }
  if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m)) return $m[1];
  // Certains hébergements suppriment l'en-tête Authorization : en-tête de secours
  $x = $_SERVER['HTTP_X_JETON'] ?? '';
  return preg_match('/^[a-f0-9]{64}$/i', $x) ? $x : null;
}

function profil_public(array $u): array {
  $ecole = $u['ecole_id'] ? ligne('SELECT id, code, nom_officiel, ville FROM ecoles WHERE id = ?', [$u['ecole_id']]) : null;
  return [
    'id' => (int)$u['id'], 'nom' => $u['nom'], 'prenoms' => $u['prenoms'],
    'role' => $u['role'], 'identifiant' => $u['identifiant'],
    'ecole' => $ecole, 'doit_changer_mdp' => (bool)$u['doit_changer_mdp'],
  ];
}

function connexion(string $identifiant, string $mdp): array {
  $u = ligne('SELECT * FROM utilisateurs WHERE identifiant = ?', [$identifiant]);
  $message = 'Identifiant ou mot de passe incorrect.';

  if (!$u) {
    password_verify($mdp, '$2y$10$abcdefghijklmnopqrstuuJ8i0eXyZ0vZ1k5mF3m0c9QwZr6yH2a'); // temps constant
    journaliser('ECHEC_CONNEXION', 'utilisateurs', null, 'Identifiant inconnu', null, null, [], $identifiant);
    erreur($message, 401);
  }
  if (!$u['actif']) {
    journaliser('ECHEC_CONNEXION', 'utilisateurs', $u['id'], 'Compte désactivé', null, null, $u, $identifiant);
    erreur('Ce compte est désactivé. Contactez la direction.', 403);
  }
  if ($u['bloque_jusqu_a'] && strtotime($u['bloque_jusqu_a']) > time()) {
    journaliser('ECHEC_CONNEXION', 'utilisateurs', $u['id'], 'Compte temporairement bloqué', null, null, $u, $identifiant);
    erreur('Trop de tentatives. Réessayez après ' . date('H:i', strtotime($u['bloque_jusqu_a'])) . '.', 423);
  }
  if (!password_verify($mdp, $u['mot_de_passe_hash'])) {
    $n = $u['tentatives_echouees'] + 1;
    $bloque = $n >= MAX_TENTATIVES ? date('Y-m-d H:i:s', time() + BLOCAGE_MINUTES * 60) : null;
    requete('UPDATE utilisateurs SET tentatives_echouees = ?, bloque_jusqu_a = ? WHERE id = ?',
            [$bloque ? 0 : $n, $bloque, $u['id']]);
    journaliser('ECHEC_CONNEXION', 'utilisateurs', $u['id'],
                $bloque ? 'Mot de passe incorrect — compte bloqué' : "Mot de passe incorrect ($n)", null, null, $u, $identifiant);
    erreur($message, 401);
  }

  requete('UPDATE utilisateurs SET tentatives_echouees = 0, bloque_jusqu_a = NULL, derniere_connexion = NOW() WHERE id = ?', [$u['id']]);
  $jeton = bin2hex(random_bytes(32));
  requete('INSERT INTO jetons (utilisateur_id, empreinte, expire_le, adresse_ip) VALUES (?,?,?,?)', [
    $u['id'], hash('sha256', $jeton),
    date('Y-m-d H:i:s', time() + DUREE_JETON_HEURES * 3600), $_SERVER['REMOTE_ADDR'] ?? null,
  ]);
  journaliser('CONNEXION', 'utilisateurs', $u['id'], 'Connexion réussie', null, null, $u);
  return ['jeton' => $jeton, 'utilisateur' => profil_public($u)];
}

// Vérifie le jeton ; à appeler au début de toute route protégée
function authentifier(): array {
  global $UTILISATEUR;
  $jeton = lire_jeton();
  if (!$jeton) erreur('Vous devez être connecté.', 401);
  $j = ligne('SELECT j.id AS jeton_id, u.* FROM jetons j JOIN utilisateurs u ON u.id = j.utilisateur_id
              WHERE j.empreinte = ? AND j.revoque = 0 AND j.expire_le > NOW()', [hash('sha256', $jeton)]);
  if (!$j || !$j['actif']) erreur('Session expirée. Veuillez vous reconnecter.', 401);
  // Expiration glissante : la session reste ouverte tant qu'on travaille
  requete('UPDATE jetons SET derniere_activite = NOW(), expire_le = ? WHERE id = ?',
          [date('Y-m-d H:i:s', time() + DUREE_JETON_HEURES * 3600), $j['jeton_id']]);
  $UTILISATEUR = $j;
  return $j;
}

function exiger_role(string ...$roles): void {
  global $UTILISATEUR;
  if (!in_array($UTILISATEUR['role'], $roles, true)) erreur("Vous n'avez pas les droits pour cette action.", 403);
}

function est_super_admin(): bool {
  global $UTILISATEUR;
  return $UTILISATEUR['role'] === 'SUPER_ADMIN';
}

function valider_mdp(string $mdp): void {
  if (mb_strlen($mdp) < LONGUEUR_MIN_MDP) erreur('Le mot de passe doit contenir au moins ' . LONGUEUR_MIN_MDP . ' caractères.');
  if (!preg_match('/[0-9]/', $mdp) || !preg_match('/[a-zA-Z]/', $mdp))
    erreur('Le mot de passe doit contenir des lettres et des chiffres.');
}
