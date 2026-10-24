-- ============================================================
--  Migrazione 011 - Menu della pizzeria (agosto 2026, "menu agosto 2026 SARZANA.pdf")
--  - categorie del menu (create se mancano) e loro ordine
--  - prodotti di prova nascosti (restano nei conti già fatti)
--  - tutti i prodotti del menu con i prezzi (IVA 10%)
--  - "Per i più sfiziosi" come varianti; "Servizio al tavolo 1,80" come coperto
--  Dati, non struttura: NON è segnata in database.sql, così anche le installazioni nuove la eseguono.
--  Niente tabelle temporanee (sugli hosting condivisi possono mancare i permessi): elenchi con UNION ALL.
-- ============================================================

-- Categorie mancanti
INSERT INTO categorie (nome, colore, ordine, destinazione)
  SELECT m.nome, m.colore, m.ordine, m.destinazione FROM (
    SELECT 'PIZZE' AS nome, '#d35400' AS colore, 1 AS ordine, 'cucina' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE SPEC' AS nome, '#c0392b' AS colore, 2 AS ordine, 'cucina' AS destinazione, '#fadbd8' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS nome, '#b9770e' AS colore, 3 AS ordine, 'cucina' AS destinazione, '#fdfefe' AS colore_prodotti
    UNION ALL
    SELECT 'CALZONI' AS nome, '#7f8c8d' AS colore, 4 AS ordine, 'cucina' AS destinazione, '#e5e8e8' AS colore_prodotti
    UNION ALL
    SELECT 'FOCACCE' AS nome, '#e67e22' AS colore, 5 AS ordine, 'cucina' AS destinazione, '#fae5d3' AS colore_prodotti
    UNION ALL
    SELECT 'SPECIALITA' AS nome, '#16a085' AS colore, 6 AS ordine, 'cucina' AS destinazione, '#d1f2eb' AS colore_prodotti
    UNION ALL
    SELECT 'ANTIPASTI' AS nome, '#e74c3c' AS colore, 7 AS ordine, 'cucina' AS destinazione, '#f5b7b1' AS colore_prodotti
    UNION ALL
    SELECT 'INSALATE' AS nome, '#27ae60' AS colore, 8 AS ordine, 'cucina' AS destinazione, '#d5f5e3' AS colore_prodotti
    UNION ALL
    SELECT 'BEVANDE' AS nome, '#2980b9' AS colore, 9 AS ordine, 'bar' AS destinazione, '#d6eaf8' AS colore_prodotti
    UNION ALL
    SELECT 'BIRRE' AS nome, '#f39c12' AS colore, 10 AS ordine, 'bar' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'VINI' AS nome, '#8e44ad' AS colore, 11 AS ordine, 'bar' AS destinazione, '#e8daef' AS colore_prodotti
    UNION ALL
    SELECT 'DOLCI' AS nome, '#9b59b6' AS colore, 12 AS ordine, 'cucina' AS destinazione, '#ebdef0' AS colore_prodotti
    UNION ALL
    SELECT 'CAFFETTERIA' AS nome, '#795548' AS colore, 13 AS ordine, 'bar' AS destinazione, '#edbb99' AS colore_prodotti
  ) m
  WHERE NOT EXISTS (SELECT 1 FROM categorie c WHERE c.nome = m.nome);

-- Ordine delle categorie come nel menu
UPDATE categorie c JOIN (
    SELECT 'PIZZE' AS nome, '#d35400' AS colore, 1 AS ordine, 'cucina' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE SPEC' AS nome, '#c0392b' AS colore, 2 AS ordine, 'cucina' AS destinazione, '#fadbd8' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS nome, '#b9770e' AS colore, 3 AS ordine, 'cucina' AS destinazione, '#fdfefe' AS colore_prodotti
    UNION ALL
    SELECT 'CALZONI' AS nome, '#7f8c8d' AS colore, 4 AS ordine, 'cucina' AS destinazione, '#e5e8e8' AS colore_prodotti
    UNION ALL
    SELECT 'FOCACCE' AS nome, '#e67e22' AS colore, 5 AS ordine, 'cucina' AS destinazione, '#fae5d3' AS colore_prodotti
    UNION ALL
    SELECT 'SPECIALITA' AS nome, '#16a085' AS colore, 6 AS ordine, 'cucina' AS destinazione, '#d1f2eb' AS colore_prodotti
    UNION ALL
    SELECT 'ANTIPASTI' AS nome, '#e74c3c' AS colore, 7 AS ordine, 'cucina' AS destinazione, '#f5b7b1' AS colore_prodotti
    UNION ALL
    SELECT 'INSALATE' AS nome, '#27ae60' AS colore, 8 AS ordine, 'cucina' AS destinazione, '#d5f5e3' AS colore_prodotti
    UNION ALL
    SELECT 'BEVANDE' AS nome, '#2980b9' AS colore, 9 AS ordine, 'bar' AS destinazione, '#d6eaf8' AS colore_prodotti
    UNION ALL
    SELECT 'BIRRE' AS nome, '#f39c12' AS colore, 10 AS ordine, 'bar' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'VINI' AS nome, '#8e44ad' AS colore, 11 AS ordine, 'bar' AS destinazione, '#e8daef' AS colore_prodotti
    UNION ALL
    SELECT 'DOLCI' AS nome, '#9b59b6' AS colore, 12 AS ordine, 'cucina' AS destinazione, '#ebdef0' AS colore_prodotti
    UNION ALL
    SELECT 'CAFFETTERIA' AS nome, '#795548' AS colore, 13 AS ordine, 'bar' AS destinazione, '#edbb99' AS colore_prodotti
  ) m ON m.nome = c.nome
  SET c.ordine = m.ordine;

-- Prodotti di prova dell'installazione: nascosti
UPDATE articoli SET visibile = 0
  WHERE nome IN ('BUFALA','MARGHERITA','MARINARA','NAPOLI','ROMANA','DIAVOLA','CAPRICCIOSA','4 FORMAGGI',
                 'VEGETARIANA','PORCINI','CRUDO','FUNGHI','WURSTEL','CARCIOFI','Acqua 0,5L','Coca-Cola',
                 'Birra Media','Bruschetta','Tiramisù');

-- Prodotti del menu
INSERT INTO articoli (categoria_id, nome, prezzo, aliquota_iva, colore, ordine, visibile)
  SELECT c.id, p.nome, p.prezzo, 10.00, m.colore_prodotti, p.ordine, 1
  FROM (
    SELECT 'PIZZE' AS cat, 'MARGHERITA' AS nome, 6.20 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'MARINARA' AS nome, 6.10 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'NAPOLI' AS nome, 7.90 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'ROMANA' AS nome, 7.90 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'DIAVOLA' AS nome, 9.00 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, '4 STAGIONI' AS nome, 9.00 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'CAPRICCIOSA' AS nome, 9.00 AS prezzo, 7 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, '4 FORMAGGI' AS nome, 9.90 AS prezzo, 8 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'VEGETARIANA' AS nome, 9.90 AS prezzo, 9 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'SPECK & MASCARPONE' AS nome, 9.90 AS prezzo, 10 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'CRUDO & MASCARPONE' AS nome, 9.90 AS prezzo, 11 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'PORCINI' AS nome, 9.10 AS prezzo, 12 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'COTTO' AS nome, 9.10 AS prezzo, 13 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'COTTO & FUNGHI' AS nome, 8.90 AS prezzo, 14 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'CRUDO DI PARMA' AS nome, 9.20 AS prezzo, 15 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'CRUDO & PORCINI' AS nome, 9.90 AS prezzo, 16 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'SPECK & CARCIOFI' AS nome, 9.00 AS prezzo, 17 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'WURSTEL' AS nome, 8.50 AS prezzo, 18 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'COTTO & WURSTEL' AS nome, 9.00 AS prezzo, 19 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'SALSICCIA' AS nome, 9.00 AS prezzo, 20 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'TONNO & CIPOLLE' AS nome, 9.00 AS prezzo, 21 AS ordine
    UNION ALL
    SELECT 'PIZZE' AS cat, 'SORRENTO' AS nome, 9.90 AS prezzo, 22 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'XXV APRILE' AS nome, 9.80 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'SAN GIORGIO' AS nome, 9.80 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'CALANDRINA' AS nome, 9.90 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'MASCARDI' AS nome, 9.90 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'MATTEOTTI' AS nome, 9.90 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'LANDINELLI' AS nome, 9.90 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'CISA' AS nome, 9.90 AS prezzo, 7 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'FIASELLA' AS nome, 11.00 AS prezzo, 8 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'GORI' AS nome, 9.90 AS prezzo, 9 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'GARIBALDI' AS nome, 9.90 AS prezzo, 10 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'PIZZATA LIGURE' AS nome, 9.90 AS prezzo, 11 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'FRATTA' AS nome, 9.90 AS prezzo, 12 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'BRADIA' AS nome, 9.90 AS prezzo, 13 AS ordine
    UNION ALL
    SELECT 'PIZZE SPEC' AS cat, 'PARADISO' AS nome, 11.00 AS prezzo, 14 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'SOTTO LA PASSERELLA' AS nome, 9.90 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'SARZANELLO' AS nome, 8.70 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'GRISEI' AS nome, 8.70 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'NAVE' AS nome, 8.70 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'BERSAGION' AS nome, 9.00 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS cat, 'MAZZINI' AS nome, 8.70 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'CALZONI' AS cat, 'CALZONE CLASSICO' AS nome, 9.50 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'CALZONI' AS cat, 'CALZONE BEPPE' AS nome, 9.90 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'CALZONI' AS cat, 'CALZONE FANTASIA' AS nome, 9.90 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'CALZONI' AS cat, 'CALZONE VESUVIO' AS nome, 10.00 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA CLASSICA' AS nome, 10.00 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA COTTO' AS nome, 10.00 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA TONNO' AS nome, 10.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA GAMBERETTI' AS nome, 10.00 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA 4 FORMAGGI' AS nome, 10.00 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'FOCACCE' AS cat, 'FOCACCIA SALMONE' AS nome, 10.00 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'FARINATA' AS nome, 4.60 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'FARINATA STRACCHINO' AS nome, 5.00 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'FARINATA GORGONZOLA' AS nome, 5.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'FARINATA AL PESTO' AS nome, 5.20 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'TORTA DI RISO' AS nome, 3.90 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'SPECIALITA' AS cat, 'PIZZA AL TAGLIO' AS nome, 4.50 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'TAGLIERE DELLE DELIZIE' AS nome, 16.00 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CACCIATORE' AS nome, 11.00 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'INSALATA CAPRESE' AS nome, 11.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CARPACCIO DI BRESAOLA' AS nome, 13.00 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CROSTINO AL POMODORO' AS nome, 5.00 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CROSTINO ALLA RUCOLA' AS nome, 5.00 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CROSTINO ALLO SPECK' AS nome, 5.00 AS prezzo, 7 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'CROSTINO AL SALMONE' AS nome, 6.00 AS prezzo, 8 AS ordine
    UNION ALL
    SELECT 'ANTIPASTI' AS cat, 'PIATTO AFFETTATI BABY' AS nome, 5.50 AS prezzo, 9 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'INSALATA VERDE' AS nome, 8.00 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'INSALATA MISTA' AS nome, 8.00 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'INSALATA CON CIPOLLA' AS nome, 9.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'VERDUROSA' AS nome, 9.90 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'MEDITERRANEA' AS nome, 8.00 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'INSALATE' AS cat, 'FANTASIA DI MELE' AS nome, 9.90 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'BEVANDE' AS cat, 'ACQUA 1 LT' AS nome, 2.00 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'BEVANDE' AS cat, 'ACQUA 1/2 LT' AS nome, 1.60 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'BEVANDE' AS cat, 'BIBITA LATTINA 33CL' AS nome, 3.50 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'BEVANDE' AS cat, 'COCA COLA SPINA 33CL' AS nome, 3.90 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'BIONDA MORETTI 20CL' AS nome, 4.40 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'BIONDA MORETTI 40CL' AS nome, 5.50 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'BIONDA MORETTI 1,5L' AS nome, 15.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ROSSA MORETTI 20CL' AS nome, 4.60 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ROSSA MORETTI 40CL' AS nome, 5.60 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ROSSA MORETTI 1,5L' AS nome, 15.40 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ICHNUSA N.F. 20CL' AS nome, 4.60 AS prezzo, 7 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ICHNUSA N.F. 40CL' AS nome, 5.60 AS prezzo, 8 AS ordine
    UNION ALL
    SELECT 'BIRRE' AS cat, 'ICHNUSA N.F. 1,5L' AS nome, 15.00 AS prezzo, 9 AS ordine
    UNION ALL
    SELECT 'VINI' AS cat, 'VINO 25 CL' AS nome, 3.30 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'VINI' AS cat, 'VINO 50 CL' AS nome, 5.50 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'VINI' AS cat, 'VINO 1 LT' AS nome, 8.50 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'PANNA COTTA' AS nome, 5.00 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'TIRAMISÙ' AS nome, 5.50 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'CANTUCCINI E VIN SANTO' AS nome, 5.50 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'BABÀ' AS nome, 6.00 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'SORBETTO LIMONE' AS nome, 4.50 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'DOLCI' AS cat, 'DOLCE DELLA CASA' AS nome, 5.50 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'CAFFÈ' AS nome, 1.30 AS prezzo, 1 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'CAFFÈ CORRETTO' AS nome, 2.20 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'CAFFÈ DECAFFEINATO' AS nome, 1.40 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'CAPPUCCINO' AS nome, 2.10 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'GINSENG' AS nome, 1.50 AS prezzo, 5 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'ORZO' AS nome, 1.50 AS prezzo, 6 AS ordine
    UNION ALL
    SELECT 'CAFFETTERIA' AS cat, 'AMARI' AS nome, 3.00 AS prezzo, 7 AS ordine
  ) p
  JOIN categorie c ON c.nome = p.cat
  JOIN (
    SELECT 'PIZZE' AS nome, '#d35400' AS colore, 1 AS ordine, 'cucina' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE SPEC' AS nome, '#c0392b' AS colore, 2 AS ordine, 'cucina' AS destinazione, '#fadbd8' AS colore_prodotti
    UNION ALL
    SELECT 'PIZZE BIANCHE' AS nome, '#b9770e' AS colore, 3 AS ordine, 'cucina' AS destinazione, '#fdfefe' AS colore_prodotti
    UNION ALL
    SELECT 'CALZONI' AS nome, '#7f8c8d' AS colore, 4 AS ordine, 'cucina' AS destinazione, '#e5e8e8' AS colore_prodotti
    UNION ALL
    SELECT 'FOCACCE' AS nome, '#e67e22' AS colore, 5 AS ordine, 'cucina' AS destinazione, '#fae5d3' AS colore_prodotti
    UNION ALL
    SELECT 'SPECIALITA' AS nome, '#16a085' AS colore, 6 AS ordine, 'cucina' AS destinazione, '#d1f2eb' AS colore_prodotti
    UNION ALL
    SELECT 'ANTIPASTI' AS nome, '#e74c3c' AS colore, 7 AS ordine, 'cucina' AS destinazione, '#f5b7b1' AS colore_prodotti
    UNION ALL
    SELECT 'INSALATE' AS nome, '#27ae60' AS colore, 8 AS ordine, 'cucina' AS destinazione, '#d5f5e3' AS colore_prodotti
    UNION ALL
    SELECT 'BEVANDE' AS nome, '#2980b9' AS colore, 9 AS ordine, 'bar' AS destinazione, '#d6eaf8' AS colore_prodotti
    UNION ALL
    SELECT 'BIRRE' AS nome, '#f39c12' AS colore, 10 AS ordine, 'bar' AS destinazione, '#fdebd0' AS colore_prodotti
    UNION ALL
    SELECT 'VINI' AS nome, '#8e44ad' AS colore, 11 AS ordine, 'bar' AS destinazione, '#e8daef' AS colore_prodotti
    UNION ALL
    SELECT 'DOLCI' AS nome, '#9b59b6' AS colore, 12 AS ordine, 'cucina' AS destinazione, '#ebdef0' AS colore_prodotti
    UNION ALL
    SELECT 'CAFFETTERIA' AS nome, '#795548' AS colore, 13 AS ordine, 'bar' AS destinazione, '#edbb99' AS colore_prodotti
  ) m ON m.nome = p.cat
  WHERE NOT EXISTS (SELECT 1 FROM articoli a WHERE a.categoria_id = c.id AND a.nome = p.nome AND a.visibile = 1);

-- "Per i più sfiziosi": varianti per pizze, calzoni e focacce
UPDATE varianti SET nome = 'Mozzarella di bufala DOP', prezzo = 2.90 WHERE nome = 'Mozzarella di bufala';

INSERT INTO varianti (nome, prezzo, ordine)
  SELECT m.nome, m.prezzo, m.ordine FROM (
    SELECT 'Mozzarella di bufala DOP' AS nome, 2.90 AS prezzo, 2 AS ordine
    UNION ALL
    SELECT 'Mozzarella senza lattosio' AS nome, 2.00 AS prezzo, 3 AS ordine
    UNION ALL
    SELECT 'Burrata DOP' AS nome, 3.50 AS prezzo, 4 AS ordine
    UNION ALL
    SELECT 'Impasto integrale' AS nome, 1.50 AS prezzo, 30 AS ordine
  ) m
  WHERE NOT EXISTS (SELECT 1 FROM varianti v WHERE v.nome = m.nome);

INSERT IGNORE INTO varianti_categorie (variante_id, categoria_id)
  SELECT v.id, c.id FROM varianti v
  JOIN categorie c ON c.nome IN ('PIZZE', 'PIZZE SPEC', 'PIZZE BIANCHE', 'CALZONI', 'FOCACCE')
  WHERE v.nome IN ('Mozzarella di bufala DOP', 'Mozzarella senza lattosio', 'Burrata DOP', 'Impasto integrale');

-- Le varianti delle pizze valgono anche per le pizze bianche
INSERT IGNORE INTO varianti_categorie (variante_id, categoria_id)
  SELECT vc.variante_id, cb.id
  FROM varianti_categorie vc
  JOIN categorie cp ON cp.id = vc.categoria_id AND cp.nome = 'PIZZE'
  JOIN categorie cb ON cb.nome = 'PIZZE BIANCHE';

-- Servizio al tavolo 1,80 a persona = coperto
UPDATE impostazioni SET valore = '1.80' WHERE chiave = 'coperto';