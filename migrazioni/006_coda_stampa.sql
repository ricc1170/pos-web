-- ============================================================
--  Migrazione 006 - Coda di stampa per il "ponte di stampa"
--  Il sito online non raggiunge le stampanti del locale: le stampe
--  vanno in coda e un programma sul PC del locale (ponte.ps1) le
--  preleva e le invia alle stampanti.
-- ============================================================
CREATE TABLE IF NOT EXISTS coda_stampa (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  stampante_id     INT          NOT NULL,
  descrizione      VARCHAR(100) NOT NULL,
  dati             MEDIUMTEXT   NOT NULL,              -- byte ESC/POS in base64
  stato            ENUM('attesa','stampata','errore') NOT NULL DEFAULT 'attesa',
  tentativi        INT          NOT NULL DEFAULT 0,
  errore           VARCHAR(255) NULL,
  creato           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_tentativo DATETIME     NULL,
  stampato         DATETIME     NULL,
  KEY (stato, id),
  FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
