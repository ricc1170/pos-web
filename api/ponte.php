<?php
// ============================================================
//  api/ponte.php  -  Interfaccia per il ponte di stampa (ponte.ps1)
//  Il ponte si identifica con l'intestazione  X-Ponte-Chiave: <chiave>
//    GET  ?azione=prendi  -> { lavori: [ {id, collegamento, ip, porta, com, baud, flusso, dati(base64)} ] }
//         (intestazione facoltativa X-Ponte-Porte: porte COM presenti sul PC del ponte)
//    POST ?azione=esito   -> body JSON { id, ok, errore }
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stampa.php';

header('Content-Type: application/json; charset=utf-8');

function rispondi(array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati);
    exit;
}

$giusta = (string)impostazione('ponte_chiave', '');
if ($giusta === '' || !hash_equals($giusta, (string)($_SERVER['HTTP_X_PONTE_CHIAVE'] ?? ''))) {
    rispondi(['errore' => 'Chiave del ponte non valida: riscarica ponte.ps1 dalla pagina Stampanti'], 403);
}

// Segno che il ponte è vivo (la pagina Stampanti mostra "collegato")
salva_impostazione('ponte_visto', date('Y-m-d H:i:s'));

// Porte seriali presenti sul PC del ponte (intestazione X-Ponte-Porte: "COM1,COM3"), mostrate in Stampanti
if (isset($_SERVER['HTTP_X_PONTE_PORTE'])) {
    preg_match_all('/COM\d{1,3}/i', $_SERVER['HTTP_X_PONTE_PORTE'], $m);
    $porte = implode(',', array_unique(array_map('strtoupper', $m[0])));
    if ($porte !== (string)impostazione('ponte_porte', '')) salva_impostazione('ponte_porte', $porte);
}

$azione = $_GET['azione'] ?? '';

// Versione del ponte: dalla 2 sa inviare gli scontrini alla Epson RT (lavori tipo "rt")
$versione = (int)($_SERVER['HTTP_X_PONTE_VERSIONE'] ?? 1);

if ($azione === 'prendi') {
    require_once __DIR__ . '/../includes/documenti.php';
    scontrini_senza_risposta();

    // Stampe in attesa; dopo un tentativo fallito si riprova ogni 10 secondi
    $lavori = q('SELECT c.id, c.tipo, s.collegamento, s.ip, s.porta, s.com, s.baud, s.flusso, c.dati
                 FROM coda_stampa c JOIN stampanti s ON s.id = c.stampante_id
                 WHERE c.tipo = "stampa" AND c.stato = "attesa"
                   AND (c.ultimo_tentativo IS NULL OR c.ultimo_tentativo < NOW() - INTERVAL 10 SECOND)
                 ORDER BY c.id LIMIT 10')->fetchAll();
    foreach ($lavori as &$l) {
        $l['id']    = (int)$l['id'];
        $l['porta'] = (int)$l['porta'];
        $l['baud']  = (int)$l['baud'];
    }
    unset($l);

    // Scontrini per la Epson: consegnati UNA sola volta (mai ripetuti: rischio di scontrini doppi)
    if ($versione >= 2) {
        foreach (q('SELECT id, dati FROM coda_stampa WHERE tipo = "rt" AND stato = "attesa" AND tentativi = 0 ORDER BY id LIMIT 5')->fetchAll() as $r) {
            q('UPDATE coda_stampa SET tentativi = 1, ultimo_tentativo = NOW() WHERE id = ?', [$r['id']]);
            $lavori[] = ['id' => (int)$r['id'], 'tipo' => 'rt', 'url' => rt_url(), 'dati' => $r['dati']];
        }
    }
    rispondi(['lavori' => $lavori]);
}

if ($azione === 'esito' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($in['id'] ?? 0);
    $lavoro = q('SELECT tipo, documento_id FROM coda_stampa WHERE id = ?', [$id])->fetch();

    if ($lavoro && $lavoro['tipo'] === 'rt') {
        // Risposta della Epson: il documento diventa emesso (conto chiuso) o errore; nessun nuovo tentativo
        require_once __DIR__ . '/../includes/documenti.php';
        $risposta = (string)($in['risposta'] ?? '');
        q('UPDATE coda_stampa SET stato = ?, stampato = NOW(), errore = ?, risposta = ? WHERE id = ?',
          [!empty($in['ok']) ? 'stampata' : 'errore', mb_substr((string)($in['errore'] ?? ''), 0, 255) ?: null,
           mb_substr($risposta, 0, 60000), $id]);
        if ($lavoro['documento_id']) {
            registra_esito_rt((int)$lavoro['documento_id'], !empty($in['ok']), $risposta, (string)($in['errore'] ?? ''));
        } else {
            // Verifica del collegamento (pagina Fiscale)
            $esito = empty($in['ok']) ? ($in['errore'] ?? 'cassa non raggiungibile')
                   : (leggi_risposta_rt($risposta)[0] ? 'cassa collegata e pronta' : leggi_risposta_rt($risposta)[3]);
            salva_impostazione('rt_ultima_verifica', date('d/m H:i') . ' - ' . $esito);
        }
        rispondi(['ok' => true]);
    }

    if (!empty($in['ok'])) {
        q('UPDATE coda_stampa SET stato = "stampata", stampato = NOW(), errore = NULL WHERE id = ? AND stato = "attesa"', [$id]);
    } else {
        // Stampante spenta o senza carta: riprovo, poi mi arrendo
        q('UPDATE coda_stampa
           SET tentativi = tentativi + 1, ultimo_tentativo = NOW(), errore = ?,
               stato = IF(tentativi >= ?, "errore", "attesa")
           WHERE id = ? AND stato = "attesa"',
          [mb_substr((string)($in['errore'] ?? 'errore sconosciuto'), 0, 255), PONTE_MAX_TENTATIVI, $id]);
    }
    // Pulizia: le stampe vecchie non servono più
    q('DELETE FROM coda_stampa WHERE stato <> "attesa" AND creato < NOW() - INTERVAL 2 DAY');
    rispondi(['ok' => true]);
}

rispondi(['errore' => 'Azione sconosciuta'], 400);
