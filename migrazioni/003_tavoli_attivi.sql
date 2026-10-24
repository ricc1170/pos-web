-- ============================================================
--  Migrazione 003 - Tavoli nascosti (gestione sale e tavoli)
--  Un tavolo che ha già avuto conti non si cancella: si nasconde.
--  Da eseguire UNA volta dopo la 002. database.sql è già aggiornato.
-- ============================================================
ALTER TABLE tavoli
  ADD COLUMN attivo TINYINT(1) NOT NULL DEFAULT 1 AFTER forma;
