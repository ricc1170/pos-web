# POS stile TouchEat — versione web (PHP + MySQL)

Gestionale POS per ristorazione, accessibile da browser (PC cassa, tablet Android camerieri, monitor cucina).

## Requisiti
- PHP 8.0+ e MySQL/MariaDB. Il modo più semplice è **XAMPP** (https://www.apachefriends.org).

## Installazione (con XAMPP)
1. Installa e avvia **XAMPP**. Dal pannello di controllo avvia **Apache** e **MySQL**.
2. Copia la cartella `pos` dentro `C:\xampp\htdocs\` (percorso finale: `C:\xampp\htdocs\pos`).
3. Apri il browser su `http://localhost/phpmyadmin` → scheda **Importa** → scegli il file `database.sql` → **Esegui**. Crea il database `pos` con dati di esempio.
   Se il database era già stato creato con una versione precedente, importa anche i file in `migrazioni/` non ancora eseguiti (in ordine).
4. Copia `config.example.php` in `config.php` (non è su Git: ognuno ha il suo). Se le credenziali MySQL non sono quelle di default, modificale lì.
5. Vai su `http://localhost/pos/` → appare il login.

## Installazione online
Vedi `C:\xampp\pos-online\ISTRUZIONI.md` (cartella fuori dal sito: contiene le credenziali del database online).

## Accesso di prova (PIN, solo installazione locale con database.sql)
- `0000` → Admin
- `1111` → Cassa (Samadi)
- `2222` → Cameriere (Mario)

## Accesso dagli altri dispositivi (tablet, cucina)
Sul PC server trova il suo IP locale (es. `192.168.1.10`) con il comando `ipconfig`.
Dagli altri dispositivi apri `http://192.168.1.10/pos/` nel browser.
(Potrebbe servire aprire la porta 80 nel firewall di Windows.)

## Stato del progetto (tappe)
- [x] **Tappa 1** — Login operatore + mappa tavoli (sala.php)
- [x] **Tappa 2** — Presa comanda: categorie, prodotti, conto (comanda.php + api/conto.php)
- [ ] **Tappa 3** — Cassa: pagamento, chiusura conto
- [ ] **Tappa 4** — Stampa comanda / scontrino: spostata nel programma desktop della cassa (il sito non stampa)

## Struttura file
```
pos/
├── config.example.php    modello di config.php (credenziali database, non su Git)
├── database.sql          schema + dati di esempio
├── index.php             login (PIN)
├── sala.php              mappa tavoli
├── comanda.php           presa comanda
├── impostazioni.php      pagina unica delle impostazioni (solo admin)
├── operatori.php         gestione operatori e PIN
├── categorie.php         gestione categorie
├── generali.php          dati del locale (nome, cassa, coperto, valuta)
├── articoli.php          gestione prodotti (solo admin)
├── bar.php               elenco comande Bar parcheggiate (asporto)
├── tavoli.php            gestione sale e tavoli (solo admin)
├── migrazioni/           aggiornamenti per database già installati
├── logout.php
├── api/
│   └── conto.php         operazioni sul conto (AJAX, JSON)
├── includes/
│   ├── db.php            connessione PDO + helper
│   └── auth.php          login/sessione operatore
└── assets/
    ├── css/style.css     tema grafico (stile TouchEat)
    └── js/comanda.js     logica schermata comanda
```
