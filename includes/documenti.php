<?php
// ============================================================
//  Documenti: proforma (preconto), scontrino (Epson RT), fattura (FatturaPA)
//  e chiusura del conto. Usato da api/conto.php, fattura.php, api/ponte.php.
// ============================================================
require_once __DIR__ . '/stampa.php';

// Pagamenti -> codici Epson (paymentType/index) e FatturaPA (ModalitaPagamento)
const PAGAMENTI_EPSON = [1 => [0, 1], 2 => [2, 1], 3 => [3, 1]];          // contanti (0/1 verificato), carta, buoni pasto
const PAGAMENTI_FPA   = [1 => 'MP01', 2 => 'MP08', 3 => 'MP08'];

// ------------------------------------------------------------
//  Dati del conto
// ------------------------------------------------------------

// Conto con righe e varianti, pronto per documenti e stampe
function dati_conto(int $conto_id): ?array {
    $c = q('SELECT c.*, t.nome AS tavolo, s.nome AS sala
            FROM conti c LEFT JOIN tavoli t ON t.id = c.tavolo_id LEFT JOIN sale s ON s.id = t.sala_id
            WHERE c.id = ?', [$conto_id])->fetch();
    if (!$c) return null;
    $c['righe'] = q('SELECT r.id, r.descrizione, r.quantita, r.prezzo_unitario + r.prezzo_varianti AS prezzo, r.aliquota_iva,
                            r.note, COALESCE(a.a_peso, 0) AS a_peso
                     FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id
                     WHERE r.conto_id = ? ORDER BY r.id', [$conto_id])->fetchAll();
    foreach ($c['righe'] as &$r) {
        $r['varianti'] = q('SELECT segno, nome FROM righe_varianti WHERE riga_id = ? ORDER BY segno = "-", id',
                           [$r['id']])->fetchAll();
    }
    unset($r);
    $c['prezzo_coperto'] = (float)impostazione('coperto', 0);
    return $c;
}

// Titolo del conto: "Tavolo 5 - Sala Interna", "Asporto 20:15 Rossi", "Bar"
function titolo_conto(array $c): string {
    if ($c['tipo'] === 'tavolo') return 'Tavolo ' . $c['tavolo'] . ' - ' . $c['sala'];
    if ($c['orario_ritiro']) return trim('Asporto ' . date('H:i', strtotime($c['orario_ritiro'])) . ' ' . $c['nome_cliente']);
    return 'Bar';
}

// Descrizione di una riga con le varianti: "MARGHERITA +Bufala -Basilico"
function descrizione_riga(array $r): string {
    $d = $r['descrizione'];
    foreach ($r['varianti'] as $v) $d .= ' ' . $v['segno'] . $v['nome'];
    return $d;
}

// Il conto si chiude: pagamento registrato, tavolo libero / comanda Bar tolta dai parcheggi
function chiudi_conto(int $conto_id, int $pagamento_id) {
    $c = q('SELECT totale, stato FROM conti WHERE id = ?', [$conto_id])->fetch();
    if (!$c || $c['stato'] !== 'aperto') return;
    q('UPDATE conti SET stato = "chiuso", data_chiusura = NOW() WHERE id = ?', [$conto_id]);
    q('INSERT INTO pagamenti (conto_id, forma_pagamento_id, importo) VALUES (?, ?, ?)', [$conto_id, $pagamento_id, $c['totale']]);
}

// ------------------------------------------------------------
//  Stampe su carta: preconto (proforma) e copia di cortesia della fattura
// ------------------------------------------------------------

// Stampante per conti e documenti: la prima di tipo Cassa attiva, altrimenti la prima attiva
function stampante_cassa(): ?array {
    $st = q('SELECT * FROM stampanti WHERE attiva = 1 ORDER BY tipo = "cassa" DESC, id LIMIT 1')->fetch();
    return $st ?: null;
}

// Riga "descrizione ........ importo" larga $w caratteri
function riga_importo(string $sx, float $importo, int $w): string {
    $dx = number_format($importo, 2, ',', '.');
    $sx = testo_stampa($sx);
    $spazio = $w - strlen($dx) - 1;
    if (strlen($sx) > $spazio) {
        // Descrizione lunga: va a capo e l'importo finisce da solo, allineato a destra
        return wordwrap($sx, $w, "\n", true) . "\n" . str_pad($dx, $w, ' ', STR_PAD_LEFT) . "\n";
    }
    return str_pad($sx, $spazio) . ' ' . $dx . "\n";
}

// Corpo comune: righe del conto con importi, coperto, totale
function corpo_conto(array $c, int $w): string {
    $sep = str_repeat('-', $w) . "\n";
    $out = $sep;
    foreach ($c['righe'] as $r) {
        $q = (float)$r['quantita'];
        $out .= riga_importo(testo_quantita($q, (bool)$r['a_peso']) . ' x ' . $r['descrizione'], round($q * $r['prezzo'], 2), $w);
        foreach ($r['varianti'] as $v) $out .= a_capo('   ' . $v['segno'] . ' ' . $v['nome'], $w, 5);
    }
    if ($c['coperti'] && $c['prezzo_coperto'] > 0) {
        $out .= riga_importo("{$c['coperti']} x Coperto", $c['coperti'] * $c['prezzo_coperto'], $w);
    }
    $out .= $sep
          . ESC_GRASSETTO_ON . ESC_ALTO . riga_importo('TOTALE ' . impostazione('valuta', '€'), (float)$c['totale'], $w)
          . ESC_NORMALE . ESC_GRASSETTO_OFF;
    return $out;
}

function foglio_proforma(array $st, array $c): string {
    $w = (int)$st['larghezza'];
    return ESC_INIT . ESC_CENTRO
         . ESC_GRASSETTO_ON . a_capo((string)impostazione('nome_locale', ''), $w) . ESC_GRASSETTO_OFF
         . ESC_GRANDE . a_capo('PRECONTO', intdiv($w, 2)) . ESC_NORMALE
         . a_capo(titolo_conto($c) . ' - ' . date('d/m/Y H:i'), $w)
         . ESC_SINISTRA . corpo_conto($c, $w)
         . ESC_CENTRO . a_capo('Documento non fiscale', $w)
         . fine_stampa();
}

function foglio_cortesia_fattura(array $st, array $c, array $doc, array $cli): string {
    $w = (int)$st['larghezza'];
    return ESC_INIT . ESC_CENTRO
         . ESC_GRASSETTO_ON . a_capo((string)impostazione('azienda_denominazione', impostazione('nome_locale', '')), $w) . ESC_GRASSETTO_OFF
         . a_capo(impostazione('azienda_indirizzo', '') . ' - ' . impostazione('azienda_cap', '') . ' ' . impostazione('azienda_comune', ''), $w)
         . a_capo('P.IVA ' . impostazione('azienda_piva', ''), $w)
         . ESC_GRANDE . a_capo("FATTURA {$doc['numero']}/{$doc['anno']}", intdiv($w, 2)) . ESC_NORMALE
         . a_capo('del ' . date('d/m/Y', strtotime($doc['data'])), $w)
         . ESC_SINISTRA . a_capo('Cliente: ' . nome_cliente($cli), $w)
         . a_capo(($cli['piva'] ? 'P.IVA ' . $cli['piva'] : 'C.F. ' . $cli['cf']), $w)
         . corpo_conto($c, $w)
         . ESC_CENTRO . a_capo('Copia di cortesia: la fattura elettronica e\' quella inviata allo SDI', $w)
         . fine_stampa();
}

// Stampa un documento sulla stampante Cassa. Restituisce [riuscita, messaggio]
function stampa_su_cassa(string $descrizione, callable $foglio): array {
    $st = stampante_cassa();
    if (!$st) return [false, 'Nessuna stampante attiva per stampare il ' . strtolower($descrizione)];
    return stampa($st, $foglio($st), $descrizione);
}

// ------------------------------------------------------------
//  Scontrino su registratore telematico Epson (protocollo Fiscal ePOS-Print / fpmate.cgi)
// ------------------------------------------------------------

// Modalità della cassa: "spenta", "simulata" (prove: nessuno scontrino fiscale) o "epson" (registratore in rete)
function rt_modo(): string {
    $m = (string)impostazione('rt_modo', '');
    if ($m === '') $m = impostazione('rt_attivo') === '1' ? 'epson' : 'spenta';   // impostazione vecchia
    return in_array($m, ['spenta', 'simulata', 'epson'], true) ? $m : 'spenta';
}

function rt_simulata(): bool {
    return rt_modo() === 'simulata';
}

function rt_configurato(): bool {
    return rt_simulata()
        || (rt_modo() === 'epson' && filter_var((string)impostazione('rt_ip', ''), FILTER_VALIDATE_IP));
}

// Scontrino simulato: numero "SIM-n", conto chiuso subito, foglio NON fiscale sulla stampante Cassa
function scontrino_simulato(array $c, int $pagamento_id, int $operatore_id): array {
    $n = 1 + (int)q('SELECT COUNT(*) FROM documenti WHERE tipo = "scontrino" AND rt_numero LIKE "SIM-%"')->fetchColumn();
    q('INSERT INTO documenti (tipo, conto_id, data, totale, pagamento_id, stato, rt_numero, operatore_id)
       VALUES ("scontrino", ?, NOW(), ?, ?, "emesso", ?, ?)', [$c['id'], $c['totale'], $pagamento_id, "SIM-$n", $operatore_id]);
    $doc_id = (int)db()->lastInsertId();
    chiudi_conto((int)$c['id'], $pagamento_id);
    $pagamento = (string)q('SELECT nome FROM forme_pagamento WHERE id = ?', [$pagamento_id])->fetchColumn();
    $stampa = stampa_su_cassa('Scontrino simulato', function ($st) use ($c, $n, $pagamento) {
        $w = (int)$st['larghezza'];
        return ESC_INIT . ESC_CENTRO
             . ESC_GRASSETTO_ON . a_capo((string)impostazione('nome_locale', ''), $w) . ESC_GRASSETTO_OFF
             . ESC_GRANDE . a_capo('SIMULAZIONE', intdiv($w, 2)) . ESC_NORMALE
             . a_capo("Scontrino simulato n. SIM-$n - " . date('d/m/Y H:i'), $w)
             . a_capo(titolo_conto($c), $w)
             . ESC_SINISTRA . corpo_conto($c, $w)
             . a_capo('Pagamento: ' . $pagamento, $w)
             . ESC_CENTRO . ESC_GRASSETTO_ON . a_capo('NON FISCALE - SOLO PROVA', $w) . ESC_GRASSETTO_OFF
             . fine_stampa();
    });
    return [$doc_id, $stampa];
}

// Indirizzo del servizio fiscale della Epson (porta 80; "rt_porta" solo per prove)
function rt_url(): string {
    $porta = (int)impostazione('rt_porta', 80);
    return 'http://' . impostazione('rt_ip') . ($porta !== 80 ? ":$porta" : '') . '/cgi-bin/fpmate.cgi?devid=local_printer&timeout=10000';
}

// Reparto della cassa per un'aliquota IVA (impostazione rt_reparti "22=1;10=2;4=3;5=4", come nella cassa Epson)
function reparto_rt(float $aliquota): int {
    foreach (explode(';', (string)impostazione('rt_reparti', '22=1;10=2;4=3;5=4')) as $coppia) {
        [$a, $r] = array_pad(explode('=', $coppia), 2, null);
        if ($a !== null && abs((float)$a - $aliquota) < 0.001) return max(1, (int)$r);
    }
    return 1;
}

function xml_attr(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function importo_rt(float $n): string {
    return number_format($n, 2, ',', '');
}

function busta_epson(string $corpo): string {
    return '<?xml version="1.0" encoding="utf-8"?>'
         . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $corpo . '</s:Body></s:Envelope>';
}

// Scontrino: una riga per prodotto (prezzo comprensivo delle aggiunte), coperto, pagamento
function xml_scontrino(array $c, int $pagamento_id): string {
    $op = xml_attr((string)impostazione('rt_operatore', '1'));
    $x = "<printerFiscalReceipt><beginFiscalReceipt operator=\"$op\" />";
    foreach ($c['righe'] as $r) {
        $desc = substr(testo_stampa(descrizione_riga($r)), 0, 38);
        $q = rtrim(rtrim(number_format((float)$r['quantita'], 3, ',', ''), '0'), ',');
        $x .= '<printRecItem operator="' . $op . '" description="' . xml_attr($desc) . '" quantity="' . $q
            . '" unitPrice="' . importo_rt((float)$r['prezzo']) . '" department="' . reparto_rt((float)$r['aliquota_iva'])
            . '" justification="1" />';
    }
    if ($c['coperti'] && $c['prezzo_coperto'] > 0) {
        $x .= '<printRecItem operator="' . $op . '" description="COPERTO" quantity="' . (int)$c['coperti']
            . '" unitPrice="' . importo_rt($c['prezzo_coperto']) . '" department="' . reparto_rt(10) . '" justification="1" />';
    }
    [$tipo, $indice] = PAGAMENTI_EPSON[$pagamento_id] ?? [0, 0];
    $nome = (string)q('SELECT nome FROM forme_pagamento WHERE id = ?', [$pagamento_id])->fetchColumn();
    $x .= '<printRecTotal operator="' . $op . '" description="' . xml_attr(strtoupper(testo_stampa($nome))) . '" payment="'
        . importo_rt((float)$c['totale']) . '" paymentType="' . $tipo . '" index="' . $indice . '" justification="1" />'
        . "<endFiscalReceipt operator=\"$op\" /></printerFiscalReceipt>";
    return busta_epson($x);
}

// Interrogazione dello stato della cassa (pulsante "Verifica collegamento", non stampa niente)
function xml_stato_rt(): string {
    return busta_epson('<printerCommand><queryPrinterStatus operator="' . xml_attr((string)impostazione('rt_operatore', '1'))
                     . '" statusType="1" /></printerCommand>');
}

// Legge la risposta della Epson: [riuscito, numero scontrino, numero chiusura Z, messaggio]
function leggi_risposta_rt(string $xml): array {
    if (!preg_match('/<response\b[^>]*success="(true|false)"[^>]*>/i', $xml, $m)) {
        return [false, null, null, 'risposta non valida dalla cassa'];
    }
    $prendi = fn($tag) => preg_match("/<$tag>([^<]*)<\/$tag>/i", $xml, $t) ? trim($t[1]) : null;
    if (strtolower($m[1]) === 'true') {
        return [true, $prendi('fiscalReceiptNumber'), $prendi('zRepNumber'), 'ok'];
    }
    preg_match('/code="([^"]*)"/i', $m[0], $cod);
    preg_match('/status="([^"]*)"/i', $m[0], $sta);
    return [false, null, null, 'la cassa ha rifiutato lo scontrino (codice ' . ($cod[1] ?? '?') . ', stato ' . ($sta[1] ?? '?') . ')'];
}

// Esito dello scontrino: se la cassa l'ha emesso il conto si chiude
function registra_esito_rt(int $doc_id, bool $collegato, string $risposta, string $errore) {
    $doc = q('SELECT * FROM documenti WHERE id = ? AND stato = "attesa"', [$doc_id])->fetch();
    if (!$doc) return;
    [$ok, $numero, $z, $msg] = $collegato ? leggi_risposta_rt($risposta) : [false, null, null, $errore ?: 'cassa non raggiungibile'];
    if ($ok) {
        q('UPDATE documenti SET stato = "emesso", rt_numero = ?, rt_zreport = ?, errore = NULL WHERE id = ?', [$numero, $z, $doc_id]);
        if ($doc['conto_id']) chiudi_conto((int)$doc['conto_id'], (int)$doc['pagamento_id']);
    } else {
        q('UPDATE documenti SET stato = "errore", errore = ? WHERE id = ?', [mb_substr($msg, 0, 255), $doc_id]);
    }
}

// Scontrini consegnati al ponte da più di 60 secondi senza risposta: segnati come errore,
// perché non si sa se la cassa li ha stampati (va controllato prima di riprovare)
function scontrini_senza_risposta() {
    foreach (q('SELECT id, documento_id FROM coda_stampa
                WHERE tipo = "rt" AND stato = "attesa" AND tentativi = 1 AND ultimo_tentativo < NOW() - INTERVAL 60 SECOND')->fetchAll() as $l) {
        q('UPDATE coda_stampa SET stato = "errore", errore = "nessuna risposta" WHERE id = ?', [$l['id']]);
        if ($l['documento_id']) {
            q('UPDATE documenti SET stato = "errore", errore = ? WHERE id = ? AND stato = "attesa"',
              ['nessuna risposta dalla cassa: controlla se lo scontrino è uscito prima di riprovare', $l['documento_id']]);
        }
    }
    // Scontrini diretti (programma desktop) senza esito dopo 2 minuti: il programma si è fermato a metà
    q('UPDATE documenti d SET d.stato = "errore", d.errore = ?
       WHERE d.tipo = "scontrino" AND d.stato = "attesa" AND d.data < NOW() - INTERVAL 2 MINUTE
         AND NOT EXISTS (SELECT 1 FROM coda_stampa cs WHERE cs.documento_id = d.id)',
      ['nessun esito dal programma della cassa: controlla se lo scontrino è uscito prima di riprovare']);
    // Mai consegnati entro 2 minuti (ponte spento): annullati, così non escono più tardi a sorpresa
    foreach (q('SELECT id, documento_id FROM coda_stampa
                WHERE tipo = "rt" AND stato = "attesa" AND tentativi = 0 AND creato < NOW() - INTERVAL 2 MINUTE')->fetchAll() as $l) {
        q('UPDATE coda_stampa SET stato = "errore", errore = "ponte non collegato" WHERE id = ?', [$l['id']]);
        if ($l['documento_id']) {
            q('UPDATE documenti SET stato = "errore", errore = ? WHERE id = ? AND stato = "attesa"',
              ['ponte di stampa non collegato, scontrino annullato', $l['documento_id']]);
        }
    }
}

// Invio alla cassa: diretto se il server è nella rete del locale, altrimenti tramite il ponte (una sola volta, senza ripetere)
function invia_rt(string $xml, string $descrizione, ?int $doc_id): void {
    if (usa_ponte((string)impostazione('rt_ip'))) {
        q('INSERT INTO coda_stampa (tipo, documento_id, descrizione, dati) VALUES ("rt", ?, ?, ?)',
          [$doc_id, mb_substr($descrizione, 0, 100), base64_encode($xml)]);
        return;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Content-Type: text/xml; charset=utf-8\r\n", 'content' => $xml,
    ]]);
    $risposta = @file_get_contents(rt_url(), false, $ctx);
    $errore = $risposta === false ? 'cassa ' . impostazione('rt_ip') . ' non raggiungibile' : '';
    if ($doc_id) {
        registra_esito_rt($doc_id, $risposta !== false, (string)$risposta, $errore);
    } else {
        salva_impostazione('rt_ultima_verifica', date('d/m H:i') . ' - '
            . ($risposta === false ? $errore : (leggi_risposta_rt($risposta)[0] ? 'cassa collegata e pronta' : leggi_risposta_rt($risposta)[3])));
    }
}

// ------------------------------------------------------------
//  Fattura elettronica (FatturaPA 1.2, FPR12)
// ------------------------------------------------------------

function nome_cliente(array $cli): string {
    return $cli['denominazione'] ?: trim($cli['nome'] . ' ' . $cli['cognome']);
}

// Dati dell'azienda mancanti per fatturare (elenco vuoto = tutto a posto)
function dati_azienda_mancanti(): array {
    $mancano = [];
    foreach (['azienda_denominazione' => 'ragione sociale', 'azienda_piva' => 'partita IVA',
              'azienda_indirizzo' => 'indirizzo', 'azienda_cap' => 'CAP', 'azienda_comune' => 'comune'] as $k => $nome) {
        if (trim((string)impostazione($k, '')) === '') $mancano[] = $nome;
    }
    return $mancano;
}

// Numero della prossima fattura dell'anno: continua dopo le fatture del POS e dopo l'ultima
// emessa con altri programmi (impostazione "fattura_ultimo_<anno>", pagina Fiscale)
function prossimo_numero_fattura(int $anno): int {
    $pos    = (int)q('SELECT COALESCE(MAX(numero), 0) FROM documenti WHERE tipo = "fattura" AND anno = ? FOR UPDATE', [$anno])->fetchColumn();
    $esterne = (int)impostazione("fattura_ultimo_$anno", 0);
    return 1 + max($pos, $esterne);
}

// Nome del file XML: IT + partita IVA + progressivo (5 caratteri)
function nome_file_fattura(array $doc): string {
    return 'IT' . impostazione('azienda_piva') . '_' . str_pad(strtoupper(base_convert((string)$doc['id'], 10, 36)), 5, '0', STR_PAD_LEFT) . '.xml';
}

function xml_fattura(int $doc_id): string {
    $doc = q('SELECT * FROM documenti WHERE id = ? AND tipo = "fattura"', [$doc_id])->fetch();
    $cli = q('SELECT * FROM clienti WHERE id = ?', [$doc['cliente_id']])->fetch();
    $c   = dati_conto((int)$doc['conto_id']);
    $e   = fn($s) => htmlspecialchars(trim((string)$s), ENT_XML1, 'UTF-8');
    $n2  = fn($n) => number_format($n, 2, '.', '');

    // Righe: prezzi del conto IVA inclusa -> imponibile (scorporo)
    $linee = [];
    foreach ($c['righe'] as $r) {
        $linee[] = [descrizione_riga($r), (float)$r['quantita'], (float)$r['prezzo'], (float)$r['aliquota_iva']];
    }
    if ($c['coperti'] && $c['prezzo_coperto'] > 0) $linee[] = ['Coperto', (float)$c['coperti'], $c['prezzo_coperto'], 10.0];

    $xml_linee = '';
    $riepilogo = [];
    foreach ($linee as $i => [$desc, $q, $lordo, $al]) {
        $unit   = $lordo / (1 + $al / 100);
        $totale = round($unit * $q, 2);
        $riepilogo[$n2($al)] = ($riepilogo[$n2($al)] ?? 0) + $totale;
        $xml_linee .= '<DettaglioLinee><NumeroLinea>' . ($i + 1) . '</NumeroLinea><Descrizione>' . $e(mb_substr($desc, 0, 1000))
                   // Pesi con 3 decimali: PrezzoTotale deve tornare con PrezzoUnitario x Quantita (controllo SDI)
                   . '</Descrizione><Quantita>' . number_format($q, $q == round($q, 2) ? 2 : 3, '.', '') . '</Quantita><PrezzoUnitario>'
                   . number_format($unit, 8, '.', '') . '</PrezzoUnitario><PrezzoTotale>' . $n2($totale)
                   . '</PrezzoTotale><AliquotaIVA>' . $n2($al) . '</AliquotaIVA></DettaglioLinee>';
    }
    $xml_riep = '';
    $totale_doc = 0;
    foreach ($riepilogo as $al => $imponibile) {
        $imposta = round($imponibile * $al / 100, 2);
        $totale_doc += $imponibile + $imposta;
        $xml_riep .= "<DatiRiepilogo><AliquotaIVA>$al</AliquotaIVA><ImponibileImporto>" . $n2($imponibile)
                   . '</ImponibileImporto><Imposta>' . $n2($imposta) . '</Imposta><EsigibilitaIVA>I</EsigibilitaIVA></DatiRiepilogo>';
    }

    $piva = $e(impostazione('azienda_piva'));
    $cf_az = trim((string)impostazione('azienda_cf', ''));
    $sede = fn($ind, $cap, $com, $prov, $naz) => '<Sede><Indirizzo>' . $e($ind) . '</Indirizzo><CAP>' . $e($cap) . '</CAP><Comune>'
        . $e($com) . '</Comune>' . ($prov ? '<Provincia>' . $e(strtoupper($prov)) . '</Provincia>' : '')
        . '<Nazione>' . $e($naz ?: 'IT') . '</Nazione></Sede>';

    $dest = strtoupper($cli['codice_destinatario'] ?: '0000000');
    $anag_cli = $cli['denominazione']
        ? '<Denominazione>' . $e($cli['denominazione']) . '</Denominazione>'
        : '<Nome>' . $e($cli['nome']) . '</Nome><Cognome>' . $e($cli['cognome']) . '</Cognome>';

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<p:FatturaElettronica versione="FPR12" xmlns:ds="http://www.w3.org/2000/09/xmldsig#"'
        . ' xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' xsi:schemaLocation="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2 http://www.fatturapa.gov.it/export/fatturazione/sdi/fatturapa/v1.2/Schema_del_file_xml_FatturaPA_versione_1.2.xsd">'
        . '<FatturaElettronicaHeader>'
        .   '<DatiTrasmissione><IdTrasmittente><IdPaese>IT</IdPaese><IdCodice>' . ($cf_az !== '' ? $e($cf_az) : $piva) . '</IdCodice></IdTrasmittente>'
        .     '<ProgressivoInvio>' . str_pad(strtoupper(base_convert((string)$doc['id'], 10, 36)), 5, '0', STR_PAD_LEFT) . '</ProgressivoInvio>'
        .     '<FormatoTrasmissione>FPR12</FormatoTrasmissione><CodiceDestinatario>' . $e($dest) . '</CodiceDestinatario>'
        .     ($dest === '0000000' && $cli['pec'] ? '<PECDestinatario>' . $e($cli['pec']) . '</PECDestinatario>' : '')
        .   '</DatiTrasmissione>'
        .   '<CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>' . $piva . '</IdCodice></IdFiscaleIVA>'
        .     ($cf_az !== '' ? '<CodiceFiscale>' . $e($cf_az) . '</CodiceFiscale>' : '')
        .     '<Anagrafica><Denominazione>' . $e(impostazione('azienda_denominazione')) . '</Denominazione></Anagrafica>'
        .     '<RegimeFiscale>' . $e(impostazione('azienda_regime', 'RF01')) . '</RegimeFiscale></DatiAnagrafici>'
        .     $sede(impostazione('azienda_indirizzo'), impostazione('azienda_cap'), impostazione('azienda_comune'), impostazione('azienda_provincia'), 'IT')
        .   '</CedentePrestatore>'
        .   '<CessionarioCommittente><DatiAnagrafici>'
        .     ($cli['piva'] ? '<IdFiscaleIVA><IdPaese>' . $e($cli['nazione'] ?: 'IT') . '</IdPaese><IdCodice>' . $e($cli['piva']) . '</IdCodice></IdFiscaleIVA>' : '')
        .     ($cli['cf'] ? '<CodiceFiscale>' . $e(strtoupper($cli['cf'])) . '</CodiceFiscale>' : '')
        .     "<Anagrafica>$anag_cli</Anagrafica></DatiAnagrafici>"
        .     $sede($cli['indirizzo'], $cli['cap'], $cli['comune'], $cli['provincia'], $cli['nazione'])
        .   '</CessionarioCommittente>'
        . '</FatturaElettronicaHeader>'
        . '<FatturaElettronicaBody>'
        .   '<DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa>'
        .     '<Data>' . date('Y-m-d', strtotime($doc['data'])) . '</Data><Numero>' . $doc['numero'] . '/' . $doc['anno'] . '</Numero>'
        .     '<ImportoTotaleDocumento>' . $n2($totale_doc) . '</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>'
        .   "<DatiBeniServizi>$xml_linee$xml_riep</DatiBeniServizi>"
        .   '<DatiPagamento><CondizioniPagamento>TP02</CondizioniPagamento><DettaglioPagamento><ModalitaPagamento>'
        .     (PAGAMENTI_FPA[(int)$doc['pagamento_id']] ?? 'MP01') . '</ModalitaPagamento><ImportoPagamento>' . $n2($totale_doc)
        .     '</ImportoPagamento></DettaglioPagamento></DatiPagamento>'
        . '</FatturaElettronicaBody>'
        . '</p:FatturaElettronica>';
}
