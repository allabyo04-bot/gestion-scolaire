<?php
// 024 — Sexe du personnel (facultatif) : sert à afficher Instituteur / Institutrice,
// Directeur / Directrice. Les valeurs réelles sont saisies dans le logiciel (Comptes > Modifier).
if (!ligne("SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateurs' AND COLUMN_NAME = 'sexe'"))
  bd()->exec("ALTER TABLE utilisateurs ADD COLUMN sexe ENUM('M','F') NULL AFTER prenoms");
info('  Champ « sexe » du personnel disponible.');
