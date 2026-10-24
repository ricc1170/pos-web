-- ============================================================
--  Migrazione 005 - Configurazione stampanti
--  larghezza = caratteri per riga (carta 80 mm = 48, 58 mm = 32)
--  attiva    = 0 per escludere una stampante senza cancellarla
-- ============================================================
ALTER TABLE stampanti
  ADD COLUMN larghezza TINYINT    NOT NULL DEFAULT 48 AFTER tipo,
  ADD COLUMN attiva    TINYINT(1) NOT NULL DEFAULT 1  AFTER larghezza;
