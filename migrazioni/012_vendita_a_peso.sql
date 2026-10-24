-- ============================================================
--  Migrazione 012 - Vendita a peso (bilancia sul PC della cassa)
--  articoli.a_peso = 1  -> prezzo al kg; la quantità della riga è il peso in kg
--  righe.quantita con 3 decimali (grammi)
-- ============================================================
ALTER TABLE articoli ADD COLUMN a_peso TINYINT(1) NOT NULL DEFAULT 0 AFTER stampa;
ALTER TABLE righe MODIFY quantita DECIMAL(8,3) NOT NULL DEFAULT 1;
