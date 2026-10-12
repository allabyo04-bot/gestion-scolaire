<?php
// 020 — Réinitialisation du compte « theo » avant sa remise au Père Théodore :
// mot de passe provisoire (seule sa version chiffrée figure ici), changement obligatoire
// à la première connexion, fermeture de toutes les sessions ouvertes sur ce compte.
$u = ligne("SELECT id FROM utilisateurs WHERE identifiant = 'theo'");
if (!$u) { info('  Compte theo introuvable : rien à faire.'); return; }
requete('UPDATE utilisateurs SET mot_de_passe_hash = ?, doit_changer_mdp = 1, tentatives_echouees = 0, bloque_jusqu_a = NULL WHERE id = ?',
        ['$2y$10$OdxJ1ZuBr20txm6nHMv0F.dWYCiJ01nyYnhmrvrxKxQyA0BjOUpf2', $u['id']]);
requete('UPDATE jetons SET revoque = 1 WHERE utilisateur_id = ?', [$u['id']]);
journaliser('REINITIALISATION_MDP', 'utilisateurs', (int)$u['id'], 'theo : mot de passe provisoire avant remise au Père Théodore', null, null, []);
info('  Compte theo réinitialisé : mot de passe provisoire, changement obligatoire à la première connexion.');
