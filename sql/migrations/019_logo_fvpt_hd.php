<?php
// 019 — Logo des Écoles FVPT en haute définition (remplace la version 185 px chargée en 017,
//       seulement là où elle n'a pas été remplacée à la main).
// L'empreinte est calculée en PHP : la fonction MD5() n'existe plus dans MySQL 9.
$ancien = '5ed02c2c7692ac8ba2ecd0707adc1626';
$hd = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../donnees/logo_ecoles_fvpt.png'));
$n = 0;
foreach (lignes("SELECT id, logo FROM groupes WHERE code = 'FVPT'") as $g)
  if ($g['logo'] === null || md5($g['logo']) === $ancien) { requete('UPDATE groupes SET logo = ? WHERE id = ?', [$hd, $g['id']]); $n++; }
foreach (lignes("SELECT id, donnees FROM ecole_images WHERE type = 'LOGO'") as $i)
  if (md5($i['donnees']) === $ancien) { requete('UPDATE ecole_images SET donnees = ? WHERE id = ?', [$hd, $i['id']]); $n++; }
info("  Logo des Écoles FVPT en haute définition : $n emplacement(s) mis à jour.");
