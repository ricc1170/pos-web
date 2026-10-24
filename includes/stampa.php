<?php
// ============================================================
//  Stampa su stampanti termiche di rete (ESC/POS su RAW TCP, porta 9100)
//  Il server PHP apre una connessione TCP verso IP:porta e invia i byte.
//  Funziona solo se il server è nella stessa rete delle stampanti
//  (POS installato su un PC del locale), non dal sito online.
//
//  Comandi tarati sulla Bixolon SRP-350/352 Plus III del locale:
//  testo in cp1252, 32 caratteri per riga (16 a dimensione doppia).
// ============================================================

const STAMPA_TIMEOUT = 4;              // secondi

const ESC_INIT          = "\x1B\x40";      // inizializza
const ESC_SINISTRA      = "\x1B\x61\x00";
const ESC_CENTRO        = "\x1B\x61\x01";
const ESC_GRASSETTO_ON  = "\x1B\x45\x01";
const ESC_GRASSETTO_OFF = "\x1B\x45\x00";
const ESC_NORMALE       = "\x1D\x21\x00";
const ESC_GRANDE        = "\x1D\x21\x11";  // doppia altezza + larghezza (metà caratteri per riga)
const ESC_ALTO          = "\x1D\x21\x01";  // solo doppia altezza
const ESC_LARGO         = "\x1D\x21\x10";  // solo doppia larghezza
const ESC_STRETTO       = "\x1B\x4D\x01";  // font stretto
const ESC_AVANZA5       = "\x1B\x64\x05";  // avanza 5 righe
const ESC_TAGLIO        = "\x1D\x56\x00";  // taglio carta

// Testo UTF-8 -> cp1252 (accenti ed € compresi). I caratteri che la stampante
// non ha vengono resi con l'equivalente più vicino, o con '?'.
function testo_stampa(string $s): string {
    $s = strtr($s, ['·' => '-']);
    $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
    return $r === false ? preg_replace('/[^\x20-\x7E\n]/', '?', $s) : $r;
}

// Quantità per i fogli: "2", "1,5" oppure, per i prodotti a peso, "0,474 kg"
function testo_quantita(float $q, bool $a_peso = false): string {
    if ($a_peso) return number_format($q, 3, ',', '') . ' kg';
    return $q == (int)$q ? (string)(int)$q : rtrim(rtrim(number_format($q, 3, ',', ''), '0'), ',');
}

// Fine stampa: avanza la carta e taglia
function fine_stampa(): string {
    return ESC_AVANZA5 . ESC_TAGLIO;
}

// Indirizzo della rete interna (192.168.x.x, 10.x.x.x, 172.16-31.x.x, localhost)?
function ip_privato(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP)
        && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Il POS gira su un server su internet (es. sito online) e la stampante è nella rete del locale:
// la stampa diretta è impossibile
function stampa_da_internet(string $ip_stampante): bool {
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    return ip_privato($ip_stampante) && $server !== '' && !ip_privato($server);
}

// ------------------------------------------------------------
//  Ponte di stampa: dal sito online le stampe vanno in coda (coda_stampa)
//  e il programma ponte.ps1 sul PC del locale le invia alle stampanti.
// ------------------------------------------------------------
const PONTE_MAX_TENTATIVI = 30;   // circa 5 minuti di tentativi (uno ogni 10 s)

function salva_impostazione(string $chiave, string $valore) {
    q('INSERT INTO impostazioni (chiave, valore) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE valore = VALUES(valore)', [$chiave, $valore]);
}

// Si passa dal ponte se lo dice config.php (STAMPA_PONTE, sito online), se forzato con
// l'impostazione stampa_ponte = 1, o se il server risulta su internet
function usa_ponte(string $ip_stampante): bool {
    return (defined('STAMPA_PONTE') && STAMPA_PONTE)
        || impostazione('stampa_ponte') === '1'
        || stampa_da_internet($ip_stampante);
}

// Chiave segreta con cui il ponte si fa riconoscere (creata al primo uso)
function chiave_ponte(): string {
    $k = (string)impostazione('ponte_chiave', '');
    if ($k === '') {
        $k = bin2hex(random_bytes(16));
        salva_impostazione('ponte_chiave', $k);
    }
    return $k;
}

// Il ponte ha chiesto lavori negli ultimi 30 secondi?
function ponte_collegato(): bool {
    $visto = impostazione('ponte_visto');
    return $visto && time() - strtotime($visto) < 30;
}

// La stampante passa dal ponte? Sempre se è seriale (è collegata alla porta COM del PC del ponte)
function via_ponte(array $st): bool {
    return ($st['collegamento'] ?? 'rete') === 'seriale' || usa_ponte((string)$st['ip']);
}

// Il ponte serve? (sito online, o almeno una stampante seriale attiva)
function serve_ponte(): bool {
    return usa_ponte('192.168.0.1')
        || (bool)q('SELECT 1 FROM stampanti WHERE attiva = 1 AND collegamento = "seriale" LIMIT 1')->fetch();
}

// Descrizione del collegamento: "192.168.1.100:9100" oppure "COM3 · 9600 baud"
function collegamento_stampante(array $st): string {
    return ($st['collegamento'] ?? 'rete') === 'seriale'
        ? "{$st['com']} · {$st['baud']} baud"
        : "{$st['ip']}:{$st['porta']}";
}

// Stampa diretta o tramite ponte. Restituisce [riuscita, messaggio]
function stampa(array $st, string $dati, string $descrizione): array {
    if (via_ponte($st)) {
        q('INSERT INTO coda_stampa (stampante_id, descrizione, dati) VALUES (?, ?, ?)',
          [$st['id'], mb_substr($descrizione, 0, 100), base64_encode($dati)]);
        return ponte_collegato()
            ? [true, "\"$descrizione\" inviata al ponte di stampa: esce tra pochi secondi su \"{$st['nome']}\"."]
            : [true, "\"$descrizione\" messa in coda, ma il ponte di stampa non è collegato: "
                   . 'uscirà quando avvii il ponte sul PC del locale.'];
    }
    $errore = invia_a_stampante($st['ip'], (int)$st['porta'], $dati);
    return [$errore === '', $errore ?: "\"$descrizione\" stampata su \"{$st['nome']}\"."];
}

// Stampante su cui esce un prodotto (riga di articoli con stampa, stampante_id, categoria_id), o null.
//  - stampa = 0           -> non si stampa
//  - stampante scelta     -> quella, se attiva
//  - automatica (NULL)    -> prima stampante attiva del tipo della categoria (cucina / bar)
function stampante_articolo(array $art): ?array {
    if (empty($art['stampa'])) return null;
    if (!empty($art['stampante_id'])) {
        $st = q('SELECT * FROM stampanti WHERE id = ? AND attiva = 1', [$art['stampante_id']])->fetch();
        if ($st) return $st;
    }
    $st = q('SELECT s.* FROM categorie c JOIN stampanti s ON s.tipo = c.destinazione AND s.attiva = 1
             WHERE c.id = ? ORDER BY s.id LIMIT 1', [$art['categoria_id']])->fetch();
    return $st ?: null;
}

// Stampante imposta dalla nota della riga (Impostazioni > Note rapide), o null.
// Vale la prima nota rapida attiva, con stampante attiva, scritta nella nota come parola intera
// (maiuscole o minuscole: anche "celiaco" scritto a mano dai tablet).
function stampante_nota(?string $nota): ?array {
    static $regole = null;
    if (trim((string)$nota) === '') return null;
    if ($regole === null) {
        $regole = q('SELECT n.testo, s.* FROM note_rapide n JOIN stampanti s ON s.id = n.stampante_id AND s.attiva = 1
                     WHERE n.attiva = 1 ORDER BY n.ordine, n.id')->fetchAll();
    }
    foreach ($regole as $r) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($r['testo'], '/') . '(?![\p{L}\p{N}])/iu', $nota)) return $r;
    }
    return null;
}

// ------------------------------------------------------------
//  Comanda di cucina/bar
// ------------------------------------------------------------

// Converte per la stampante (cp1252: un byte per carattere, così le lunghezze tornano) e spezza
// in righe lunghe al massimo $larghezza; le righe seguenti rientrano di $rientro spazi
function a_capo(string $testo, int $larghezza, int $rientro = 0): string {
    $righe = explode("\n", wordwrap(testo_stampa($testo), $larghezza, "\n", true));
    $pad = str_repeat(' ', $rientro);
    $out = array_shift($righe);
    foreach ($righe as $r) {
        $out .= "\n" . $pad . wordwrap($r, $larghezza - $rientro, "\n" . $pad, true);
    }
    return $out . "\n";
}

// Foglietto della comanda per una stampante: intestazione (tavolo / asporto / bar) + righe
function foglio_comanda(array $st, array $conto, array $righe, int $invio, string $operatore): string {
    $w   = (int)$st['larghezza'];
    $sep = str_repeat('-', $w) . "\n";

    if ($conto['tipo'] === 'tavolo') {
        $titolo = 'TAVOLO ' . $conto['tavolo'];
        $sotto  = $conto['sala'];
    } elseif ($conto['orario_ritiro']) {
        $titolo = 'ASPORTO ' . date('H:i', strtotime($conto['orario_ritiro']));
        $sotto  = (string)$conto['nome_cliente'];
    } else {
        $titolo = 'BAR';
        $sotto  = '';
    }

    $out = ESC_INIT . ESC_CENTRO
         . ESC_GRANDE . a_capo($titolo, intdiv($w, 2)) . ESC_NORMALE
         . ($sotto !== '' ? ESC_GRASSETTO_ON . a_capo($sotto, $w) . ESC_GRASSETTO_OFF : '')
         . a_capo("Comanda $invio · " . date('d/m H:i') . " · $operatore", $w)
         . ($conto['tipo'] === 'tavolo' && $conto['coperti'] ? "Coperti: {$conto['coperti']}\n" : '')
         . ESC_SINISTRA . $sep;

    $pezzi = 0;
    foreach ($righe as $r) {
        $a_peso = !empty($r['a_peso']);
        // Un prodotto a peso è un articolo solo (la quantità sono kg)
        $pezzi += $a_peso ? 1 : (float)$r['quantita'];
        // "2 x MARGHERITA" oppure "0,474 kg PIZZA AL TAGLIO"
        $q = testo_quantita((float)$r['quantita'], $a_peso) . ($a_peso ? ' ' : ' x ');
        $out .= ESC_ALTO . ESC_GRASSETTO_ON
              . a_capo($q . $r['descrizione'], $w, strlen($q))
              . ESC_GRASSETTO_OFF . ESC_NORMALE;
        // Varianti in grassetto, ben visibili: "+ BUFALA", "- BASILICO"
        foreach ($r['varianti'] ?? [] as $v) {
            $out .= ESC_GRASSETTO_ON . a_capo('   ' . $v['segno'] . ' ' . mb_strtoupper($v['nome']), $w, 5) . ESC_GRASSETTO_OFF;
        }
        if ($r['note']) {
            $out .= ESC_GRASSETTO_ON . a_capo('   >> ' . $r['note'], $w, 6) . ESC_GRASSETTO_OFF;
        }
    }

    // Numero di articoli di questo foglio (somma delle quantità), per il controllo in cucina/bar
    $out .= $sep
          . ESC_GRASSETTO_ON . a_capo('ARTICOLI: ' . testo_quantita($pezzi), $w) . ESC_GRASSETTO_OFF
          . $sep;
    if ($conto['note']) {
        $out .= ESC_GRASSETTO_ON . a_capo('NOTE: ' . $conto['note'], $w) . ESC_GRASSETTO_OFF;
    }
    return $out . fine_stampa();
}

// Stampa le righe della comanda numero $invio del conto, ognuna sulla stampante del suo prodotto.
// Restituisce [tutto_ok, messaggio per l'operatore]
function stampa_comanda(int $conto_id, int $invio): array {
    $conto = q('SELECT c.*, t.nome AS tavolo, s.nome AS sala
                FROM conti c LEFT JOIN tavoli t ON t.id = c.tavolo_id LEFT JOIN sale s ON s.id = t.sala_id
                WHERE c.id = ?', [$conto_id])->fetch();
    $righe = q('SELECT r.id, r.quantita, r.descrizione, r.note, r.operatore_id,
                       a.stampa, a.stampante_id, a.categoria_id, a.a_peso
                FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id
                WHERE r.conto_id = ? AND r.invio = ? ORDER BY r.id', [$conto_id, $invio])->fetchAll();
    if (!$conto || !$righe) return [true, ''];

    foreach ($righe as &$r) {
        $r['varianti'] = q('SELECT segno, nome FROM righe_varianti WHERE riga_id = ? ORDER BY segno = "-", id',
                           [$r['id']])->fetchAll();
    }
    unset($r);

    $operatore = (string)q('SELECT nome FROM operatori WHERE id = ?', [$righe[0]['operatore_id']])->fetchColumn();

    // Raggruppo le righe per stampante
    $gruppi = [];
    $senza  = [];
    foreach ($righe as $r) {
        if ($r['categoria_id'] === null) continue;   // riga libera senza prodotto
        // Una nota come CELIACO può mandare il prodotto su un'altra stampante (solo lì)
        $st = empty($r['stampa']) ? null : (stampante_nota($r['note']) ?: stampante_articolo($r));
        if ($st) {
            $gruppi[$st['id']]['st'] = $st;
            $gruppi[$st['id']]['righe'][] = $r;
        } elseif ($r['stampa']) {
            $senza[] = $r['descrizione'];
        }
    }

    $ok = true;
    $esiti = [];
    foreach ($gruppi as $g) {
        [$riuscita, $testo] = stampa($g['st'], foglio_comanda($g['st'], $conto, $g['righe'], $invio, $operatore),
                                     "Comanda $invio");
        $ok = $ok && $riuscita;
        $esiti[] = $riuscita ? "✔ {$g['st']['nome']}" : "✖ {$g['st']['nome']}: $testo";
    }
    if ($senza) {
        $ok = false;
        $esiti[] = 'Senza stampante: ' . implode(', ', array_unique($senza));
    }
    if (!$esiti) return [true, 'Comanda inviata (nessun prodotto da stampare)'];
    $pendenti = serve_ponte() && !ponte_collegato() ? ' (ponte di stampa NON collegato: in coda)' : '';
    return [$ok, 'Comanda inviata — ' . implode(' · ', $esiti) . $pendenti];
}

// Invia byte grezzi alla stampante. Restituisce '' se ok, altrimenti il messaggio d'errore.
function invia_a_stampante(string $ip, int $porta, string $dati, int $timeout = STAMPA_TIMEOUT): string {
    $sock = @fsockopen($ip, $porta, $errno, $errstr, $timeout);
    if (!$sock) {
        // WSAEACCES (10013): il server non ha il permesso di aprire connessioni verso l'esterno (hosting)
        if (stampa_da_internet($ip) || $errno == 10013) {
            return "Il sito online non può stampare su $ip: la stampante è nella rete del locale e il server è su internet. "
                 . 'Fai la prova dal POS installato sul PC del locale.';
        }
        return "Stampante $ip:$porta non raggiungibile" . ($errstr ? " ($errstr)" : '')
             . '. Controlla che sia accesa, collegata alla rete e che IP e porta siano giusti.';
    }
    stream_set_timeout($sock, $timeout);
    $scritti = fwrite($sock, $dati);
    fclose($sock);
    return $scritti === strlen($dati) ? '' : "Invio interrotto verso $ip:$porta";
}

// Foglietto di prova con i dati della stampante
function scontrino_prova(array $st): string {
    $riga = str_repeat('-', (int)$st['larghezza']);
    return ESC_INIT
         . ESC_CENTRO . ESC_GRANDE . "PROVA\n" . ESC_NORMALE
         . ESC_GRASSETTO_ON . testo_stampa((string)impostazione('nome_locale', 'POS')) . "\n" . ESC_GRASSETTO_OFF
         . $riga . "\n"
         . ESC_SINISTRA
         . testo_stampa("Stampante: {$st['nome']}\n")
         . "Tipo:      {$st['tipo']}\n"
         . "Collegam.: " . collegamento_stampante($st) . "\n"
         . "Riga:      {$st['larghezza']} caratteri\n"
         . 'Data:      ' . date('d/m/Y H:i') . "\n"
         . testo_stampa("Accenti:   à è é ì ò ù €\n")
         . $riga . "\n"
         . ESC_CENTRO . testo_stampa("Se leggi questo foglio\nla stampante è configurata bene.\n")
         . fine_stampa();
}
