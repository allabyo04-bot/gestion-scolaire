<?php
function bd(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $hote = defined('DB_HOST_LOCAL') ? DB_HOST_LOCAL : DB_HOST;
    $nom  = defined('DB_NAME_LOCAL') ? DB_NAME_LOCAL : DB_NAME;
    $user = defined('DB_USER_LOCAL') ? DB_USER_LOCAL : DB_USER;
    $pass = defined('DB_PASS_LOCAL') ? DB_PASS_LOCAL : DB_PASS;
    $port = DB_PORT;
    $pdo = new PDO("mysql:host=$hote;port=$port;dbname=$nom;charset=utf8mb4", $user, $pass, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+01:00'");   // heure du Bénin pour NOW()
  }
  return $pdo;
}

function requete(string $sql, array $p = []): PDOStatement {
  $st = bd()->prepare($sql);
  $st->execute($p);
  return $st;
}
function ligne(string $sql, array $p = []): ?array {
  $r = requete($sql, $p)->fetch();
  return $r === false ? null : $r;
}
function lignes(string $sql, array $p = []): array {
  return requete($sql, $p)->fetchAll();
}
