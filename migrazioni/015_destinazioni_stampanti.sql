-- ============================================================
--  Migrazione 015 - Destinazioni delle categorie come i tipi di stampante del programma della cassa:
--  cucina, pizzeria, bar, banco (+ nessuna = non si stampa)
--  Le categorie delle pizze passano alla pizzeria (si cambia da Categorie).
-- ============================================================
ALTER TABLE categorie MODIFY destinazione ENUM('cucina','pizzeria','bar','banco','nessuna') NOT NULL DEFAULT 'cucina';

UPDATE categorie SET destinazione = 'pizzeria'
WHERE destinazione = 'cucina' AND nome IN ('PIZZE', 'PIZZE SPEC', 'PIZZE BIANCHE', 'CALZONI', 'FOCACCE');
