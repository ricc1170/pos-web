<?php
// ============================================================
//  Documenti: proforma (preconto), scontrino (registratore telematico), fattura (FatturaPA)
//  e chiusura del conto. Usato da api/conto.php e fattura.php.
//  Stampe e registratore Epson li gestisce il programma desktop della cassa (configurazione sul PC):
//  il sito registra i documenti e restituisce i dati da stampare.
// ============================================================

// Pagamenti -> codici FatturaPA (ModalitaPagamento). I codici Epson stanno nel programma (pos_desktop/cassa_rt.py)
const PAGAMENTI_FPA = [1 => 'MP01', 2 => 'MP08', 3 => 'MP08'];

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

// Dati del conto per il programma desktop (preconto, scontrino simulato o Epson): prezzi e aliquote per riga
function conto_da_stampare(array $c): array {
    $righe = [];
    foreach ($c['righe'] as $r) {
        $righe[] = ['descrizione' => $r['descrizione'], 'quantita' => (float)$r['quantita'], 'prezzo' => (float)$r['prezzo'],
                    'aliquota_iva' => (float)$r['aliquota_iva'], 'a_peso' => (bool)$r['a_peso'], 'varianti' => $r['varianti']];
    }
    return [
        'titolo'         => titolo_conto($c),
        'nome_locale'    => (string)impostazione('nome_locale', ''),
        'valuta'         => (string)impostazione('valuta', '€'),
        'righe'          => $righe,
        'coperti'        => (int)$c['coperti'],
        'prezzo_coperto' => (float)$c['prezzo_coperto'],
        'totale'         => (float)$c['totale'],
    ];
}

// ------------------------------------------------------------
//  Scontrino: la modalità della cassa (simulata / Epson) la sceglie il programma desktop
// ------------------------------------------------------------

// Scontrino simulato: numero "SIM-n", conto chiuso subito. Il foglio NON fiscale lo stampa il programma.
// Restituisce [id del documento, numero]
function scontrino_simulato(array $c, int $pagamento_id, int $operatore_id): array {
    $n = 1 + (int)q('SELECT COUNT(*) FROM documenti WHERE tipo = "scontrino" AND rt_numero LIKE "SIM-%"')->fetchColumn();
    q('INSERT INTO documenti (tipo, conto_id, data, totale, pagamento_id, stato, rt_numero, operatore_id)
       VALUES ("scontrino", ?, NOW(), ?, ?, "emesso", ?, ?)', [$c['id'], $c['totale'], $pagamento_id, "SIM-$n", $operatore_id]);
    $doc_id = (int)db()->lastInsertId();
    chiudi_conto((int)$c['id'], $pagamento_id);
    return [$doc_id, "SIM-$n"];
}

// Legge la risposta della Epson (riportata dal programma): [riuscito, numero scontrino, numero chiusura Z, messaggio]
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

// Scontrini senza esito dal programma dopo 2 minuti (si è fermato a metà): errore, perché non si sa
// se la cassa li ha stampati (va controllato prima di riprovare)
function scontrini_senza_risposta() {
    q('UPDATE documenti SET stato = "errore", errore = ?
       WHERE tipo = "scontrino" AND stato = "attesa" AND data < NOW() - INTERVAL 2 MINUTE',
      ['nessun esito dal programma della cassa: controlla se lo scontrino è uscito prima di riprovare']);
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
