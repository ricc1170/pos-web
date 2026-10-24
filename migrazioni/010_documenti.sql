-- ============================================================
--  Migrazione 010 - Scontrino (Epson RT), fattura elettronica, proforma
--  clienti     anagrafica per le fatture
--  documenti   scontrini, fatture e proforma emessi (fatture numerate per anno)
--  coda_stampa anche lavori per il registratore telematico (tipo = 'rt'),
--              con la risposta della cassa
-- ============================================================
CREATE TABLE IF NOT EXISTS clienti (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  denominazione       VARCHAR(80)  NULL,             -- azienda (oppure nome + cognome)
  nome                VARCHAR(60)  NULL,
  cognome             VARCHAR(60)  NULL,
  piva                VARCHAR(11)  NULL,
  cf                  VARCHAR(16)  NULL,
  indirizzo           VARCHAR(80)  NOT NULL,
  cap                 VARCHAR(5)   NOT NULL,
  comune              VARCHAR(60)  NOT NULL,
  provincia           CHAR(2)      NULL,
  nazione             CHAR(2)      NOT NULL DEFAULT 'IT',
  codice_destinatario VARCHAR(7)   NOT NULL DEFAULT '0000000',
  pec                 VARCHAR(100) NULL,
  creato              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documenti (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tipo         ENUM('scontrino','fattura','proforma') NOT NULL,
  conto_id     INT           NULL,
  numero       INT           NULL,                  -- fatture: numero progressivo nell'anno
  anno         SMALLINT      NULL,
  data         DATETIME      NOT NULL,
  totale       DECIMAL(10,2) NOT NULL,
  pagamento_id INT           NULL,
  cliente_id   INT           NULL,
  stato        ENUM('attesa','emesso','errore') NOT NULL DEFAULT 'emesso',
  rt_numero    VARCHAR(20)   NULL,                  -- numero scontrino restituito dalla Epson
  rt_zreport   VARCHAR(10)   NULL,
  errore       VARCHAR(255)  NULL,
  operatore_id INT           NULL,
  UNIQUE KEY (tipo, anno, numero),
  FOREIGN KEY (conto_id)     REFERENCES conti(id) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id)   REFERENCES clienti(id),
  FOREIGN KEY (pagamento_id) REFERENCES forme_pagamento(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE coda_stampa
  ADD COLUMN tipo         ENUM('stampa','rt') NOT NULL DEFAULT 'stampa' AFTER id,
  ADD COLUMN documento_id INT  NULL AFTER stampante_id,
  ADD COLUMN risposta     TEXT NULL AFTER errore,
  MODIFY stampante_id INT NULL;
