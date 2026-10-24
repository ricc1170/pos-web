-- ============================================================
--  Migrazione 002 - Note sulla comanda (finestra "Parcheggio" del Bar)
--  Da eseguire UNA volta dopo la 001. database.sql è già aggiornato.
-- ============================================================
ALTER TABLE conti
  ADD COLUMN note VARCHAR(255) NULL AFTER nome_cliente;
