-- ============================================================
--  Migrazione 014 - Più clienti contemporanei al banco (Bar)
--  Al banco l'operatore può avere fino a 3 comande in corso, una per cliente, mostrate
--  una sotto l'altra nel programma della cassa (Impostazioni > Bar). conti.posto = 1, 2 o 3.
-- ============================================================
ALTER TABLE conti ADD COLUMN posto TINYINT NOT NULL DEFAULT 1 AFTER operatore_id;
