<?php
// =====================================================================
//  MISE À JOUR AUTOMATIQUE DE LA BASE — exécuté à chaque démarrage
//  1. attend que la base soit joignable
//  2. applique, dans l'ordre, les fichiers sql/migrations/*.sql pas encore appliqués
//  3. crée le compte de l'administrateur général s'il n'existe aucun
//  Sans danger s'il est relancé : ce qui est fait n'est jamais refait.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = is_dir(__DIR__ . '/../html/api') ? __DIR__ . '/../html/api' : __DIR__ . '/../api';
require $racine . '/config.php';
require $racine . '/lib/db.php';

function info(string $m): void { fwrite(STDOUT, '[base] ' . $m . PHP_EOL); }

// 1. Attente de la base (au premier déploiement, MySQL démarre parfois après l'application)
for ($essai = 1; ; $essai++) {
  try { bd()->query('SELECT 1'); break; }
  catch (Throwable $e) {
    if ($essai >= 30) { info('Base injoignable : ' . $e->getMessage()); exit(1); }
    info("Base pas encore prête, nouvel essai dans 3 s ($essai/30)…");
    sleep(3);
  }
}

// 2. Migrations
bd()->exec('CREATE TABLE IF NOT EXISTS migrations_appliquees (
  fichier VARCHAR(150) PRIMARY KEY, appliquee_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$faites = array_flip(bd()->query('SELECT fichier FROM migrations_appliquees')->fetchAll(PDO::FETCH_COLUMN));
$fichiers = glob(__DIR__ . '/migrations/*.sql');
sort($fichiers);
foreach ($fichiers as $f) {
  $nom = basename($f);
  if (isset($faites[$nom])) continue;
  info("Application de {$nom}…");
  $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($f));       // retire les commentaires
  $instructions = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)));
  try {
    foreach ($instructions as $i) {
      // Une instruction précédée de /*facultatif*/ peut échouer sans bloquer
      // (ex. supprimer une contrainte déjà supprimée lors d'un essai interrompu)
      if (str_starts_with($i, '/*facultatif*/')) {
        try { bd()->exec($i); } catch (Throwable $e) { info('  (instruction facultative ignorée : ' . $e->getMessage() . ')'); }
        continue;
      }
      bd()->exec($i);
    }
  } catch (Throwable $e) {
    info("ÉCHEC sur $nom : " . $e->getMessage());
    info('Instruction en cause : ' . mb_substr($i, 0, 200));
    exit(1);
  }
  requete('INSERT INTO migrations_appliquees (fichier) VALUES (?)', [$nom]);
  info("$nom appliquée (" . count($instructions) . ' instructions).');
}

// 3. Premier compte
if (!ligne("SELECT id FROM utilisateurs WHERE role = 'SUPER_ADMIN' LIMIT 1")) {
  $mdp = getenv('ADMIN_MOT_DE_PASSE') ?: 'Bienvenue2026';
  requete("INSERT INTO utilisateurs (ecole_id, role, nom, prenoms, identifiant, mot_de_passe_hash, doit_changer_mdp)
           VALUES (NULL, 'SUPER_ADMIN', 'SOUMÉ', 'Théodore', 'theo', ?, 1)", [password_hash($mdp, PASSWORD_DEFAULT)]);
  info("Compte administrateur créé : identifiant « theo », mot de passe provisoire à changer à la première connexion.");
}
info('Base à jour.');
