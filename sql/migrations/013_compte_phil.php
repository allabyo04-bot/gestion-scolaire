<?php
// 013 — Compte d'administrateur général pour Phil (ALLABY Odilon), créé verrouillé.
// Activation : Théodore (ou un autre administrateur) clique sur « Nouveau mot de passe » dans Comptes.
if (ligne("SELECT id FROM utilisateurs WHERE identifiant = 'phil' OR (role = 'SUPER_ADMIN' AND nom = 'ALLABY')")) { info('  Compte de Phil déjà présent.'); return; }
requete("INSERT INTO utilisateurs (ecole_id, role, nom, prenoms, identifiant, mot_de_passe_hash, doit_changer_mdp)
         VALUES (NULL, 'SUPER_ADMIN', 'ALLABY', 'Odilon', 'phil', ?, 1)", [password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
journaliser('CREATION', 'utilisateurs', (int)bd()->lastInsertId(), 'Compte administrateur général créé : phil', null, null, []);
info("  Compte administrateur général « phil » créé (verrouillé).");
