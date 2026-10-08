-- 007 — La Ségoviana est à Parakou (seulement si la ville n'a pas déjà été corrigée à la main)
SET NAMES utf8mb4;
UPDATE ecoles SET ville = 'Parakou' WHERE code = 'SEGOVIANA' AND ville = 'À préciser';
