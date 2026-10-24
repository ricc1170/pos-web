-- ============================================================
--  Migrazione 004 - Blocco dei tentativi di login
--  Ogni PIN sbagliato viene registrato con l'IP: dopo 5 errori in
--  10 minuti quell'IP non può più provare finché non passa il tempo.
--  Da eseguire UNA volta dopo la 003, sul database del POS selezionato.
-- ============================================================

CREATE TABLE IF NOT EXISTS login_tentativi (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  ip      VARCHAR(45) NOT NULL,
  creato  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (ip, creato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
