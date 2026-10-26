-- ============================================================
--  POS stile TouchEat  -  Schema database MySQL (versione base)
--  Codifica: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS pos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE pos;

-- OPERATORI (camerieri / cassa) -> TouchEat: "Personale"/"Utenti"
CREATE TABLE IF NOT EXISTS operatori (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  nome    VARCHAR(50)  NOT NULL,
  pin     VARCHAR(10)  NOT NULL,
  ruolo   ENUM('cameriere','cassa','admin') NOT NULL DEFAULT 'cameriere',
  attivo  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY (pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SALE -> TouchEat: "Sala Interna", "Veranda", "Pedana", "Dehor"
CREATE TABLE IF NOT EXISTS sale (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  nome    VARCHAR(50)  NOT NULL,
  ordine  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TAVOLI -> forma e dimensioni per disegnare la piantina come TouchEat
CREATE TABLE IF NOT EXISTS tavoli (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  sala_id   INT          NOT NULL,
  nome      VARCHAR(20)  NOT NULL,
  posti     INT          NOT NULL DEFAULT 4,
  pos_x     INT          NOT NULL DEFAULT 0,
  pos_y     INT          NOT NULL DEFAULT 0,
  larghezza INT          NOT NULL DEFAULT 90,
  altezza   INT          NOT NULL DEFAULT 90,
  forma     ENUM('rettangolo','tondo') NOT NULL DEFAULT 'rettangolo',
  attivo    TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (sala_id) REFERENCES sale(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATEGORIE (gruppi) -> TouchEat: PIZZE, BEVANDE, ANTIPASTI...
CREATE TABLE IF NOT EXISTS categorie (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(50)  NOT NULL,
  colore       VARCHAR(7)   DEFAULT '#2b6cb0',
  ordine       INT          NOT NULL DEFAULT 0,
  destinazione ENUM('cucina','pizzeria','bar','banco','nessuna') NOT NULL DEFAULT 'cucina'   -- tipo di stampante nel programma
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STAMPANTI di rete
CREATE TABLE IF NOT EXISTS stampanti (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  nome  VARCHAR(40) NOT NULL,
  collegamento ENUM('rete','seriale') NOT NULL DEFAULT 'rete',
  ip    VARCHAR(45) NOT NULL,                  -- rete
  porta INT         NOT NULL DEFAULT 9100,     -- rete
  com    VARCHAR(10) NULL,                     -- seriale: porta COM sul PC del ponte
  baud   INT         NOT NULL DEFAULT 9600,    -- seriale
  flusso ENUM('nessuno','rtscts','xonxoff') NOT NULL DEFAULT 'nessuno',   -- seriale
  tipo  ENUM('cucina','bar','cassa') NOT NULL DEFAULT 'cucina',
  larghezza TINYINT    NOT NULL DEFAULT 48,   -- caratteri per riga: 80 mm = 48, 58 mm = 32
  attiva    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ARTICOLI (prodotti) -> TouchEat: "Articoli". colore = sfondo del pulsante
CREATE TABLE IF NOT EXISTS articoli (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT          NOT NULL,
  nome         VARCHAR(80)  NOT NULL,
  prezzo       DECIMAL(8,2) NOT NULL DEFAULT 0,
  aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  colore       VARCHAR(7)   DEFAULT '#ffffff',
  ordine       INT          NOT NULL DEFAULT 0,
  visibile     TINYINT(1)   NOT NULL DEFAULT 1,
  stampante_id INT          NULL,               -- NULL = automatica (dalla categoria)
  stampa       TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = non stampare
  a_peso       TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = prezzo al kg, quantità = peso letto dalla bilancia
  FOREIGN KEY (categoria_id) REFERENCES categorie(id),
  FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CONTI (tavolo aperto, oppure comanda Bar/asporto senza tavolo)
CREATE TABLE IF NOT EXISTS conti (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tipo          ENUM('tavolo','asporto') NOT NULL DEFAULT 'tavolo',
  tavolo_id     INT           NULL,
  operatore_id  INT           NULL,
  posto         TINYINT       NOT NULL DEFAULT 1,   -- Bar: cliente al banco (1, 2 o 3) dello stesso operatore
  coperti       INT           NOT NULL DEFAULT 0,
  orario_ritiro DATETIME      NULL,
  nome_cliente  VARCHAR(60)   NULL,
  note          VARCHAR(255)  NULL,
  stato         ENUM('aperto','chiuso') NOT NULL DEFAULT 'aperto',
  data_apertura TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_chiusura DATETIME      NULL,
  totale        DECIMAL(10,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (tavolo_id)    REFERENCES tavoli(id),
  FOREIGN KEY (operatore_id) REFERENCES operatori(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RIGHE del conto
CREATE TABLE IF NOT EXISTS righe (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  conto_id        INT          NOT NULL,
  articolo_id     INT          NULL,
  descrizione     VARCHAR(100) NOT NULL,
  quantita        DECIMAL(8,3) NOT NULL DEFAULT 1,      -- pezzi, oppure kg per i prodotti a peso
  prezzo_unitario DECIMAL(8,2) NOT NULL DEFAULT 0,
  prezzo_varianti DECIMAL(8,2) NOT NULL DEFAULT 0,   -- somma delle aggiunte, per pezzo
  aliquota_iva    DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  note            VARCHAR(150) NULL,
  invio           INT          NOT NULL DEFAULT 0,
  stampata        TINYINT(1)   NOT NULL DEFAULT 0,
  operatore_id    INT          NULL,
  creata_il       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conto_id)    REFERENCES conti(id) ON DELETE CASCADE,
  FOREIGN KEY (articolo_id) REFERENCES articoli(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VARIANTI (aggiunte "+" a pagamento, tolte "−" gratis) e categorie per cui valgono
CREATE TABLE IF NOT EXISTS varianti (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  nome    VARCHAR(50)  NOT NULL,
  prezzo  DECIMAL(8,2) NOT NULL DEFAULT 0,
  tutte   TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = vale per tutte le categorie
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

-- Varianti scelte su una riga del conto (copia di nome e prezzo al momento della scelta)
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

-- FORME DI PAGAMENTO
CREATE TABLE IF NOT EXISTS forme_pagamento (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(40) NOT NULL,
  ordine INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAGAMENTI (conto misto possibile)
CREATE TABLE IF NOT EXISTS pagamenti (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  conto_id           INT           NOT NULL,
  forma_pagamento_id INT           NOT NULL,
  importo            DECIMAL(10,2) NOT NULL,
  data               TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conto_id)           REFERENCES conti(id),
  FOREIGN KEY (forma_pagamento_id) REFERENCES forme_pagamento(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CLIENTI (anagrafica per le fatture)
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

-- DOCUMENTI emessi: scontrini (Epson RT), fatture (numerate per anno), proforma
CREATE TABLE IF NOT EXISTS documenti (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tipo         ENUM('scontrino','fattura','proforma') NOT NULL,
  conto_id     INT           NULL,
  numero       INT           NULL,
  anno         SMALLINT      NULL,
  data         DATETIME      NOT NULL,
  totale       DECIMAL(10,2) NOT NULL,
  pagamento_id INT           NULL,
  cliente_id   INT           NULL,
  stato        ENUM('attesa','emesso','errore') NOT NULL DEFAULT 'emesso',
  rt_numero    VARCHAR(20)   NULL,
  rt_zreport   VARCHAR(10)   NULL,
  errore       VARCHAR(255)  NULL,
  operatore_id INT           NULL,
  UNIQUE KEY (tipo, anno, numero),
  FOREIGN KEY (conto_id)     REFERENCES conti(id) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id)   REFERENCES clienti(id),
  FOREIGN KEY (pagamento_id) REFERENCES forme_pagamento(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CODA DI STAMPA per il "ponte" (sito online -> PC del locale -> stampanti e registratore telematico)
CREATE TABLE IF NOT EXISTS coda_stampa (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tipo             ENUM('stampa','rt') NOT NULL DEFAULT 'stampa',
  stampante_id     INT          NULL,                  -- NULL per i lavori del registratore telematico
  documento_id     INT          NULL,
  descrizione      VARCHAR(100) NOT NULL,
  dati             MEDIUMTEXT   NOT NULL,              -- byte ESC/POS (o XML per la Epson RT) in base64
  stato            ENUM('attesa','stampata','errore') NOT NULL DEFAULT 'attesa',
  tentativi        INT          NOT NULL DEFAULT 0,
  errore           VARCHAR(255) NULL,
  risposta         TEXT         NULL,                  -- risposta del registratore telematico
  creato           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_tentativo DATETIME     NULL,
  stampato         DATETIME     NULL,
  KEY (stato, id),
  FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE RAPIDE delle righe (finestra Note): con una stampante, il prodotto con quella nota esce solo lì
CREATE TABLE IF NOT EXISTS note_rapide (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  testo        VARCHAR(30) NOT NULL,
  stampante_id INT         NULL,                 -- NULL = il prodotto esce sulla sua stampante solita
  ordine       INT         NOT NULL DEFAULT 0,
  attiva       TINYINT(1)  NOT NULL DEFAULT 1,
  FOREIGN KEY (stampante_id) REFERENCES stampanti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TENTATIVI DI LOGIN sbagliati (blocco dopo troppi errori dallo stesso IP)
CREATE TABLE IF NOT EXISTS login_tentativi (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  ip      VARCHAR(45) NOT NULL,
  creato  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (ip, creato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MIGRAZIONI già applicate (aggiornamento automatico, vedi includes/migrazioni.php).
-- Ogni nuova migrazione va riportata anche qui sotto, negli INSERT INTO migrazioni.
CREATE TABLE IF NOT EXISTS migrazioni (
  nome     VARCHAR(100) PRIMARY KEY,
  eseguita TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPOSTAZIONI (chiave/valore)
CREATE TABLE IF NOT EXISTS impostazioni (
  chiave VARCHAR(50)  PRIMARY KEY,
  valore VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
--  DATI DI ESEMPIO
-- ============================================================
INSERT INTO operatori (nome, pin, ruolo) VALUES
  ('Admin','0000','admin'),
  ('Samadi','1111','cassa'),
  ('Mario','2222','cameriere');

INSERT INTO sale (nome, ordine) VALUES
  ('Sala Interna',1), ('Veranda',2), ('Pedana',3), ('Dehor',4);

-- Tavoli come nello screenshot della Sala Interna
INSERT INTO tavoli (sala_id,nome,posti,pos_x,pos_y,larghezza,altezza,forma) VALUES
  (1,'3',4,  20,120,70,90,'rettangolo'),
  (1,'2',4, 360,120,120,170,'tondo'),
  (1,'1',4, 720,120,90,90,'rettangolo'),
  (1,'4',4,  10,330,80,150,'tondo'),
  (1,'5',4, 360,380,90,90,'rettangolo'),
  (1,'6',4,  20,560,70,90,'rettangolo'),
  (1,'11',4,540,560,150,80,'rettangolo'),
  (1,'10',4,940,540,100,150,'tondo'),
  (1,'7',4,  20,760,70,90,'rettangolo'),
  (1,'8',6, 540,760,220,100,'rettangolo'),
  (1,'9',2, 940,760,80,90,'rettangolo');

INSERT INTO categorie (nome,colore,ordine,destinazione) VALUES
  ('PIZZE','#d35400',1,'pizzeria'),
  ('PIZZE SPEC','#c0392b',2,'pizzeria'),
  ('CALZONI','#7f8c8d',3,'pizzeria'),
  ('FOCACCE','#e67e22',4,'pizzeria'),
  ('ANTIPASTI','#e74c3c',5,'cucina'),
  ('INSALATE','#27ae60',6,'cucina'),
  ('BEVANDE','#2980b9',7,'bar'),
  ('BIRRE','#f39c12',8,'bar'),
  ('VINI','#8e44ad',9,'bar'),
  ('DOLCI','#9b59b6',10,'cucina'),
  ('CAFFETTERIA','#795548',11,'bar');

-- Pizze (con colori come nello screenshot)
INSERT INTO articoli (categoria_id,nome,prezzo,colore) VALUES
  (1,'BUFALA',9.00,'#ffffff'),
  (1,'MARGHERITA',6.00,'#f1c40f'),
  (1,'MARINARA',5.00,'#e67e22'),
  (1,'NAPOLI',7.00,'#9b8cf0'),
  (1,'ROMANA',7.00,'#1abc9c'),
  (1,'DIAVOLA',9.00,'#e74c3c'),
  (1,'CAPRICCIOSA',9.00,'#e74c3c'),
  (1,'4 FORMAGGI',9.00,'#ffffff'),
  (1,'VEGETARIANA',9.90,'#2ecc71'),
  (1,'PORCINI',10.00,'#2ecc71'),
  (1,'CRUDO',9.20,'#e74c3c'),
  (1,'FUNGHI',8.00,'#2ecc71'),
  (1,'WURSTEL',8.00,'#e74c3c'),
  (1,'CARCIOFI',8.00,'#2ecc71');

INSERT INTO articoli (categoria_id,nome,prezzo,colore) VALUES
  (7,'Acqua 0,5L',1.50,'#aed6f1'),
  (7,'Coca-Cola',3.00,'#aed6f1'),
  (8,'Birra Media',4.50,'#f8c471'),
  (5,'Bruschetta',5.00,'#f5b7b1'),
  (10,'Tiramisù',5.00,'#d7bde2');

-- Migrazioni già comprese in questo file
INSERT IGNORE INTO migrazioni (nome) VALUES
  ('001_asporto.sql'), ('002_note_conto.sql'), ('003_tavoli_attivi.sql'), ('004_login_tentativi.sql'),
  ('005_stampanti.sql'), ('006_coda_stampa.sql'), ('007_stampante_articolo.sql'),
  ('008_varianti.sql'), ('009_stampanti_seriali.sql'),
  ('010_documenti.sql'), ('011_charset_utf8mb4.sql'), ('012_vendita_a_peso.sql'), ('013_note_rapide.sql'),
  ('014_posto_banco.sql'), ('015_destinazioni_stampanti.sql');

INSERT INTO note_rapide (testo, ordine) VALUES
  ('INTEGRALE', 1), ('BIANCA', 2), ('ROSSA', 3), ('BEN COTTA', 4), ('CELIACO', 5);

-- Varianti di esempio: ingredienti per pizze, calzoni e focacce
INSERT INTO varianti (nome, prezzo, ordine) VALUES
  ('Mozzarella', 1.00, 1), ('Mozzarella di bufala', 2.00, 2), ('Pomodoro', 0.50, 3),
  ('Prosciutto cotto', 1.50, 4), ('Prosciutto crudo', 2.00, 5), ('Salame piccante', 1.50, 6),
  ('Wurstel', 1.00, 7), ('Funghi', 1.00, 8), ('Carciofi', 1.00, 9), ('Olive', 0.50, 10),
  ('Acciughe', 1.00, 11), ('Cipolla', 0.50, 12), ('Basilico', 0.00, 13), ('Origano', 0.00, 14),
  ('Aglio', 0.00, 15), ('Ben cotta', 0.00, 16);

INSERT INTO varianti_categorie (variante_id, categoria_id)
  SELECT v.id, c.id FROM varianti v JOIN categorie c
  ON c.nome IN ('PIZZE', 'PIZZE SPEC', 'CALZONI', 'FOCACCE');

INSERT INTO forme_pagamento (nome,ordine) VALUES
  ('Contanti',1), ('Carta',2), ('Buoni pasto',3);

INSERT INTO impostazioni (chiave,valore) VALUES
  ('nome_locale','PIZZERIA BUGLIANI SNC'),
  ('numero_cassa','1'),
  ('coperto','0.00'),
  ('valuta','€');
