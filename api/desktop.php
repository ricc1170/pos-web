<?php
// ============================================================
//  api/desktop.php  -  Interfaccia JSON per il programma desktop in Python (pos_desktop)
//  Login con PIN e dati per disegnare le schermate. Le operazioni sul conto
//  il programma le fa con api/conto.php, usando la stessa sessione (cookie).
//
//  Ogni richiesta deve avere l'intestazione  X-POS-Desktop: 1
//  (un altro sito non può aggiungerla senza permesso CORS: protegge dal CSRF)
//
//    POST {azione: "login", pin}            -> { operatore, impostazioni }
//    POST {azione: "logout"}
//    GET  ?azione=sala                      -> { sale, tavoli, parcheggiate, avviso_ponte }
//    GET  ?azione=info_sala&sala=N          -> { tavoli, locale }
//    GET  ?azione=menu                      -> { categorie, articoli, varianti, pagamenti, operatori, ... }
//    GET  ?azione=parcheggi                 -> { comande }
//    POST {azione: "cambio", da, a, unisci} -> { esito: "ok"|"serve_unione", testo }
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stampa.php';
require_once __DIR__ . '/../includes/documenti.php';
require_once __DIR__ . '/../includes/cambio_tavolo.php';

header('Content-Type: application/json; charset=utf-8');

function rispondi(array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati);
    exit;
}

if (($_SERVER['HTTP_X_POS_DESKTOP'] ?? '') !== '1') {
    rispondi(['errore' => 'Richiesta non valida'], 400);
}

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$azione = $in['azione'] ?? ($_GET['azione'] ?? '');

// Dati dell'operatore per il programma (ruolo: cosa può fare)
function dati_operatore(array $op): array {
    return ['id' => (int)$op['id'], 'nome' => $op['nome'], 'ruolo' => $op['ruolo'],
            'puo_stornare' => in_array($op['ruolo'], ['cassa', 'admin'], true)];
}

// ------------------------------------------------------------
//  Login / logout
// ------------------------------------------------------------
if ($azione === 'login') {
    if ($minuti = login_bloccato()) {
        rispondi(['errore' => "Troppi PIN sbagliati: riprova tra $minuti minut" . ($minuti == 1 ? 'o' : 'i')], 429);
    }
    if (!login_pin((string)($in['pin'] ?? ''))) rispondi(['errore' => 'PIN non valido'], 401);
    rispondi([
        'operatore'    => dati_operatore(operatore_loggato()),
        'impostazioni' => [
            'nome_locale'  => (string)impostazione('nome_locale', 'POS'),
            'numero_cassa' => (string)impostazione('numero_cassa', '1'),
            'valuta'       => (string)impostazione('valuta', '€'),
            'coperto'      => (float)impostazione('coperto', 0),
        ],
    ]);
}

if ($azione === 'logout') {
    logout();
    rispondi(['ok' => true]);
}

$op = operatore_loggato();
if (!$op) rispondi(['errore' => 'Sessione scaduta, rifare il login'], 401);

// ------------------------------------------------------------
//  Mappa tavoli: tutte le sale con i loro tavoli (il programma cambia sala senza ricaricare)
// ------------------------------------------------------------
if ($azione === 'sala') {
    $sale = q('SELECT id, nome FROM sale ORDER BY ordine, id')->fetchAll();
    $tavoli = q('SELECT t.id, t.sala_id, t.nome, t.posti, t.pos_x, t.pos_y, t.larghezza, t.altezza, t.forma,
                        c.id AS conto_id, c.totale, c.coperti,
                        (SELECT COUNT(*) FROM righe r WHERE r.conto_id = c.id AND r.invio = 0) AS da_inviare
                 FROM tavoli t
                 LEFT JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
                 WHERE t.attivo = 1
                 ORDER BY t.nome')->fetchAll();
    foreach ($tavoli as &$t) {
        foreach (['id', 'sala_id', 'posti', 'pos_x', 'pos_y', 'larghezza', 'altezza', 'coperti', 'da_inviare'] as $k) {
            $t[$k] = (int)$t[$k];
        }
        $t['conto_id'] = $t['conto_id'] === null ? null : (int)$t['conto_id'];
        $t['totale']   = (float)$t['totale'];
    }
    unset($t);
    foreach ($sale as &$s) $s['id'] = (int)$s['id'];
    unset($s);

    rispondi([
        'sale'         => $sale,
        'tavoli'       => $tavoli,
        'parcheggiate' => (int)q('SELECT COUNT(*) FROM conti
                                  WHERE tipo = "asporto" AND stato = "aperto" AND orario_ritiro IS NOT NULL')->fetchColumn(),
        'avviso_ponte' => serve_ponte() && q('SELECT 1 FROM stampanti WHERE attiva = 1 LIMIT 1')->fetch() && !ponte_collegato(),
    ]);
}

// ------------------------------------------------------------
//  Info Sala (come la finestra di sala.php)
// ------------------------------------------------------------
if ($azione === 'info_sala') {
    $tavoli = q('
        SELECT t.id, t.nome, c.coperti, c.totale, o.nome AS operatore,
               TIMESTAMPDIFF(MINUTE, c.data_apertura, NOW()) AS min_aperto,
               (SELECT TIMESTAMPDIFF(MINUTE, MAX(r.creata_il), NOW()) FROM righe r WHERE r.conto_id = c.id AND r.invio > 0) AS min_comanda,
               (SELECT COUNT(*) FROM righe r WHERE r.conto_id = c.id AND r.invio = 0) AS da_inviare
        FROM tavoli t
        JOIN conti c ON c.tavolo_id = t.id AND c.stato = "aperto"
        LEFT JOIN operatori o ON o.id = c.operatore_id
        WHERE t.sala_id = ? AND t.attivo = 1
        ORDER BY c.data_apertura', [(int)($_GET['sala'] ?? 0)])->fetchAll();
    foreach ($tavoli as &$t) {
        $t['id']          = (int)$t['id'];
        $t['coperti']     = (int)$t['coperti'];
        $t['totale']      = (float)$t['totale'];
        $t['min_aperto']  = (int)$t['min_aperto'];
        $t['min_comanda'] = $t['min_comanda'] === null ? null : (int)$t['min_comanda'];
        $t['da_inviare']  = (int)$t['da_inviare'];
    }
    unset($t);
    $locale = q('
        SELECT
          (SELECT COUNT(*) FROM tavoli WHERE attivo = 1)                                           AS tavoli,
          (SELECT COUNT(*) FROM conti WHERE stato = "aperto" AND tipo = "tavolo")                  AS occupati,
          (SELECT COALESCE(SUM(coperti), 0) FROM conti WHERE stato = "aperto" AND tipo = "tavolo") AS coperti,
          (SELECT COALESCE(SUM(totale), 0) FROM conti WHERE stato = "aperto" AND tipo = "tavolo")  AS totale_tavoli,
          (SELECT COUNT(*) FROM conti WHERE stato = "aperto" AND tipo = "asporto")                 AS bar,
          (SELECT COALESCE(SUM(totale), 0) FROM conti WHERE stato = "aperto" AND tipo = "asporto") AS totale_bar')->fetch();
    rispondi(['tavoli' => $tavoli, 'locale' => array_map('floatval', $locale)]);
}

// ------------------------------------------------------------
//  Menu: categorie, prodotti, varianti, pagamenti, operatori del banco
// ------------------------------------------------------------
if ($azione === 'menu') {
    $categorie = q('SELECT id, nome, colore FROM categorie ORDER BY ordine, id')->fetchAll();
    foreach ($categorie as &$c) $c['id'] = (int)$c['id'];
    unset($c);

    $articoli = q('SELECT id, categoria_id, nome, prezzo, colore, a_peso FROM articoli WHERE visibile = 1 ORDER BY ordine, nome')->fetchAll();
    foreach ($articoli as &$a) {
        $a['id']           = (int)$a['id'];
        $a['categoria_id'] = (int)$a['categoria_id'];
        $a['prezzo']       = (float)$a['prezzo'];    // al kg se a_peso
        $a['a_peso']       = (bool)$a['a_peso'];
    }
    unset($a);

    $varianti = q('SELECT v.id, v.nome, v.prezzo, v.tutte,
                          (SELECT GROUP_CONCAT(vc.categoria_id) FROM varianti_categorie vc WHERE vc.variante_id = v.id) AS categorie
                   FROM varianti v WHERE v.attiva = 1 ORDER BY v.ordine, v.nome')->fetchAll();
    foreach ($varianti as &$v) {
        $v = ['id' => (int)$v['id'], 'nome' => $v['nome'], 'prezzo' => (float)$v['prezzo'], 'tutte' => (bool)$v['tutte'],
              'categorie' => $v['categorie'] ? array_map('intval', explode(',', $v['categorie'])) : []];
    }
    unset($v);

    $pagamenti = q('SELECT id, nome FROM forme_pagamento ORDER BY ordine, id')->fetchAll();
    foreach ($pagamenti as &$p) $p['id'] = (int)$p['id'];
    unset($p);

    $operatori = q('SELECT id, nome FROM operatori WHERE attivo = 1 ORDER BY id')->fetchAll();
    foreach ($operatori as &$o) $o['id'] = (int)$o['id'];
    unset($o);

    // Pulsanti della finestra Note (la stampante dedicata la decide il sito al momento della stampa)
    $note_rapide = q('SELECT n.testo, s.nome AS stampante FROM note_rapide n
                      LEFT JOIN stampanti s ON s.id = n.stampante_id AND s.attiva = 1
                      WHERE n.attiva = 1 ORDER BY n.ordine, n.id')->fetchAll();

    rispondi([
        'categorie'  => $categorie,
        'articoli'   => $articoli,
        'varianti'   => $varianti,
        'note_rapide' => $note_rapide,
        'pagamenti'  => $pagamenti,
        'operatori'  => $operatori,
        'rt_simulata' => rt_simulata(),
        'rt_modo'     => rt_modo(),       // "epson" + [cassa] ip in config.ini = scontrino diretto dal programma
    ]);
}

// ------------------------------------------------------------
//  Comande Bar: in corso al banco e parcheggiate (come bar.php)
// ------------------------------------------------------------
if ($azione === 'parcheggi') {
    $puo_stornare = in_array($op['ruolo'], ['cassa', 'admin'], true);
    // Orario di ritiro salvato in ora italiana: il confronto si fa in PHP (il fuso del database può essere diverso)
    $adesso = date('Y-m-d H:i:s');
    $comande = q('
        SELECT c.id, c.orario_ritiro, c.nome_cliente, c.note, c.totale, o.nome AS operatore,
               (SELECT COALESCE(SUM(IF(a.a_peso = 1, 1, r.quantita)), 0)
                FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id WHERE r.conto_id = c.id) AS pezzi,
               (SELECT COUNT(*)                   FROM righe WHERE conto_id = c.id AND invio = 0) AS da_inviare,
               (SELECT COUNT(*)                   FROM righe WHERE conto_id = c.id AND invio > 0) AS inviate
        FROM conti c
        LEFT JOIN operatori o ON o.id = c.operatore_id
        WHERE c.tipo = "asporto" AND c.stato = "aperto"
        ORDER BY c.orario_ritiro IS NOT NULL, c.orario_ritiro, c.id')->fetchAll();
    foreach ($comande as &$c) {
        $c['id']          = (int)$c['id'];
        $c['orario']      = $c['orario_ritiro'] ? date('H:i', strtotime($c['orario_ritiro'])) : null;
        $c['totale']      = (float)$c['totale'];
        $c['pezzi']       = (float)$c['pezzi'];
        $c['da_inviare']  = (int)$c['da_inviare'];
        $c['ritardo']     = $c['orario_ritiro'] !== null && $c['orario_ritiro'] < $adesso;
        $c['eliminabile'] = $puo_stornare || !(int)$c['inviate'];
        unset($c['orario_ritiro'], $c['inviate']);
    }
    unset($c);
    rispondi(['comande' => $comande]);
}

// ------------------------------------------------------------
//  Cambio tavolo (includes/cambio_tavolo.php): se la destinazione è occupata serve unisci = true
// ------------------------------------------------------------
if ($azione === 'cambio' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        [$esito, $testo] = cambia_tavolo((int)($in['da'] ?? 0), (int)($in['a'] ?? 0), !empty($in['unisci']));
        rispondi(['esito' => $esito, 'testo' => $testo]);
    } catch (ErroreCambio $e) {
        rispondi(['errore' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('api/desktop.php cambio: ' . $e->getMessage());
        rispondi(['errore' => 'Cambio tavolo non riuscito (errore del database)'], 500);
    }
}

rispondi(['errore' => 'Azione sconosciuta'], 400);
