<?php
// 019 — Logo des Écoles FVPT en haute définition (remplace la version 185 px chargée en 017,
//       seulement là où elle n'a pas été remplacée à la main)
$hd = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/../donnees/logo_ecoles_fvpt.png'));
$n = requete("UPDATE groupes SET logo = ? WHERE code = 'FVPT' AND (logo IS NULL OR MD5(logo) = '5ed02c2c7692ac8ba2ecd0707adc1626')", [$hd])->rowCount();
$n += requete("UPDATE ecole_images SET donnees = ? WHERE type = 'LOGO' AND MD5(donnees) = '5ed02c2c7692ac8ba2ecd0707adc1626'", [$hd])->rowCount();
info("  Logo des Écoles FVPT en haute définition : $n emplacement(s) mis à jour.");
