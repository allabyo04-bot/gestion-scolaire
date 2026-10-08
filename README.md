# Gestion scolaire multi-écoles

Réseau d'écoles du Père Théodore (Kandi, Parakou, N'Dali…). Maternelle à terminale.

## Contenu du dépôt

| Dossier | Rôle |
|---|---|
| `front/` | Code source du site (React). Construit automatiquement par Railway. |
| `api/` | API PHP (connexion, notes, élèves, paramètres, journal). |
| `sql/migrations/` | Structure de la base, appliquée automatiquement au démarrage. |
| `docker/` | Configuration du serveur (Apache, PHP, démarrage). |
| `Dockerfile` | Recette de construction utilisée par Railway. |

## Déploiement sur Railway

1. Projet Railway relié à ce dépôt GitHub (le `Dockerfile` est détecté tout seul).
2. Ajouter une base **MySQL** dans le même projet.
3. Dans le service de l'application, variable : `MYSQL_URL` = `${{MySQL.MYSQL_URL}}`
4. Facultatif : `ADMIN_MOT_DE_PASSE` = mot de passe provisoire du premier compte
   (sinon : `Bienvenue2026`). À définir **avant** le premier démarrage réussi.
5. Settings > Networking > Generate Domain.

Au démarrage, `sql/migrer.php` applique les migrations manquantes et crée le compte
`theo` s'il n'existe aucun administrateur. Le mot de passe provisoire doit être changé
à la première connexion.

## Mettre à jour

    git add .
    git commit -m "Description de la modification"
    git push

Railway reconstruit et redéploie automatiquement.

## Modifier la base de données

Ne jamais modifier `001_structure_initiale.sql` une fois en production.
Ajouter un nouveau fichier numéroté : `sql/migrations/002_bulletins.sql`, etc.
Il sera appliqué une seule fois, au prochain démarrage.

## Travailler en local (facultatif)

API : PHP 8.2+ et MySQL/MariaDB. Variables `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
(par défaut : localhost, gestion_scolaire, root, mot de passe vide).
Site : `cd front`, `npm install`, `npm run dev`.
