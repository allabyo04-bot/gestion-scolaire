<?php
class ErreurApi extends Exception {
  public int $statut;
  public function __construct(string $message, int $statut = 400) {
    parent::__construct($message);
    $this->statut = $statut;
  }
}

function repondre($donnees = null, int $statut = 200): void {
  http_response_code($statut);
  echo json_encode(['ok' => true, 'donnees' => $donnees], JSON_UNESCAPED_UNICODE);
  exit;
}

function erreur(string $message, int $statut = 400): void {
  throw new ErreurApi($message, $statut);
}

function entree(): array {
  static $donnees = null;
  if ($donnees === null) {
    $brut = file_get_contents('php://input');
    $donnees = $brut ? (json_decode($brut, true) ?? []) : [];
    $donnees = array_merge($_GET, $donnees);
    unset($donnees['r']);
  }
  return $donnees;
}

function champ(string $nom, bool $obligatoire = true) {
  $d = entree();
  if (!array_key_exists($nom, $d) || $d[$nom] === '' || $d[$nom] === null) {
    if ($obligatoire) erreur("Le champ « $nom » est obligatoire.");
    return null;
  }
  return is_string($d[$nom]) ? trim($d[$nom]) : $d[$nom];
}

function entier(string $nom, bool $obligatoire = true): ?int {
  $v = champ($nom, $obligatoire);
  if ($v === null) return null;
  if (!ctype_digit((string)$v)) erreur("Le champ « $nom » doit être un nombre entier.");
  return (int)$v;
}

// Accepte « 12,5 » comme « 12.5 » ; contrôle 0 à 20
function note_valide($v): float {
  $v = str_replace(',', '.', trim((string)$v));
  if (!is_numeric($v)) erreur("Note invalide : « $v ».");
  $n = round((float)$v, 2);
  if ($n < 0 || $n > 20) erreur("Une note doit être comprise entre 0 et 20 (reçu : $v).");
  return $n;
}

// Solution de secours si l'extension mbstring est absente de l'hébergement
if (!function_exists('mb_strlen')) {
  function mb_strlen($s) { return preg_match_all('/./us', (string)$s); }
  function mb_substr($s, $debut, $longueur = null) {
    preg_match_all('/./us', (string)$s, $m);
    return implode('', array_slice($m[0], $debut, $longueur));
  }
  function mb_strtolower($s) {
    return strtr(strtolower((string)$s), ['É'=>'é','È'=>'è','Ê'=>'ê','Ë'=>'ë','À'=>'à','Â'=>'â','Î'=>'î','Ï'=>'ï',
                                          'Ô'=>'ô','Ö'=>'ö','Ù'=>'ù','Û'=>'û','Ü'=>'ü','Ç'=>'ç']);
  }
  function mb_strtoupper($s) {
    return strtr(strtoupper((string)$s), ['é'=>'É','è'=>'È','ê'=>'Ê','ë'=>'Ë','à'=>'À','â'=>'Â','î'=>'Î','ï'=>'Ï',
                                          'ô'=>'Ô','ö'=>'Ö','ù'=>'Ù','û'=>'Û','ü'=>'Ü','ç'=>'Ç']);
  }
}
