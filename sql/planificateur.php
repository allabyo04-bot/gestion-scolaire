<?php
// =====================================================================
//  PLANIFICATEUR — lancé toutes les 5 minutes par le conteneur.
//  Chaque nuit, à partir de 2 h (heure du Bénin), envoie UNE sauvegarde
//  par e-mail si aucune sauvegarde nocturne n'a réussi aujourd'hui.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = is_dir(__DIR__ . '/../html/api') ? __DIR__ . '/../html/api' : __DIR__ . '/../api';
require $racine . '/config.php';
foreach (['db', 'reponse', 'audit', 'sauvegarde'] as $f) require $racine . "/lib/$f.php";

$heure = (int)date('G');
$forcer = in_array('--maintenant', $argv, true);
if (!$forcer && $heure < 2) exit(0);
if (!getenv('RESEND_API_KEY') || !getenv('SAUVEGARDE_EMAIL')) exit(0);          // pas configuré : rien à faire
if (!$forcer && ligne("SELECT id FROM sauvegardes WHERE type = 'NOCTURNE' AND statut = 'REUSSIE' AND DATE(cree_le) = CURDATE()")) exit(0);
// Après un échec, nouvel essai au plus toutes les heures
if (!$forcer && ligne("SELECT id FROM sauvegardes WHERE type = 'NOCTURNE' AND statut = 'ECHEC' AND cree_le > NOW() - INTERVAL 1 HOUR")) exit(0);

try {
  $s = sauvegarde_sql();
  $dest = envoyer_sauvegarde_par_email($s);
  noter_sauvegarde('NOCTURNE', 'REUSSIE', $s, $dest, null);
  fwrite(STDOUT, '[sauvegarde] Envoyée à ' . $dest . ' (' . round(strlen($s['contenu']) / 1024) . " Ko)\n");
} catch (Throwable $e) {
  noter_sauvegarde('NOCTURNE', 'ECHEC', $s ?? null, getenv('SAUVEGARDE_EMAIL') ?: null, $e->getMessage());
  fwrite(STDOUT, '[sauvegarde] ÉCHEC : ' . $e->getMessage() . "\n");
}
