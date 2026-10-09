<?php
// =====================================================================
//  016 — Groupes d'écoles (un niveau au-dessus des écoles)
//  - « Réseau des écoles FVPT » : Lycée Champagnat
//  - Nouveau groupe (nom à préciser) : CPEG Somo, EP La Ségoviana, EP Saint André de Tibona
//    → ces trois écoles quittent la Fondation : la mention FVPT est retirée de leurs entêtes
// =====================================================================
bd()->exec("CREATE TABLE IF NOT EXISTS groupes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL UNIQUE,
  nom        VARCHAR(150) NOT NULL,
  sigle      VARCHAR(40)  NULL,                  -- utilisé en bas des bulletins (ex. « Écoles FVPT »)
  actif      TINYINT(1)   NOT NULL DEFAULT 1,
  cree_le    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (!ligne("SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecoles' AND COLUMN_NAME = 'groupe_id'")) {
  bd()->exec('ALTER TABLE ecoles ADD COLUMN groupe_id INT UNSIGNED NULL AFTER id');
  bd()->exec('ALTER TABLE ecoles ADD CONSTRAINT fk_ecole_groupe FOREIGN KEY (groupe_id) REFERENCES groupes(id)');
}

$groupe = function (string $code, string $nom, ?string $sigle): int {
  $g = ligne('SELECT id FROM groupes WHERE code = ?', [$code]);
  if ($g) return (int)$g['id'];
  requete('INSERT INTO groupes (code, nom, sigle) VALUES (?,?,?)', [$code, $nom, $sigle]);
  return (int)bd()->lastInsertId();
};
$fvpt = $groupe('FVPT', 'Réseau des écoles FVPT', 'Écoles FVPT');
$parakou = $groupe('PARAKOU', 'Groupe de Parakou (nom à préciser)', null);

requete("UPDATE ecoles SET groupe_id = ? WHERE code = 'KANDI' AND groupe_id IS NULL", [$fvpt]);
$quittent = 0;
foreach (['SOMO', 'SEGOVIANA', 'STANDRE'] as $code) {
  $e = ligne('SELECT * FROM ecoles WHERE code = ?', [$code]);
  if (!$e) continue;
  if ($e['groupe_id'] === null) requete('UPDATE ecoles SET groupe_id = ? WHERE id = ?', [$parakou, $e['id']]);
  // Retrait de la mention FVPT (seulement là où l'entête est encore celle chargée automatiquement)
  requete("UPDATE ecoles SET
             entete_ligne2 = IF(entete_ligne2 = 'Fondation Vie Pour Tous, Vida para todos', NULL, entete_ligne2),
             entete_ligne3 = IF(entete_ligne3 = 'Direction nationale des écoles FVPT', NULL, entete_ligne3),
             site_web = IF(site_web = 'www.fondation-viepourtous.org', NULL, site_web)
           WHERE id = ?", [$e['id']]);
  requete("DELETE FROM ecole_images WHERE ecole_id = ? AND type = 'LOGO_FONDATION'", [$e['id']]);
  $quittent++;
}
// Toute autre école sans groupe rejoint le groupe FVPT par défaut
requete('UPDATE ecoles SET groupe_id = ? WHERE groupe_id IS NULL', [$fvpt]);
info("  Groupes : FVPT et Groupe de Parakou ; $quittent école(s) rattachée(s) au nouveau groupe, mention FVPT retirée de leurs entêtes.");
