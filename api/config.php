<?php
// =====================================================================
//  CONFIGURATION
//  Sur Railway : tout vient de la variable MYSQL_URL (rien à modifier ici).
//  En local (XAMPP) : valeurs par défaut ci-dessous, ou fichier config.local.php
// =====================================================================
$url = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
if ($url) {
  $u = parse_url($url);
  define('DB_HOST', $u['host']);
  define('DB_PORT', (int)($u['port'] ?? 3306));
  define('DB_NAME', ltrim($u['path'] ?? '', '/'));
  define('DB_USER', urldecode($u['user'] ?? ''));
  define('DB_PASS', urldecode($u['pass'] ?? ''));
} else {
  define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
  define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
  define('DB_NAME', getenv('DB_NAME') ?: 'gestion_scolaire');
  define('DB_USER', getenv('DB_USER') ?: 'root');
  define('DB_PASS', getenv('DB_PASS') ?: '');
}

// Domaines autorisés à appeler l'API depuis un AUTRE site (inutile si site et API sont ensemble)
const ORIGINES_AUTORISEES = ['http://localhost:5173'];

const DUREE_JETON_HEURES   = 12;
const MAX_TENTATIVES       = 5;
const BLOCAGE_MINUTES      = 15;
const LONGUEUR_MIN_MDP     = 8;

date_default_timezone_set('Africa/Porto-Novo');

if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
