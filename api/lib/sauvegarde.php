<?php
// =====================================================================
//  SAUVEGARDE COMPLÈTE DE LA BASE (en PHP pur, sans mysqldump)
//  Produit un fichier SQL compressé (.sql.gz) qui recrée toutes les
//  tables, vues et données. Restauration : l'importer dans une base vide.
// =====================================================================
function sauvegarde_sql(): array {
  $pdo = bd();
  $nomBase = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $objets = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll();
  $tables = array_column(array_filter($objets, fn($o) => $o['TABLE_TYPE'] === 'BASE TABLE'), 'TABLE_NAME');
  $vues = array_column(array_filter($objets, fn($o) => $o['TABLE_TYPE'] === 'VIEW'), 'TABLE_NAME');

  $sortie = fopen('php://temp', 'w+');
  $ecrire = fn(string $t) => fwrite($sortie, $t);
  $ecrire("-- Sauvegarde Réseau des écoles FVPT\n-- Base : $nomBase\n-- Date : " . date('Y-m-d H:i:s') . " (heure du Bénin)\n"
        . "-- Restauration : importer ce fichier dans une base VIDE.\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET time_zone = '+01:00';\n\n");
  $nbLignes = 0;
  foreach ($tables as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    $ecrire("-- ------------------------------------------------ $t\nDROP TABLE IF EXISTS `$t`;\n$create;\n\n");
    $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
    $lot = [];
    while ($ligne = $st->fetch(PDO::FETCH_NUM)) {
      $lot[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $ligne)) . ')';
      $nbLignes++;
      if (count($lot) === 200) { $ecrire("INSERT INTO `$t` VALUES\n" . implode(",\n", $lot) . ";\n"); $lot = []; }
    }
    if ($lot) $ecrire("INSERT INTO `$t` VALUES\n" . implode(",\n", $lot) . ";\n");
    $ecrire("\n");
  }
  // Vues : dans l'ordre de leurs dépendances (une vue peut en utiliser une autre)
  $defs = [];
  foreach ($vues as $v) $defs[$v] = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1];
  $ordre = [];
  while (count($ordre) < count($defs)) {
    $avance = false;
    foreach ($defs as $v => $def) {
      if (in_array($v, $ordre, true)) continue;
      $attend = array_filter(array_keys($defs), fn($autre) => $autre !== $v && !in_array($autre, $ordre, true) && str_contains($def, "`$autre`"));
      if (!$attend) { $ordre[] = $v; $avance = true; }
    }
    if (!$avance) { foreach (array_keys($defs) as $v) if (!in_array($v, $ordre, true)) $ordre[] = $v; }   // dépendance circulaire : impossible en pratique
  }
  // Sans DEFINER (sinon la restauration échoue sous un autre utilisateur MySQL)
  foreach ($ordre as $v) {
    $def = $defs[$v];
    $def = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $def);
    $def = preg_replace('/\sSQL SECURITY DEFINER/', '', $def);
    $def = preg_replace('/^CREATE\s+(ALGORITHM=\w+\s+)?/', 'CREATE OR REPLACE ', $def);
    $ecrire("-- ------------------------------------------------ vue $v\nDROP VIEW IF EXISTS `$v`;\n$def;\n\n");
  }
  $ecrire("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Fin de la sauvegarde\n");
  rewind($sortie);
  $sql = stream_get_contents($sortie);
  fclose($sortie);
  return ['contenu' => gzencode($sql, 9), 'nb_tables' => count($tables) + count($vues), 'nb_lignes' => $nbLignes,
          'nom' => 'sauvegarde-fvpt-' . date('Y-m-d-His') . '.sql.gz'];
}

function noter_sauvegarde(string $type, string $statut, ?array $s, ?string $destination, ?string $message, ?int $utilisateur = null): void {
  requete('INSERT INTO sauvegardes (type, statut, taille_octets, nb_tables, nb_lignes, destination, message, utilisateur_id) VALUES (?,?,?,?,?,?,?,?)',
          [$type, $statut, $s ? strlen($s['contenu']) : null, $s['nb_tables'] ?? null, $s['nb_lignes'] ?? null, $destination,
           $message ? mb_substr($message, 0, 255) : null, $utilisateur]);
}

// Envoi par e-mail avec le service Resend (variables RESEND_API_KEY et SAUVEGARDE_EMAIL sur Railway)
function envoyer_sauvegarde_par_email(array $s): string {
  $cle = getenv('RESEND_API_KEY'); $dest = getenv('SAUVEGARDE_EMAIL');
  if (!$cle || !$dest) throw new RuntimeException('Envoi par e-mail non configuré (variables RESEND_API_KEY et SAUVEGARDE_EMAIL).');
  $corps = json_encode([
    'from' => getenv('SAUVEGARDE_EXPEDITEUR') ?: 'Sauvegarde FVPT <onboarding@resend.dev>',
    'to' => array_map('trim', explode(',', $dest)),
    'subject' => 'Sauvegarde du ' . date('d/m/Y') . ' - Réseau des écoles FVPT',
    'html' => '<p>Bonjour,</p><p>Voici la sauvegarde automatique de la base du logiciel de gestion scolaire.</p>'
            . '<ul><li>Tables : ' . $s['nb_tables'] . '</li><li>Lignes : ' . number_format($s['nb_lignes'], 0, ',', ' ') . '</li>'
            . '<li>Taille : ' . number_format(strlen($s['contenu']) / 1024, 0, ',', ' ') . ' Ko</li></ul>'
            . '<p>Conservez ce message : en cas de problème, ce fichier permet de tout restaurer.</p>',
    'attachments' => [['filename' => $s['nom'], 'content' => base64_encode($s['contenu'])]],
  ], JSON_UNESCAPED_UNICODE);
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 60, 'ignore_errors' => true,
    'header' => "Authorization: Bearer $cle\r\nContent-Type: application/json\r\n", 'content' => $corps]]);
  $rep = @file_get_contents(getenv('RESEND_URL') ?: 'https://api.resend.com/emails', false, $ctx);
  $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
  if ($code < 200 || $code >= 300) throw new RuntimeException("Le service d'envoi a répondu $code : " . mb_substr((string)$rep, 0, 150));
  return $dest;
}
