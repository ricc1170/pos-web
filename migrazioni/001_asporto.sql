-- ============================================================
--  Migrazione 001 - Comande Bar / asporto con orario di ritiro
--  Da eseguire UNA volta sui database creati prima di questa modifica
--  (phpMyAdmin -> database pos -> Importa). database.sql è già aggiornato.
-- ============================================================
ALTER TABLE conti
  MODIFY tavolo_id INT NULL,
  ADD COLUMN tipo          ENUM('tavolo','asporto') NOT NULL DEFAULT 'tavolo' AFTER id,
  ADD COLUMN orario_ritiro DATETIME    NULL AFTER coperti,
  ADD COLUMN nome_cliente  VARCHAR(60) NULL AFTER orario_ritiro;
