<?php
// =====================================================================
//  API — point d'entrée unique :  /api/index.php?r=module/action
// =====================================================================
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/config.php';
foreach (['db', 'reponse', 'audit', 'auth', 'acces', 'calcul'] as $f) require __DIR__ . "/lib/$f.php";

$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origine, ORIGINES_AUTORISEES, true)) {
  header("Access-Control-Allow-Origin: $origine");
  header('Vary: Origin');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Jeton');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// route => [fichier, fonction, méthode, connexion requise]
const ROUTES = [
  'auth/connexion'             => ['auth', 'r_auth_connexion', 'POST', false],
  'auth/moi'                   => ['auth', 'r_auth_moi', 'GET', true],
  'auth/deconnexion'           => ['auth', 'r_auth_deconnexion', 'POST', true],
  'auth/changer_mdp'           => ['auth', 'r_auth_changer_mdp', 'POST', true],
  'utilisateurs/liste'         => ['utilisateurs', 'r_utilisateurs_liste', 'GET', true],
  'utilisateurs/creer'         => ['utilisateurs', 'r_utilisateurs_creer', 'POST', true],
  'utilisateurs/activer'       => ['utilisateurs', 'r_utilisateurs_activer', 'POST', true],
  'utilisateurs/reinitialiser_mdp' => ['utilisateurs', 'r_utilisateurs_reinitialiser_mdp', 'POST', true],
  'notes/mes_affectations'     => ['notes', 'r_notes_mes_affectations', 'GET', true],
  'notes/evaluations'          => ['notes', 'r_notes_evaluations', 'GET', true],
  'notes/generer_evaluations'  => ['notes', 'r_notes_generer_evaluations', 'POST', true],
  'notes/ajouter_evaluation'   => ['notes', 'r_notes_ajouter_evaluation', 'POST', true],
  'notes/supprimer_evaluation' => ['notes', 'r_notes_supprimer_evaluation', 'POST', true],
  'notes/feuille'              => ['notes', 'r_notes_feuille', 'GET', true],
  'notes/enregistrer'          => ['notes', 'r_notes_enregistrer', 'POST', true],
  'notes/valider'              => ['notes', 'r_notes_valider', 'POST', true],
  'notes/corriger'             => ['notes', 'r_notes_corriger', 'POST', true],
  'notes/rouvrir'              => ['notes', 'r_notes_rouvrir', 'POST', true],
  'notes/historique'           => ['notes', 'r_notes_historique', 'GET', true],
  'resultats/classe'           => ['resultats', 'r_resultats_classe', 'GET', true],
  'journal/liste'              => ['resultats', 'r_journal_liste', 'GET', true],
  'ref/ecoles'                 => ['referentiel', 'r_ref_ecoles', 'GET', true],
  'ref/periodes'               => ['referentiel', 'r_ref_periodes', 'GET', true],
  'ref/classes'                => ['referentiel', 'r_ref_classes', 'GET', true],
  'param/ecole'                        => ['parametres', 'r_param_ecole', 'GET', true],
  'param/ecole_enregistrer'            => ['parametres', 'r_param_ecole_enregistrer', 'POST', true],
  'param/annees'                       => ['parametres', 'r_param_annees', 'GET', true],
  'param/annee_creer'                  => ['parametres', 'r_param_annee_creer', 'POST', true],
  'param/annee_activer'                => ['parametres', 'r_param_annee_activer', 'POST', true],
  'param/periodes'                     => ['parametres', 'r_param_periodes', 'GET', true],
  'param/periodes_creer'               => ['parametres', 'r_param_periodes_creer', 'POST', true],
  'param/parametres_enregistrer'       => ['parametres', 'r_param_parametres_enregistrer', 'POST', true],
  'param/periode_statut'               => ['parametres', 'r_param_periode_statut', 'POST', true],
  'param/config_eval'                  => ['parametres', 'r_param_config_eval', 'GET', true],
  'param/config_eval_enregistrer'      => ['parametres', 'r_param_config_eval_enregistrer', 'POST', true],
  'param/config_eval_supprimer'        => ['parametres', 'r_param_config_eval_supprimer', 'POST', true],
  'param/niveaux'                      => ['parametres', 'r_param_niveaux', 'GET', true],
  'param/series'                       => ['parametres', 'r_param_series', 'GET', true],
  'param/matieres'                     => ['parametres', 'r_param_matieres', 'GET', true],
  'param/matiere_enregistrer'          => ['parametres', 'r_param_matiere_enregistrer', 'POST', true],
  'param/professeurs'                  => ['parametres', 'r_param_professeurs', 'GET', true],
  'param/classes'                      => ['parametres', 'r_param_classes', 'GET', true],
  'param/classe_enregistrer'           => ['parametres', 'r_param_classe_enregistrer', 'POST', true],
  'param/classe_supprimer'             => ['parametres', 'r_param_classe_supprimer', 'POST', true],
  'param/classe_matieres'              => ['parametres', 'r_param_classe_matieres', 'GET', true],
  'param/classe_matiere_enregistrer'   => ['parametres', 'r_param_classe_matiere_enregistrer', 'POST', true],
  'param/classe_matiere_retirer'       => ['parametres', 'r_param_classe_matiere_retirer', 'POST', true],
  'param/classe_matieres_ordre'        => ['parametres', 'r_param_classe_matieres_ordre', 'POST', true],
  'param/classe_copier_matieres'       => ['parametres', 'r_param_classe_copier_matieres', 'POST', true],
  'eleves/liste'                       => ['eleves', 'r_eleves_liste', 'GET', true],
  'eleves/fiche'                       => ['eleves', 'r_eleves_fiche', 'GET', true],
  'eleves/chercher_educmaster'         => ['eleves', 'r_eleves_chercher_educmaster', 'GET', true],
  'eleves/inscrire'                    => ['eleves', 'r_eleves_inscrire', 'POST', true],
  'eleves/modifier'                    => ['eleves', 'r_eleves_modifier', 'POST', true],
  'eleves/tuteur_enregistrer'          => ['eleves', 'r_eleves_tuteur_enregistrer', 'POST', true],
  'eleves/tuteur_retirer'              => ['eleves', 'r_eleves_tuteur_retirer', 'POST', true],
  'eleves/inscription_modifier'        => ['eleves', 'r_eleves_inscription_modifier', 'POST', true],
  'eleves/liste_classe'                => ['eleves', 'r_eleves_liste_classe', 'GET', true],
  'eleves/certificat'                  => ['eleves', 'r_eleves_certificat', 'GET', true],
  'eleves/importer'                    => ['eleves', 'r_eleves_importer', 'POST', true],
  'fin/tarifs'                         => ['finances', 'r_fin_tarifs', 'GET', true],
  'fin/tarif_enregistrer'              => ['finances', 'r_fin_tarif_enregistrer', 'POST', true],
  'fin/situation'                      => ['finances', 'r_fin_situation', 'GET', true],
  'fin/encaisser'                      => ['finances', 'r_fin_encaisser', 'POST', true],
  'fin/recu'                           => ['finances', 'r_fin_recu', 'GET', true],
  'fin/annuler'                        => ['finances', 'r_fin_annuler', 'POST', true],
  'fin/remise'                         => ['finances', 'r_fin_remise', 'POST', true],
  'fin/remise_supprimer'               => ['finances', 'r_fin_remise_supprimer', 'POST', true],
  'fin/journal'                        => ['finances', 'r_fin_journal', 'GET', true],
  'fin/recherche'                      => ['finances', 'r_fin_recherche', 'GET', true],
  'fin/impayes'                        => ['finances', 'r_fin_impayes', 'GET', true],
  'abs/mes_classes'                    => ['absences', 'r_abs_mes_classes', 'GET', true],
  'abs/appel'                          => ['absences', 'r_abs_appel', 'GET', true],
  'abs/appel_enregistrer'              => ['absences', 'r_abs_appel_enregistrer', 'POST', true],
  'abs/justifier'                      => ['absences', 'r_abs_justifier', 'POST', true],
  'abs/a_justifier'                    => ['absences', 'r_abs_a_justifier', 'GET', true],
  'abs/bilan_classe'                   => ['absences', 'r_abs_bilan_classe', 'GET', true],
  'abs/eleve'                          => ['absences', 'r_abs_eleve', 'GET', true],
  'param/periode_dates'                => ['parametres', 'r_param_periode_dates', 'POST', true],
  'caisse/jour'                        => ['caisse', 'r_caisse_jour', 'GET', true],
  'caisse/remettre'                    => ['caisse', 'r_caisse_remettre', 'POST', true],
  'caisse/confirmer'                   => ['caisse', 'r_caisse_confirmer', 'POST', true],
  'caisse/remises'                     => ['caisse', 'r_caisse_remises', 'GET', true],
  'tableau/bord'                       => ['tableau', 'r_tableau_bord', 'GET', true],
  'admin/sauvegardes'                  => ['admin', 'r_admin_sauvegardes', 'GET', true],
  'admin/sauvegarde_telecharger'       => ['admin', 'r_admin_sauvegarde_telecharger', 'GET', true],
  'admin/sauvegarde_envoyer'           => ['admin', 'r_admin_sauvegarde_envoyer', 'POST', true],
];

try {
  $r = $_GET['r'] ?? '';
  if (!isset(ROUTES[$r])) erreur('Action inconnue.', 404);
  [$fichier, $fonction, $methode, $protegee] = ROUTES[$r];
  if ($_SERVER['REQUEST_METHOD'] !== $methode) erreur("Méthode $methode attendue.", 405);
  if ($protegee) {
    $u = authentifier();
    // Mot de passe provisoire : obligation de le changer avant toute autre action
    if ($u['doit_changer_mdp'] && !str_starts_with($r, 'auth/'))
      erreur('Vous devez d\'abord changer votre mot de passe provisoire.', 428);
  }
  if (in_array($fichier, ['eleves', 'finances', 'tableau', 'absences', 'caisse'], true)) require __DIR__ . '/routes/parametres.php';
  require __DIR__ . "/routes/$fichier.php";
  $fonction();
} catch (ErreurApi $e) {
  if (bd_en_transaction()) bd()->rollBack();
  http_response_code($e->statut);
  echo json_encode(['ok' => false, 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (bd_en_transaction()) bd()->rollBack();
  error_log('API : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  http_response_code(500);
  echo json_encode(['ok' => false, 'erreur' => 'Erreur interne du serveur.'], JSON_UNESCAPED_UNICODE);
}

function bd_en_transaction(): bool {
  try { return bd()->inTransaction(); } catch (Throwable $e) { return false; }
}
