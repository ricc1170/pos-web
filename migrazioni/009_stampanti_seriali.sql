-- ============================================================
--  Migrazione 009 - Stampanti seriali (porta COM sul PC del ponte)
--  collegamento = rete (ip:porta) oppure seriale (com, baud, flusso)
--  Le seriali stampano sempre tramite il ponte di stampa (ponte.ps1).
-- ============================================================
ALTER TABLE stampanti
  ADD COLUMN collegamento ENUM('rete','seriale') NOT NULL DEFAULT 'rete' AFTER nome,
  ADD COLUMN com          VARCHAR(10) NULL                              AFTER porta,
  ADD COLUMN baud         INT         NOT NULL DEFAULT 9600             AFTER com,
  ADD COLUMN flusso       ENUM('nessuno','rtscts','xonxoff') NOT NULL DEFAULT 'nessuno' AFTER baud;
