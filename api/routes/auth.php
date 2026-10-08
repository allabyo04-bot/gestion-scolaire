<?php
function r_auth_connexion() {
  repondre(connexion((string)champ('identifiant'), (string)champ('mot_de_passe')));
}

function r_auth_moi() {
  global $UTILISATEUR;
  repondre(profil_public($UTILISATEUR));
}

function r_auth_deconnexion() {
  requete('UPDATE jetons SET revoque = 1 WHERE empreinte = ?', [hash('sha256', lire_jeton())]);
  journaliser('DECONNEXION', 'utilisateurs', $GLOBALS['UTILISATEUR']['id']);
  repondre();
}

function r_auth_changer_mdp() {
  global $UTILISATEUR;
  $ancien = (string)champ('ancien_mot_de_passe');
  $nouveau = (string)champ('nouveau_mot_de_passe');
  if (!password_verify($ancien, $UTILISATEUR['mot_de_passe_hash'])) erreur('Ancien mot de passe incorrect.');
  if ($ancien === $nouveau) erreur("Le nouveau mot de passe doit être différent de l'ancien.");
  valider_mdp($nouveau);
  requete('UPDATE utilisateurs SET mot_de_passe_hash = ?, doit_changer_mdp = 0 WHERE id = ?',
          [password_hash($nouveau, PASSWORD_DEFAULT), $UTILISATEUR['id']]);
  journaliser('CHANGEMENT_MDP', 'utilisateurs', $UTILISATEUR['id'], 'Mot de passe modifié par l\'utilisateur');
  repondre();
}
