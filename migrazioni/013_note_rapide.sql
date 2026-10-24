-- ============================================================
--  Migrazione 013 - Note rapide per le righe del conto
--  Pulsanti nella finestra Note (INTEGRALE, BIANCA, CELIACO...). Il testo finisce in righe.note;
--  se la nota ha una stampante, il prodotto con quella nota esce SOLO lì (es. CELIACO -> stampante dedicata).
-- ============================================================
CREATE TABLE IF NOT EXISTS note_rapide (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  testo        VARCHAR(30) NOT NULL,
  stampante_id INT         NULL,                 -- NULL = il prodotto esce sulla sua stampante solita
  ordine       INT         NOT NULL DEFAULT 0,
  attiva       TINYINT(1)  NOT NULL DEFAULT 1,
  FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO note_rapide (testo, ordine) VALUES
  ('INTEGRALE', 1), ('BIANCA', 2), ('ROSSA', 3), ('BEN COTTA', 4), ('CELIACO', 5);
