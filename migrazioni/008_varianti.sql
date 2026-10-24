-- ============================================================
--  Migrazione 008 - Varianti (aggiunte "+" a pagamento, tolte "−" gratis)
--  varianti            elenco ingredienti/varianti con prezzo dell'aggiunta
--  varianti_categorie  per quali categorie vale (tutte = 1: tutte le categorie)
--  righe_varianti      varianti scelte su una riga del conto (copia di nome e prezzo)
--  righe.prezzo_varianti somma delle aggiunte, per pezzo
-- ============================================================
CREATE TABLE IF NOT EXISTS varianti (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  nome    VARCHAR(50)  NOT NULL,
  prezzo  DECIMAL(8,2) NOT NULL DEFAULT 0,
  tutte   TINYINT(1)   NOT NULL DEFAULT 0,
  ordine  INT          NOT NULL DEFAULT 0,
  attiva  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS varianti_categorie (
  variante_id  INT NOT NULL,
  categoria_id INT NOT NULL,
  PRIMARY KEY (variante_id, categoria_id),
  FOREIGN KEY (variante_id)  REFERENCES varianti(id)  ON DELETE CASCADE,
  FOREIGN KEY (categoria_id) REFERENCES categorie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS righe_varianti (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  riga_id     INT          NOT NULL,
  variante_id INT          NULL,
  segno       ENUM('+','-') NOT NULL,
  nome        VARCHAR(50)  NOT NULL,
  prezzo      DECIMAL(8,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (riga_id)     REFERENCES righe(id)    ON DELETE CASCADE,
  FOREIGN KEY (variante_id) REFERENCES varianti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE righe
  ADD COLUMN prezzo_varianti DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER prezzo_unitario;

-- Elenco di partenza: ingredienti per pizze, calzoni e focacce (modificabile da Impostazioni > Varianti)
INSERT INTO varianti (nome, prezzo, ordine) VALUES
  ('Mozzarella', 1.00, 1), ('Mozzarella di bufala', 2.00, 2), ('Pomodoro', 0.50, 3),
  ('Prosciutto cotto', 1.50, 4), ('Prosciutto crudo', 2.00, 5), ('Salame piccante', 1.50, 6),
  ('Wurstel', 1.00, 7), ('Funghi', 1.00, 8), ('Carciofi', 1.00, 9), ('Olive', 0.50, 10),
  ('Acciughe', 1.00, 11), ('Cipolla', 0.50, 12), ('Basilico', 0.00, 13), ('Origano', 0.00, 14),
  ('Aglio', 0.00, 15), ('Ben cotta', 0.00, 16);

INSERT INTO varianti_categorie (variante_id, categoria_id)
  SELECT v.id, c.id FROM varianti v JOIN categorie c
  ON c.nome IN ('PIZZE', 'PIZZE SPEC', 'CALZONI', 'FOCACCE');
