-- ============================================================
--  Migrazione 007 - Stampante di ogni prodotto
--  stampante_id NULL = automatica (prima stampante attiva del tipo
--                      indicato dalla destinazione della categoria)
--  stampa = 0        = il prodotto non si stampa
-- ============================================================
ALTER TABLE articoli
  ADD COLUMN stampante_id INT        NULL               AFTER visibile,
  ADD COLUMN stampa       TINYINT(1) NOT NULL DEFAULT 1 AFTER stampante_id,
  ADD CONSTRAINT fk_articoli_stampante FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE SET NULL;
