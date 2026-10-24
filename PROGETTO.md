# PROGETTO — POS stile TouchEat (web, PHP + MySQL)

> File di contesto per riprendere il lavoro (anche da una nuova sessione Claude, es. in VS Code).
> Aggiornare questo file a fine di ogni tappa.

## Obiettivo
Ricreare una versione **semplificata** di un gestionale/POS per ristorazione, ispirato a **OneTouchEat / TouchEat** (software VB6 + Access di Telema srl, protetto da chiave hardware HASP — NON si tocca la protezione, si costruisce software nuovo da zero).
Si parte dalla **logica e dall'aspetto** di TouchEat, senza copiarne il codice.

## Vincoli e scelte decise
- **Stack**: PHP + MySQL (richiesto dall'utente). Ambiente consigliato: **XAMPP**.
- **Architettura**: **app web** (un backend su un PC "server", gli altri dispositivi via browser in LAN).
  Motivo: i **camerieri usano tablet Android** → un .exe Windows non andrebbe. L'app web gira su Android, PC cassa e monitor cucina senza installazioni.
- **Database**: MySQL/MariaDB (un solo backend gestisce la concorrenza).
- **Interfaccia**: il più possibile **simile a TouchEat** (tema blu, grandi pulsanti colorati, totale in verde, mappa tavoli con forme tonde/rettangolari).
- **Utente**: livello programmazione intermedio, usa VS Code. Preferisce costruire capendo.

## Flusso dell'applicazione (dai 5 screenshot reali)
1. **Mappa tavoli** (`sala.php`): tab delle sale in alto (Sala Interna, Veranda, Pedana, Dehor), tavoli come forme posizionate; barra strumenti a destra (Bar, Apre Tav, Incasso, Cambio Tav, Info Sala...).
2. Click su un tavolo → **schermata categorie** (BANCO): colonne di pulsanti categoria (PIZZE, PIZZE SPEC, CALZONI, FOCACCE, ANTIPASTI, INSALATE, BEVANDE, BIRRE, VINI, DOLCI, CAFFETTERIA...), pannello conto a destra, tastierino numerico, totale verde.
3. Click su categoria → **griglia prodotti colorati** (es. pizze: BUFALA, MARGHERITA, MARINARA, DIAVOLA, CAPRICCIOSA...). Toccare un prodotto lo aggiunge al conto.
4. **Cassa/Banco**: conto con righe (Qtà, Descrizione, Prezzo), totale, pulsanti Pagare, %Sconto, Abbuono, Separa, C.Diviso, Segue, Ins.Riga, Note, Intesta, coperto.

Dettagli header TouchEat reale: "TouchEat - Rel. 10.6.6 - SN#:00977 - Int.: PIZZERIA BUGLIANI SNC - Cassa: 2", riga con Utente / Data Lavoro / Data Sistema / ora.

## Schema database (file `database.sql`)
Tabelle: `operatori` (login PIN), `sale`, `tavoli` (con forma/dimensioni/posizione per la piantina), `categorie`, `articoli` (con colore pulsante), `conti` (tavolo aperto), `righe` (prodotti ordinati, con campo `invio` per raggruppare le comande), `forme_pagamento`, `pagamenti` (conto misto), `stampanti` (IP di rete ESC/POS), `impostazioni` (chiave/valore).

## Struttura file
```
pos/
├── config.php            credenziali DB
├── database.sql          schema + dati di esempio
├── index.php             login PIN (fatto)
├── sala.php              mappa tavoli (fatto)
├── comanda.php           presa comanda: categorie, prodotti, conto (fatto)
├── impostazioni.php      pagina unica delle impostazioni (solo admin): riquadri verso le pagine qui sotto
├── operatori.php         operatori: nome, PIN (4–6 cifre, mai mostrato), ruolo, attivo. Se ha comande → disattivato. Sempre ≥1 admin attivo; non si può declassare/disattivare se stessi
├── categorie.php         categorie: nome, colore, ordine, destinazione (cucina/bar/nessuna). Non si cancella se contiene prodotti
├── varianti.php          varianti/ingredienti: nome, prezzo aggiunta, categorie (o tutte), ordine, attiva
├── stampanti.php         stampanti di rete: nome, IP, porta, tipo (cucina/bar/cassa), carta (larghezza in caratteri), attiva, prova di stampa
├── includes/stampa.php   ESC/POS: invia_a_stampante() via socket IP:porta (timeout 4 s), testo_stampa() (UTF-8 → cp1252), fine_stampa() (avanza 5 + taglio), scontrino_prova()
├── generali.php          dati del locale (tabella impostazioni): nome, numero cassa, coperto, valuta
├── articoli.php          gestione prodotti, solo admin (inserisci/modifica/cancella; se già venduto → nascosto)
├── bar.php               elenco comande Bar parcheggiate (per orario di ritiro)
├── tavoli.php            gestione sale e tavoli, solo admin (piantina con trascinamento; tavolo con conti → nascosto)
├── install.php           installazione su database vuoto (online): tabelle + operatori con PIN scelti. Da cancellare dopo l'uso
├── web.config            regole per server IIS (Aruba): pagina iniziale, blocco cartelle interne e file .sql/.md
├── migrazioni/           script SQL per aggiornare database già installati (001_asporto … 012_vendita_a_peso)
├── logout.php
├── api/conto.php         AJAX JSON: stato, aggiungi, quantita, elimina, nota, coperti, invia, parcheggia, annulla
├── api/desktop.php       JSON per il programma desktop Python: login, sala, info_sala, menu, parcheggi, cambio (vedi sotto)
├── includes/db.php       PDO + helper: db(), q(), impostazione(), h()
├── includes/auth.php     login/sessione: login_pin(), richiedi_login(), operatore_loggato(), richiedi_admin(), controlla_csrf(), pin_valido(), pin_debole()
├── assets/css/style.css  tema grafico stile TouchEat
└── assets/js/comanda.js  logica schermata comanda (chiama api/conto.php e ridisegna il conto)
```
Convenzioni: funzione `q($sql,$params)` per query preparate; `h()` per l'output sicuro; `impostazione('chiave')` per la config.

## Stato tappe
- [x] **Tappa 1** — Login PIN + mappa tavoli dinamica (tavoli occupati in rosso con totale).
- [x] **Tappa 2** — Presa comanda: categorie → prodotti → pannello conto (aggiungi/togli righe, quantità, note, coperti). Aggiornamento conto via AJAX (`api/conto.php`).

### Regole decise in Tappa 2
- Il conto si crea al primo articolo (o coperto) e si elimina se torna vuoto → il tavolo torna libero.
- Le righe si compongono (`invio = 0`) e si mandano in cucina col tasto **Invia**: ricevono `invio = N` (N-esima comanda del conto). Uscendo con righe non inviate viene chiesto se inviarle.
- Stesso articolo non ancora inviato e senza note → aumenta la quantità invece di creare una nuova riga.
- Righe già inviate: le può modificare/eliminare solo `cassa` o `admin` (il cameriere no).
- Tastierino: numero digitato + tocco su prodotto = quantità; numero + "Cop." = coperti.
- Totale conto = somma righe + coperti × impostazione `coperto`.
- Ogni scrittura blocca la riga del tavolo (`SELECT … FOR UPDATE`) per evitare doppi conti da due tablet.

### Comande Bar / asporto (parcheggio)
- Pulsante **Bar** in sala → `comanda.php?bar=1`: stessa schermata del tavolo, senza tavolo. La comanda nasce nel DB al primo articolo (poi l'URL diventa `comanda.php?conto=N`).
- Finiti i prodotti → pulsante **Parcheggio** → finestra con orario di ritiro (obbligatorio, pulsanti ogni 15 min o campo libero), nome cliente, note, casella "Invia in cucina" (spuntata) → torna alla sala.
- Pulsante **Parcheggi (N)** in sala → `bar.php`: elenco comande Bar aperte per orario (rosse se in ritardo, grigie "Da parcheggiare" se lasciate senza orario). Toccandone una si riapre; Parcheggio di nuovo per cambiare orario/nome/note.
- **Banco multi-operatore** (stessa cassa, a turno): in cima al pannello un tasto per ogni operatore attivo con il numero di articoli in corso. Ogni operatore ha la sua comanda in corso = `conti` asporto aperto con `orario_ritiro` NULL e `operatore_id` = lui (`api/conto.php` `{bar:true, banco:N}`). Il tocco sul nome cambia solo a chi vanno gli articoli; permessi e sessione restano di chi ha fatto login. L'operatore scelto si ricorda nel browser (`localStorage.banco_operatore`).
- Dopo Parcheggia/Elimina si resta al banco con una comanda vuota per lo stesso operatore. Uscendo dal banco non si chiede nulla (le comande in corso restano sotto i tasti). Pulsanti in basso: Sala, Invia, Parcheggio, Richiama (N parcheggiate). Tastierino nascosto, si apre con "🔢 Tastierino".
- Nel DB è un `conti` con `tipo='asporto'`, `tavolo_id` NULL, `orario_ritiro`, `nome_cliente`, `note`. `api/conto.php` accetta `{bar: true}` (nuova) o `{conto: N}` al posto di `{tavolo: N}`; azioni extra `parcheggia`, `annulla`.
- Differenze dal tavolo: niente coperti; se si tolgono tutti gli articoli la comanda resta (si elimina dalla finestra Parcheggio). Eliminare una comanda già inviata: solo cassa/admin.
- Orario prima di adesso di oltre 3 ore → si intende il giorno dopo (ordini dopo mezzanotte).

### Menu reale (migrazione 011, 23/10/2026)
- Dal "menu agosto 2026 SARZANA.pdf": 13 categorie (PIZZE, PIZZE SPEC, PIZZE BIANCHE, CALZONI, FOCACCE, SPECIALITA,
  ANTIPASTI, INSALATE, BEVANDE, BIRRE, VINI, DOLCI, CAFFETTERIA), 102 prodotti a IVA 10%, prodotti di prova nascosti,
  "Per i più sfiziosi" come varianti (bufala DOP 2,90, senza lattosio 2,00, burrata 3,50, integrale 1,50), coperto 1,80.
- Migrazione di DATI: non è segnata in database.sql (le installazioni nuove la eseguono). Niente tabelle temporanee
  (permessi su hosting condiviso): elenchi con UNION ALL. Non modellati: "Pizza baby -20%", allergeni e ingredienti.

### Cambio tavolo (`includes/cambio_tavolo.php`, `sala.php`)
- Pulsante **Cambio Tav** in sala → `sala.php?cambio=1` (tocca il tavolo occupato) → `&da=N` (tocca la destinazione, anche
  in altra sala: i parametri passano nelle schede) → POST `azione=cambio`.
- Destinazione libera: il conto cambia `tavolo_id`. Occupata: conferma e **unione** (righe e documenti al conto di
  destinazione, comande inviate rinumerate dopo le sue, coperti sommati, totale ricalcolato, conto origine eliminato).
- Blocco dei due tavoli in ordine di id (FOR UPDATE). Vietato se c'è uno scontrino in corso.
- Se c'erano comande inviate: foglietto "CAMBIO TAVOLO a -> b" / "UNIONE TAVOLI" sulle stampanti di quei prodotti.

### Info Sala (`sala.php`)
- Finestra sopra la piantina: occupati / liberi / coperti / totale aperto della sala; elenco tavoli occupati con coperti,
  totale, "aperto da", ultima comanda, operatore, avviso righe da inviare (tocco = apre la comanda); riepilogo di tutto
  il locale con le comande Bar. Minuti calcolati in SQL (TIMESTAMPDIFF con NOW()) per evitare differenze di fuso orario.

### Varianti (migrazione 008)
- Tabelle `varianti` (nome, prezzo, tutte, ordine, attiva), `varianti_categorie` (per quali categorie), `righe_varianti`
  (copia di nome/segno/prezzo sulla riga), `righe.prezzo_varianti` (somma aggiunte per pezzo). Totale riga = q × (prezzo_unitario + prezzo_varianti).
- In comanda (modo principale, come TouchEat): pulsante **VARIANTI** nella colonna categorie → al posto dei prodotti i pulsanti
  delle varianti della categoria dell'ultimo prodotto inserito (o della riga toccata). Tocco = "+"; "− SENZA" prima del tocco,
  o subito dopo, = "senza"; ritocco della stessa variante = la toglie. Categoria = si torna ai prodotti.
  API: azione `variante {riga, variante, segno}` (`imposta_variante()`); `aggiungi` restituisce `riga` (id dell'ultima riga).
- In alternativa: riga → **Varianti** (sotto il conto) → finestra con − e + per ogni ingrediente. Valgono per tutta la riga.
  Una riga con varianti non si somma con le aggiunte successive dello stesso prodotto. Righe inviate: solo cassa/admin.
- `api/conto.php` azione `varianti {riga, scelte:[{id, segno}]}` (`salva_varianti()` accetta solo varianti attive della categoria).
  Nello stato `prezzo_unitario` è già comprensivo delle aggiunte; ogni riga ha `varianti` e `categoria_id`.
- Stampa: sotto il prodotto "+ NOME" / "- NOME" in grassetto. Elenco di esempio (16 ingredienti pizze) nella migrazione e in database.sql.

### Gestione sale e tavoli (`tavoli.php`, solo admin)
- Pulsante **Tavoli** in sala (solo admin). Tab delle sale + "Nuova sala"; piantina con griglia.
- Tavoli: trascinamento (mouse/touch, griglia 10px) salva subito `pos_x/pos_y`; tocco = scheda (nome, posti, sala, forma, larghezza/altezza, visibile).
- Nome tavolo unico tra i tavoli attivi (la comanda lo mostra senza sala). Nuovo tavolo = numero più alto + 1.
- Cancellare un tavolo: vietato se ha un conto aperto; se ha conti passati viene nascosto (`tavoli.attivo = 0`), altrimenti cancellato. `sala.php` mostra solo i tavoli attivi.
- Cancellare una sala: solo se non contiene tavoli (nemmeno nascosti).
- [~] **Tappa 3** — Cassa. FATTO (migrazione 010, `includes/documenti.php`): tasti **Proforma** (preconto su stampante
  Cassa, tutti) · **Scontrino** (Epson RT, solo cassa/admin) · **Fattura** (`fattura.php`, FatturaPA XML) nella comanda;
  pagina **Fiscale** (dati azienda, Epson IP/reparti/operatore, verifica, ultima fattura esterna dell'anno) e **Documenti**.
  - Scontrino: `xml_scontrino()` (fpmate.cgi, SOAP printerFiscalReceipt). Diretto se in LAN, altrimenti coda `tipo='rt'`
    consegnata UNA volta al ponte versione ≥2 (header X-Ponte-Versione); il conto si chiude SOLO quando la Epson risponde
    success="true" (`registra_esito_rt`). Non consegnati in 2 min o senza risposta in 60 s → errore (mai ristampati da soli).
  - Pagamenti Epson: contanti 0/0, carta 2/1, buoni 3/1 (`PAGAMENTI_EPSON`) — DA VERIFICARE sulla cassa vera.
  - Modalità cassa `rt_modo`: spenta / **simulata** (in uso ora: `scontrino_simulato()` numeri SIM-n, conto chiuso subito,
    foglio "NON FISCALE" sulla stampante Cassa) / epson. La Epson del locale forse è collegata in seriale: se non ha
    porta di rete servirà il software Epson sul PC o il protocollo seriale nativo (da decidere col modello esatto).
  - Fattura: numero progressivo per anno (+ `fattura_ultimo_<anno>`), scorporo IVA per riga, XML validato con lo schema
    ufficiale FatturaPA 1.2.2; copia di cortesia stampata. L'utente carica l'XML sul portale AdE.
  - Mancano: pagamento misto, sconto/abbuono, nota di credito, chiusura giornaliera (Z) dalla cassa.
- [ ] **Tappa 4** — Stampa comanda cucina / scontrino su stampanti ESC/POS di rete (socket IP:9100).
  Configurazione stampanti già fatta (`stampanti.php`, migrazione 005).
  **Ponte di stampa** (fatto, migrazione 006): l'utente lavora ONLINE. `stampa($st, $dati, $descr)` in `includes/stampa.php`
  stampa diretto se il server è nella LAN, altrimenti mette in `coda_stampa` (base64). Sul PC del locale gira `ponte.ps1`
  (scaricato da Stampanti, con URL + chiave dentro; modello in `includes/ponte-modello.ps1`) che ogni 2 s chiama
  `api/ponte.php?azione=prendi` (header `X-Ponte-Chiave`), invia a IP:porta e riporta `esito`. Errori: riprova ogni 10 s,
  dopo 30 tentativi → "errore". Stato in pagina Stampanti (collegato se contatto < 30 s). Per provarlo in locale: impostazione `stampa_ponte` = 1.
  ⚠ Non usare Invoke-RestMethod nel ponte: in PowerShell 5 tiene aperte le connessioni e dopo 2 richieste si blocca per sempre
  (successo davvero). Il ponte usa `HttpWebRequest` con KeepAlive=false e timeout, disattiva la "modalità selezione" della
  console (un clic nella finestra la congela), forza https per il sito online e scrive `%LOCALAPPDATA%\PontePOS\ponte.log`.
  Avvio automatico: `installa-ponte.bat` (= `ponte.ps1 -Installa`) copia in `%LOCALAPPDATA%\PontePOS` e crea il collegamento in
  Esecuzione automatica (finestra ridotta a icona); `-Rimuovi` lo toglie; mutex `Local\PontePOS` = una sola copia.
  Un sito non può avviare programmi sul PC: al login la sala mostra un avviso rosso se il ponte non è collegato
  (`api/stato_ponte.php`, ricontrollo ogni 10 s, sparisce da solo).
  Le future stampe di comande/scontrini devono passare SEMPRE da `stampa()`.
  **Seriali (solo base, migrazione 009)**: `stampanti.collegamento` rete/seriale + `com`, `baud`, `flusso`; le seriali vanno sempre
  al ponte (`via_ponte()`), che scrive sulla porta COM (`Invia-Seriale`) e segnala le porte del PC (`X-Ponte-Porte` → `ponte_porte`).
  MANCA il modulo in `stampanti.php` per scegliere "Seriale": rimandato (23/10/2026). Il terminale Android H10-1 con stampante
  seriale interna (115200 8N1) NON è usabile dal browser: servirebbe un'app Android; l'utente ha scelto di restare sul PC Windows.
  **Stampante per prodotto** (migrazione 007): `articoli.stampante_id` (NULL = automatica: prima stampante attiva del tipo = destinazione
  della categoria) e `articoli.stampa` (0 = non stampare). La regola sta in `stampante_articolo($art)` in `includes/stampa.php`:
  usarla per smistare le righe della comanda tra le stampanti.
  **Stampa comande** (fatto): `invia_righe()` in `api/conto.php` segna `$da_stampare`; DOPO il commit `stampa_comanda($conto, $invio)`
  raggruppa le righe per stampante e manda un foglio per stampante (`foglio_comanda()`: TAVOLO n / ASPORTO hh:mm / BAR, comanda N,
  ora, operatore, coperti, righe "Q x PRODOTTO" doppia altezza + note, "ARTICOLI: N" = somma quantità del foglio, note conto). La risposta JSON ha `stampa: {ok, testo}`
  mostrato in basso (rosso se problemi). Vale per Invia, uscita con "inviare?" e Parcheggio con "Invia in cucina".
  Da fare: ristampa di una comanda, stampa conto/scontrino (cassa).
  Stampante del locale: **Bixolon SRP-350/352 Plus III**, 192.168.0.77:9100, ESC/POS RAW TCP, cp1252, 32 caratteri/riga (16 a doppia dimensione),
  taglio = `1B 64 05` + `1D 56 00`. ⚠ Nella rete del locale (192.168.1.x) la stampante che funziona col ponte è a **192.168.1.100**
  (verificato il 23/10/2026: stampa riuscita online tramite ponte); 192.168.0.77 era un'altra rete. ⚠️ Il sito online (Aruba) non raggiunge le stampanti della LAN del locale:
  per questo c'è il ponte di stampa (sotto).
- [ ] **Tappa 5** — Sincronizzazione multi-postazione (polling AJAX dei tavoli/conti).

## Tutto schermo
- I browser non permettono il tutto schermo senza un tocco, e il tutto schermo "di pagina" si chiude a ogni cambio pagina.
- **App installabile (PWA)**: `manifest.json` (display fullscreen, icone `assets/icona-192/512.png`) + `sw.js` (vuoto, nessuna cache).
  Tablet: "Aggiungi a schermata Home"/"Installa app" → si apre a tutto schermo e resta così. Tag in `<head>` di tutte le pagine.
- `assets/js/schermo.js` (in tutte le pagine): registra sw.js. Il tutto schermo automatico al primo tocco è stato TOLTO su
  richiesta dell'utente (23/10/2026); restano app installabile e "POS a tutto schermo.bat", usati solo se scelti.
- PC: **POS a tutto schermo.bat** (modello `includes/schermo-modello.bat`, scaricabile da Stampanti; copia in `C:\xampp\pos-online\`):
  avvia il ponte (`%LOCALAPPDATA%\PontePOS\ponte.ps1` o quello accanto al .bat; il mutex evita doppioni) e apre il sito
  in modalità chiosco (Chrome/Edge/Firefox; uscita Alt+F4). I file scaricati usano sempre https quando il sito non è in LAN.
- `web.config`: mimeMap per .json (remove + add, così non va in conflitto su IIS).
- Nuove pagine: copiare il blocco di `<head>` (manifest, meta, schermo.js) dalle altre.

## Versione online (www.bugliani.it/pos/)
- Hosting Aruba Windows (IIS). Il DB online (`Sql729538_3`) accetta connessioni solo dal server Aruba, non da questo PC.
- Credenziali online e script di preparazione FUORI da htdocs: `C:\xampp\pos-online\` (`config.online.php`, `prepara.ps1`, `ISTRUZIONI.md`).
- Sicurezza: dopo 5 PIN sbagliati in 10 minuti l'IP è bloccato (`login_tentativi`); PIN 4–6 cifre scelti in install.php; nessun PIN di prova mostrato al login; cookie di sessione httponly/samesite (secure in HTTPS); errori DB senza dettagli a video.
- Le migrazioni non contengono più `USE pos`: si importano sul database selezionato.

### Aggiornamenti (si lavora in locale, poi si porta online)
- **Database automatico**: `includes/migrazioni.php` (chiamato da `db()`) applica i file `migrazioni/NNN_*.sql` non ancora presenti nella tabella `migrazioni`. Online basta caricare i file.
- ⚠ Il database Aruba crea le tabelle in **latin1** se non si specifica: ogni `CREATE TABLE` deve finire con
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` (altrimenti "Illegal mix of collations" nei confronti
  con il testo del POS). La migrazione `011_charset_utf8mb4.sql` ha convertito le tabelle esistenti (successo il 23/10/2026).
- **Regola per ogni nuova migrazione**: crea `migrazioni/NNN_nome.sql` (SQL compatibile MySQL 5.5: niente `DATETIME DEFAULT CURRENT_TIMESTAMP`, usare `TIMESTAMP`) e riporta la stessa modifica in `database.sql` + il nome negli `INSERT IGNORE INTO migrazioni`.
- **File**: `C:\xampp\pos-online\prepara.ps1 -Aggiornamento` mette in `aggiornamento\` solo i file cambiati rispetto all'ultimo caricamento; dopo il caricamento `prepara.ps1 -Caricato` registra lo stato online (`caricati.json`). `install.php` non viene mai incluso.

## Versione desktop Python (iniziata il 23/10/2026)
- Cartella: `C:\Users\ricca\SynologyDrive\python _vs_code\pos\` (main.py, config.ini, avvia-pos.bat, pacchetto `pos_desktop/`).
  Programma Windows con finestre PySide6 (Qt), stesso aspetto TouchEat. Tablet e sito restano come sono.
- **Non apre MySQL**: il DB Aruba accetta solo il server del sito. Il programma parla in HTTPS col sito:
  `api/desktop.php` (login PIN, dati delle schermate) + `api/conto.php` (tutte le operazioni sul conto, stesse regole dei tablet).
  Sessione PHP nel cookie di `requests.Session`. Ogni richiesta a desktop.php ha l'intestazione `X-POS-Desktop: 1` (anti-CSRF).
- Schermate: login · sala (sale, piantina, Bar, Parcheggi, Cambio Tav con unione, Info Sala, aggiornamento ogni 10 s) ·
  comanda (categorie, prodotti, griglia VARIANTI con − SENZA, finestra Varianti, note, coperti, tastierino, Invia, Proforma,
  Scontrino con attesa della cassa, banco multi-operatore, Parcheggio, Richiama) · parcheggi (apri / elimina).
  Operatore del banco ricordato in QSettings (registro di Windows) invece di localStorage.
- Restano sul sito (si aprono nel browser): Impostazioni (admin) e Fattura. Le stampe continuano a passare dal ponte (ponte.ps1).
- Librerie: `PySide6-Essentials` + `requests` (il pacchetto PySide6 completo non si installa col Python del Microsoft Store:
  percorsi troppo lunghi). Per provare in locale: in config.ini `url = http://localhost/`.
- **Bilancia e vendita a peso** (24/10/2026, migrazione 012): `articoli.a_peso` (ogni prodotto è a prezzo fisso OPPURE a peso: scelta
  "Tipo di prezzo" in Articoli, l'etichetta diventa "Prezzo al kg"),
  `righe.quantita` DECIMAL(8,3) = kg. `api/conto.php` `aggiungi {articolo, peso}`: una riga per pesata, mai sommata;
  + e − vietati sulle righe a peso (si elimina e si ripesa). Totale conto = somma delle righe ARROTONDATE al centesimo
  (ROUND per riga, come la cassa Epson). Fogli: "0,474 kg PIZZA AL TAGLIO", nel conteggio ARTICOLI vale 1.
  FatturaPA: Quantita con 3 decimali se serve. Tablet: peso scritto a mano (prompt in kg).
  Desktop: `pos_desktop/bilancia.py` (driver Dini Argeo secondo COME_FUNZIONA_LA_BILANCIA.md: READ/ZERO/TARE/CLEAR,
  9600 8N1, parser che non solleva mai eccezioni, simulatore, thread `LettoreBilancia` con code: la finestra non tocca la
  seriale), `pesata.py` (barra peso + Zero/Tara/C.Tara sopra i prodotti, finestra di pesata con "A mano" in grammi).
  Tocco su un prodotto a peso (scelta dell'utente, 24/10/2026): il peso ATTUALE va subito nel conto, anche instabile
  (avviso rosso "peso ancora in movimento"); la finestra di pesata si apre solo con piatto vuoto, OL, bilancia muta o
  assente. `frame.valido()` = > 0 e non OL; `vendibile()` = valido e stabile (non più usato per accettare). config.ini `[bilancia] porta = COM3 | SIM | vuota`. Test: `test_bilancia.py`.
  Da verificare sulla bilancia vera: testo della conferma a ZERO/TARE/CLEAR, porta COM.
  **Gestione bilancia** (`pos_desktop/impostazioni.py`): tasto Impostazioni della sala (admin) → scelta "Impostazioni del
  sito" (browser) / "Bilancia". Pagina Bilancia: peso dal vivo + riga grezza ricevuta, Zero/Tara/Cancella tara con esito
  ("la bilancia ha risposto" / "nessuna risposta"), porte COM trovate (pyserial list_ports) + Simulatore + Nessuna, baud.
  "Salva e collega" scrive solo porta/baud in config.ini (commenti conservati) e riapre la bilancia senza riavviare
  (`FinestraPOS.imposta_bilancia`, barra della comanda ricreata).
- Da fare: ponte di stampa dentro il programma Python (al posto di ponte.ps1), chiamate di rete in background (ora bloccano
  la finestra per il tempo della risposta), modifica delle impostazioni senza browser.

## Domande aperte
- Confermare che il flusso "componi e poi Invia" corrisponde a TouchEat (scelto in Tappa 2; la stampa cucina della Tappa 4 partirà dal tasto Invia).

## Accessi di prova
PIN: 0000 (admin), 1111 (cassa/Samadi), 2222 (cameriere/Mario).
```
