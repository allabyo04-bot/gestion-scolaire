<?php
// Enregistre une action dans le journal d'audit. Ne bloque jamais l'application.
function journaliser(string $action, ?string $table = null, $id = null, ?string $description = null,
                     $avant = null, $apres = null, ?array $utilisateur = null, ?string $identifiantSaisi = null): void {
  global $UTILISATEUR;
  $u = $utilisateur ?? $UTILISATEUR;
  try {
    requete('INSERT INTO journal_audit (utilisateur_id, identifiant_saisi, ecole_id, action, table_cible,
               enregistrement_id, description, valeurs_avant, valeurs_apres, adresse_ip, navigateur)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
      $u['id'] ?? null,
      $identifiantSaisi,
      $u['ecole_id'] ?? null,
      $action,
      $table,
      $id,
      $description ? mb_substr($description, 0, 255) : null,
      $avant === null ? null : json_encode($avant, JSON_UNESCAPED_UNICODE),
      $apres === null ? null : json_encode($apres, JSON_UNESCAPED_UNICODE),
      $_SERVER['REMOTE_ADDR'] ?? null,
      isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
    ]);
  } catch (Throwable $e) {
    error_log('Journal audit : ' . $e->getMessage());
  }
}
