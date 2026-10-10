-- 018 — Nom officiel du second groupe : Groupe E. GNON GANI (CPEG Somo, EP La Ségoviana, EP Saint André)
-- Seulement si le nom provisoire n'a pas déjà été changé à la main.
SET NAMES utf8mb4;
UPDATE groupes SET nom = 'Groupe E. GNON GANI', sigle = COALESCE(sigle, 'Groupe E. GNON GANI')
WHERE code = 'PARAKOU' AND nom = 'Groupe de Parakou (nom à préciser)';
