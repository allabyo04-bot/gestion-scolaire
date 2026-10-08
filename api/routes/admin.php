<?php
// Sauvegardes : téléchargement immédiat et historique (administrateur général)
require_once __DIR__ . '/../lib/sauvegarde.php';

function r_admin_sauvegarde_telecharger() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN');
  $s = sauvegarde_sql();
  noter_sauvegarde('MANUELLE', 'REUSSIE', $s, 'téléchargement', null, (int)$UTILISATEUR['id']);
  journaliser('SAUVEGARDE', 'sauvegardes', null, "Sauvegarde téléchargée ({$s['nb_lignes']} lignes)");
  header('Content-Type: application/gzip');
  header('Content-Disposition: attachment; filename="' . $s['nom'] . '"');
  header('Content-Length: ' . strlen($s['contenu']));
  echo $s['contenu'];
  exit;
}

function r_admin_sauvegardes() {
  exiger_role('SUPER_ADMIN');
  repondre([
    'email_configure' => (bool)(getenv('RESEND_API_KEY') && getenv('SAUVEGARDE_EMAIL')),
    'destinataire' => getenv('SAUVEGARDE_EMAIL') ?: null,
    'historique' => lignes("SELECT s.*, CONCAT(u.nom, ' ', u.prenoms) AS auteur FROM sauvegardes s LEFT JOIN utilisateurs u ON u.id = s.utilisateur_id
                            ORDER BY s.id DESC LIMIT 30"),
  ]);
}

// Envoi immédiat par e-mail (pour tester la configuration)
function r_admin_sauvegarde_envoyer() {
  global $UTILISATEUR;
  exiger_role('SUPER_ADMIN');
  $s = sauvegarde_sql();
  try { $dest = envoyer_sauvegarde_par_email($s); }
  catch (Throwable $e) { noter_sauvegarde('MANUELLE', 'ECHEC', $s, getenv('SAUVEGARDE_EMAIL') ?: null, $e->getMessage(), (int)$UTILISATEUR['id']); erreur($e->getMessage(), 502); }
  noter_sauvegarde('MANUELLE', 'REUSSIE', $s, $dest, 'Envoi de test', (int)$UTILISATEUR['id']);
  journaliser('SAUVEGARDE', 'sauvegardes', null, "Sauvegarde envoyée à $dest");
  repondre(['destinataire' => $dest]);
}
