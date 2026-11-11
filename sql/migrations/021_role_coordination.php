<?php
// 021 — Rôle « Coordination pédagogique » : compte sans école (toutes les écoles),
// limité à la gestion des notes (saisie suivie, préparation, corrections, résultats, bulletins).
bd()->exec("ALTER TABLE utilisateurs DROP CONSTRAINT chk_util_rattachement");
bd()->exec("ALTER TABLE utilisateurs MODIFY role ENUM('SUPER_ADMIN','COORDINATION','DIRECTRICE','SECRETARIAT','COMPTABLE','PROFESSEUR') NOT NULL");
bd()->exec("ALTER TABLE utilisateurs ADD CONSTRAINT chk_util_rattachement CHECK (
       (role IN ('SUPER_ADMIN','COORDINATION') AND ecole_id IS NULL)
    OR (role NOT IN ('SUPER_ADMIN','COORDINATION') AND ecole_id IS NOT NULL))");
info('  Rôle « Coordination pédagogique » disponible.');
