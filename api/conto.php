<?php
// ============================================================
//  api/conto.php  -  Operazioni sul conto (AJAX)
//  Il conto si individua in tre modi:
//    - { tavolo: N }            -> conto aperto del tavolo (creato al primo articolo)
//    - { bar: true, banco: N, posto: P }
//                               -> comanda Bar in corso dell'operatore N al banco per il cliente P (1, 2 o 3,
//                                  default 1; non ancora parcheggiata; creata al primo articolo)
//    - { conto: N }             -> comanda Bar parcheggiata, richiamata
//  Richiesta:  POST JSON { azione, tavolo | bar | conto, ... }
//  Risposta:   JSON { conto, righe, banco?, posti? }  oppure { errore }
//              posti (solo al banco) = stato dei clienti 1, 2 e 3 dell'operatore
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stampa.php';

header('Content-Type: application/json; charset=utf-8');

// Errore da mostrare all'operatore (gli altri errori restano nel log)
class ErroreConto extends Exception {}

function rispondi(array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati);
    exit;
}

$op = operatore_loggato();
if (!$op) rispondi(['errore' => 'Sessione scaduta, rifare il login'], 401);

$in        = json_decode(file_get_contents('php://input'), true) ?? [];
$azione    = $in['azione'] ?? ($_GET['azione'] ?? 'stato');
$tavolo_id = (int)($in['tavolo'] ?? ($_GET['tavolo'] ?? 0));
$conto_id  = (int)($in['conto'] ?? ($_GET['conto'] ?? 0));
$bar       = !empty($in['bar']) || !empty($_GET['bar']);
// Al banco: quale dei clienti contemporanei (uno sotto l'altro nel programma della cassa)
$posto     = max(1, min(3, (int)($in['posto'] ?? ($_GET['posto'] ?? 1))));

// Chi registra gli articoli: al banco l'operatore selezionato col tasto, altrimenti chi ha fatto login
$autore = (int)$op['id'];

if ($conto_id) {
    $tavolo_id = 0;
    if (!q('SELECT id FROM conti WHERE id = ? AND tipo = "asporto"', [$conto_id])->fetch()) {
        rispondi(['errore' => 'Comanda non trovata'], 404);
    }
} elseif ($bar) {
    $tavolo_id = 0;
    $banco = (int)($in['banco'] ?? ($_GET['banco'] ?? 0));
    if ($banco && q('SELECT id FROM operatori WHERE id = ? AND attivo = 1', [$banco])->fetch()) {
        $autore = $banco;
    }
    // Comanda in corso di quell'operatore (se non c'è nasce al primo articolo)
    $conto_id = (int)comanda_in_corso($autore, $posto);
} elseif (!q('SELECT id FROM tavoli WHERE id = ?', [$tavolo_id])->fetch()) {
    rispondi(['errore' => 'Tavolo non trovato'], 404);
}

// Cassa e admin possono modificare anche righe già inviate in cucina
$puo_stornare = in_array($op['ruolo'], ['cassa', 'admin'], true);

// ------------------------------------------------------------
//  Funzioni di supporto
// ------------------------------------------------------------

// Comanda Bar in corso (aperta e non parcheggiata) di un operatore al banco per un cliente: id o null
function comanda_in_corso(int $operatore_id, int $posto) {
    return q('SELECT id FROM conti
              WHERE tipo = "asporto" AND stato = "aperto" AND orario_ritiro IS NULL AND operatore_id = ? AND posto = ?
              ORDER BY id DESC LIMIT 1', [$operatore_id, $posto])->fetchColumn() ?: null;
}

// Tasti operatore del banco: per ognuno quanti articoli ha nella comanda in corso
function riepilogo_banco(): array {
    // Un prodotto a peso conta come un articolo
    $ops = q('SELECT o.id, o.nome,
                     (SELECT COALESCE(SUM(IF(a.a_peso = 1, 1, r.quantita)), 0)
                      FROM conti c JOIN righe r ON r.conto_id = c.id LEFT JOIN articoli a ON a.id = r.articolo_id
                      WHERE c.tipo = "asporto" AND c.stato = "aperto" AND c.orario_ritiro IS NULL
                        AND c.operatore_id = o.id) AS pezzi
              FROM operatori o WHERE o.attivo = 1 ORDER BY o.id')->fetchAll();
    foreach ($ops as &$o) {
        $o['id']    = (int)$o['id'];
        $o['pezzi'] = (float)$o['pezzi'];
    }
    return $ops;
}

function conto_aperto(int $tavolo_id, int $conto_id): ?array {
    if ($conto_id) {
        $c = q('SELECT * FROM conti WHERE id = ? AND stato = "aperto"', [$conto_id])->fetch();
    } elseif ($tavolo_id) {
        $c = q('SELECT * FROM conti WHERE tavolo_id = ? AND stato = "aperto" LIMIT 1', [$tavolo_id])->fetch();
    }
    return ($c ?? null) ?: null;
}

// Restituisce il conto aperto, creandolo se è un tavolo libero o una nuova comanda Bar.
// Per la comanda Bar aggiorna $conto_id, così le chiamate successive la trovano.
function apri_conto(int $tavolo_id, int &$conto_id, int $operatore_id): array {
    global $posto;
    $c = conto_aperto($tavolo_id, $conto_id);
    if ($c) return $c;
    if ($conto_id) throw new ErroreConto('Comanda già chiusa');
    if ($tavolo_id) {
        q('INSERT INTO conti (tavolo_id, operatore_id) VALUES (?, ?)', [$tavolo_id, $operatore_id]);
        return conto_aperto($tavolo_id, 0);
    }
    q('INSERT INTO conti (tipo, operatore_id, posto) VALUES ("asporto", ?, ?)', [$operatore_id, $posto]);
    $conto_id = (int)db()->lastInsertId();
    return conto_aperto(0, $conto_id);
}

// Totale = somma righe (ognuna arrotondata al centesimo, come fa la cassa: conta coi pesi) + coperti * prezzo coperto
function ricalcola_totale(int $conto_id) {
    q('UPDATE conti SET totale =
         (SELECT COALESCE(SUM(ROUND(quantita * (prezzo_unitario + prezzo_varianti), 2)), 0) FROM righe WHERE conto_id = ?)
         + coperti * ?
       WHERE id = ?', [$conto_id, (float)impostazione('coperto', 0), $conto_id]);
}

// Un conto di un tavolo senza righe e senza coperti non serve: lo elimino (il tavolo torna libero).
// Le comande Bar restano: si annullano con l'azione "annulla"
function chiudi_se_vuoto(int $conto_id) {
    $n = q('SELECT COUNT(*) FROM righe WHERE conto_id = ?', [$conto_id])->fetchColumn();
    if ($n == 0) {
        q('DELETE FROM conti WHERE id = ? AND tipo = "tavolo" AND coperti = 0', [$conto_id]);
    }
}

// Comanda inviata, da restituire al programma desktop che la stampa: [conto_id, numero invio]
$da_stampare = null;

// Le righe non ancora inviate diventano la comanda numero N+1 del conto
function invia_righe(int $conto_id) {
    global $da_stampare;
    $prossimo = 1 + (int)q('SELECT COALESCE(MAX(invio), 0) FROM righe WHERE conto_id = ?', [$conto_id])->fetchColumn();
    $n = q('UPDATE righe SET invio = ? WHERE conto_id = ? AND invio = 0', [$prossimo, $conto_id])->rowCount();
    if ($n) $da_stampare = [$conto_id, $prossimo];
}

// Categoria del prodotto della riga (le varianti valgono per categoria)
function categoria_riga(array $riga): int {
    $cat = (int)q('SELECT categoria_id FROM articoli WHERE id = ?', [$riga['articolo_id']])->fetchColumn();
    if (!$cat) throw new ErroreConto('Questo prodotto non ha varianti');
    return $cat;
}

// Variante attiva prevista per la categoria, oppure null
function variante_valida(int $id, int $cat): ?array {
    $v = q('SELECT v.* FROM varianti v
            WHERE v.id = ? AND v.attiva = 1
              AND (v.tutte = 1 OR EXISTS (SELECT 1 FROM varianti_categorie vc
                                          WHERE vc.variante_id = v.id AND vc.categoria_id = ?))',
           [$id, $cat])->fetch();
    return $v ?: null;
}

// Prezzo delle aggiunte per pezzo = somma delle varianti "+" della riga
function ricalcola_varianti(int $riga_id) {
    q('UPDATE righe SET prezzo_varianti =
         (SELECT COALESCE(SUM(prezzo), 0) FROM righe_varianti WHERE riga_id = ?)
       WHERE id = ?', [$riga_id, $riga_id]);
}

// Sostituisce le varianti di una riga con quelle scelte: [{id, segno '+'|'-'}] (finestra Varianti).
// Valgono solo le varianti attive previste per la categoria del prodotto; "+" costa, "−" è gratis.
function salva_varianti(array $riga, array $scelte) {
    $cat = categoria_riga($riga);
    q('DELETE FROM righe_varianti WHERE riga_id = ?', [$riga['id']]);
    $viste = [];
    foreach ($scelte as $s) {
        $id    = (int)($s['id'] ?? 0);
        $segno = ($s['segno'] ?? '') === '-' ? '-' : '+';
        $v = isset($viste[$id]) ? null : variante_valida($id, $cat);
        if (!$v) continue;
        $viste[$id] = true;
        q('INSERT INTO righe_varianti (riga_id, variante_id, segno, nome, prezzo) VALUES (?, ?, ?, ?, ?)',
          [$riga['id'], $v['id'], $segno, $v['nome'], $segno === '+' ? $v['prezzo'] : 0]);
    }
    ricalcola_varianti($riga['id']);
}

// Una variante alla volta (pulsanti Varianti nella colonna categorie):
// non c'è -> la aggiungo col segno; c'è con l'altro segno -> cambio segno; c'è con lo stesso segno -> la tolgo
function imposta_variante(array $riga, int $id, string $segno) {
    $v = variante_valida($id, categoria_riga($riga));
    if (!$v) throw new ErroreConto('Variante non prevista per questo prodotto');
    $c = q('SELECT id, segno FROM righe_varianti WHERE riga_id = ? AND variante_id = ?', [$riga['id'], $id])->fetch();
    if ($c && $c['segno'] === $segno) {
        q('DELETE FROM righe_varianti WHERE id = ?', [$c['id']]);
    } elseif ($c) {
        q('UPDATE righe_varianti SET segno = ?, prezzo = ? WHERE id = ?', [$segno, $segno === '+' ? $v['prezzo'] : 0, $c['id']]);
    } else {
        q('INSERT INTO righe_varianti (riga_id, variante_id, segno, nome, prezzo) VALUES (?, ?, ?, ?, ?)',
          [$riga['id'], $id, $segno, $v['nome'], $segno === '+' ? $v['prezzo'] : 0]);
    }
    ricalcola_varianti($riga['id']);
}

function stato_conto(int $tavolo_id, int $conto_id): array {
    $c = conto_aperto($tavolo_id, $conto_id);
    if (!$c) return ['conto' => null, 'righe' => []];
    $righe = q('SELECT r.id, r.articolo_id, a.categoria_id, COALESCE(a.a_peso, 0) AS a_peso, r.descrizione, r.quantita,
                       r.prezzo_unitario, r.prezzo_varianti, r.note, r.invio
                FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id
                WHERE r.conto_id = ? ORDER BY r.invio = 0, r.invio, r.id', [$c['id']])->fetchAll();

    // Varianti di tutte le righe in una sola query
    $varianti = [];
    if ($righe) {
        $ids = array_column($righe, 'id');
        $st = q('SELECT riga_id, variante_id, segno, nome, prezzo FROM righe_varianti
                 WHERE riga_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY segno = "-", id', $ids);
        foreach ($st as $v) {
            $varianti[$v['riga_id']][] = ['id' => (int)$v['variante_id'], 'segno' => $v['segno'],
                                          'nome' => $v['nome'], 'prezzo' => (float)$v['prezzo']];
        }
    }

    foreach ($righe as &$r) {
        $r['quantita']        = (float)$r['quantita'];
        $r['prezzo_unitario'] = (float)$r['prezzo_unitario'] + (float)$r['prezzo_varianti'];  // prezzo per pezzo, aggiunte comprese
        $r['categoria_id']    = $r['categoria_id'] === null ? null : (int)$r['categoria_id'];
        $r['a_peso']          = (bool)$r['a_peso'];   // quantita = kg
        $r['varianti']        = $varianti[$r['id']] ?? [];
        unset($r['prezzo_varianti']);
    }
    return [
        'conto' => [
            'id'      => (int)$c['id'],
            'tipo'    => $c['tipo'],
            'coperti' => (int)$c['coperti'],
            'totale'  => (float)$c['totale'],
            'orario'  => $c['orario_ritiro'] ? date('H:i', strtotime($c['orario_ritiro'])) : null,
            'nome'    => $c['nome_cliente'],
            'note'    => $c['note'],
        ],
        'righe' => $righe,
    ];
}

// ------------------------------------------------------------
//  Azioni
// ------------------------------------------------------------
// Stato da restituire. Al banco la comanda in corso viene ricercata di nuovo
// (dopo il parcheggio l'operatore riparte da una comanda vuota) e si aggiungono i tasti operatore
function risposta(): array {
    global $bar, $autore, $posto, $tavolo_id, $conto_id;
    if ($bar) $conto_id = (int)comanda_in_corso($autore, $posto);
    $r = stato_conto($tavolo_id, $conto_id);
    if ($bar) {
        // Tutti i clienti dell'operatore al banco: il programma li mostra uno sotto l'altro
        $r['posti'] = [];
        for ($p = 1; $p <= 3; $p++) {
            $r['posti'][] = $p === $posto ? ['conto' => $r['conto'], 'righe' => $r['righe']]
                                          : stato_conto(0, (int)comanda_in_corso($autore, $p));
        }
    }
    if (!$tavolo_id) {
        $r['banco'] = riepilogo_banco();
        $r['parcheggiate'] = (int)q('SELECT COUNT(*) FROM conti
                                     WHERE tipo = "asporto" AND stato = "aperto" AND orario_ritiro IS NOT NULL')->fetchColumn();
    }
    return $r;
}

if ($azione === 'stato') {
    rispondi(risposta());
}

// ------------------------------------------------------------
//  Documenti: proforma (preconto), scontrino, stato di uno scontrino in corso.
//  Stampanti e cassa fiscale sono configurate sul PC della cassa: il sito registra il documento e restituisce
//  il conto da stampare ("da_stampare"); il programma desktop lo stampa.
//  Scontrino: il programma dice la modalità della sua cassa ("modo"):
//    - simulata -> emesso subito (SIM-n), conto chiuso; il programma stampa il foglio NON fiscale
//    - epson    -> documento "attesa"; il programma manda lo scontrino alla Epson e riporta la risposta
//                  con "esito_scontrino" (il conto si chiude SOLO se la Epson ha emesso lo scontrino)
// ------------------------------------------------------------
if (in_array($azione, ['proforma', 'scontrino', 'documento', 'esito_scontrino'], true)) {
    require_once __DIR__ . '/../includes/documenti.php';

    // Stato di un documento: lo scontrino passa da "attesa" a "emesso" (conto chiuso) o "errore"
    $stato_doc = function (int $id): array {
        $d = q('SELECT id, stato, rt_numero, errore, conto_id FROM documenti WHERE id = ?', [$id])->fetch();
        if (!$d) return ['stato' => 'errore', 'testo' => 'Documento non trovato'];
        $testo = [
            'attesa' => 'Scontrino in stampa sulla cassa…',
            'emesso' => (strpos((string)$d['rt_numero'], 'SIM-') === 0 ? 'Scontrino SIMULATO' : 'Scontrino emesso')
                        . ($d['rt_numero'] ? ' n. ' . $d['rt_numero'] : '') . ': conto chiuso',
            'errore' => 'Scontrino NON emesso: ' . $d['errore'] . ' (il conto resta aperto)',
        ][$d['stato']];
        return ['id' => (int)$d['id'], 'stato' => $d['stato'], 'testo' => $testo];
    };

    try {
        if ($azione === 'documento') {
            scontrini_senza_risposta();
            rispondi(['documento' => $stato_doc((int)($in['id'] ?? 0))] + risposta());
        }

        if ($azione === 'esito_scontrino') {
            if (!$puo_stornare) throw new ErroreConto('Lo scontrino lo emette la cassa');
            $id = (int)q('SELECT id FROM documenti WHERE id = ? AND tipo = "scontrino" AND stato = "attesa"',
                         [(int)($in['id'] ?? 0)])->fetchColumn();
            if ($id) registra_esito_rt($id, !empty($in['collegato']), (string)($in['risposta'] ?? ''), (string)($in['errore'] ?? ''));
            rispondi(['documento' => $stato_doc((int)($in['id'] ?? 0))] + risposta());
        }

        $conto = conto_aperto($tavolo_id, $conto_id);
        $c = $conto ? dati_conto((int)$conto['id']) : null;
        if (!$c || !$c['righe']) throw new ErroreConto('Il conto è vuoto');

        if ($azione === 'proforma') {
            q('INSERT INTO documenti (tipo, conto_id, data, totale, operatore_id) VALUES ("proforma", ?, NOW(), ?, ?)',
              [$c['id'], $c['totale'], $op['id']]);
            rispondi(['da_stampare' => conto_da_stampare($c)] + risposta());
        }

        // Scontrino
        if (!$puo_stornare) throw new ErroreConto('Lo scontrino lo emette la cassa');
        $modo = (string)($in['modo'] ?? '');
        if (!in_array($modo, ['simulata', 'epson'], true)) {
            throw new ErroreConto('Lo scontrino si emette dal programma della cassa (Impostazioni > Cassa fiscale)');
        }
        $pag = (int)($in['pagamento'] ?? 0);
        $pagamento = q('SELECT id, nome FROM forme_pagamento WHERE id = ?', [$pag])->fetch();
        if (!$pagamento) throw new ErroreConto('Scegli il pagamento');
        $pagamento['id'] = (int)$pagamento['id'];
        if ((float)$c['totale'] <= 0) throw new ErroreConto('Totale a zero: niente scontrino');

        // Uno scontrino alla volta per conto (evita doppioni se si preme due volte)
        $in_corso = q('SELECT id FROM documenti WHERE conto_id = ? AND tipo = "scontrino" AND stato = "attesa"', [$c['id']])->fetchColumn();
        if ($in_corso) rispondi(['documento' => $stato_doc((int)$in_corso)] + risposta());

        $da_stampare = conto_da_stampare($c) + ['pagamento' => $pagamento];

        // Cassa simulata: emesso subito, conto chiuso; il foglio "non fiscale" lo stampa il programma
        if ($modo === 'simulata') {
            [$doc_id, $numero] = scontrino_simulato($c, $pag, (int)$op['id']);
            rispondi(['documento' => $stato_doc($doc_id), 'da_stampare' => $da_stampare + ['numero' => $numero]] + risposta());
        }

        q('INSERT INTO documenti (tipo, conto_id, data, totale, pagamento_id, stato, operatore_id)
           VALUES ("scontrino", ?, NOW(), ?, ?, "attesa", ?)', [$c['id'], $c['totale'], $pag, $op['id']]);
        $doc_id = (int)db()->lastInsertId();
        rispondi(['documento' => $stato_doc($doc_id), 'da_stampare' => $da_stampare] + risposta());
    } catch (ErroreConto $e) {
        rispondi(['errore' => $e->getMessage()] + risposta(), 400);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Blocco tavolo (o comanda): due tablet sullo stesso tavolo non creano due conti
    if ($conto_id) {
        q('SELECT id FROM conti WHERE id = ? FOR UPDATE', [$conto_id]);
    } elseif ($tavolo_id) {
        q('SELECT id FROM tavoli WHERE id = ? FOR UPDATE', [$tavolo_id]);
    } elseif ($bar) {
        // Banco: blocco l'operatore e ricontrollo, così non nascono due comande in corso
        q('SELECT id FROM operatori WHERE id = ? FOR UPDATE', [$autore]);
        $conto_id = (int)comanda_in_corso($autore, $posto);
    }

    switch ($azione) {

        case 'aggiungi':
            $qta = max(1, min(99, (int)($in['qta'] ?? 1)));
            $art = q('SELECT * FROM articoli WHERE id = ? AND visibile = 1', [(int)($in['articolo'] ?? 0)])->fetch();
            if (!$art) throw new ErroreConto('Articolo non trovato');

            // Prodotto a peso: la quantità è il peso in kg (bilancia o inserito a mano), una riga per pesata
            if ($art['a_peso']) {
                $peso = round((float)str_replace(',', '.', (string)($in['peso'] ?? 0)), 3);
                if ($peso <= 0 || $peso > 99.999) throw new ErroreConto("{$art['nome']} è a peso: serve il peso in kg");
                $conto = apri_conto($tavolo_id, $conto_id, $autore);
                q('INSERT INTO righe (conto_id, articolo_id, descrizione, quantita, prezzo_unitario, aliquota_iva, operatore_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?)',
                  [$conto['id'], $art['id'], $art['nome'], $peso, $art['prezzo'], $art['aliquota_iva'], $autore]);
                $ultima_riga = (int)db()->lastInsertId();
                ricalcola_totale($conto['id']);
                break;
            }

            $conto = apri_conto($tavolo_id, $conto_id, $autore);
            // Stesso articolo ancora da inviare, senza note né varianti e col prezzo di listino: aumento la quantità
            $esiste = q('SELECT id FROM righe r
                         WHERE conto_id = ? AND articolo_id = ? AND invio = 0 AND note IS NULL AND prezzo_unitario = ?
                           AND NOT EXISTS (SELECT 1 FROM righe_varianti v WHERE v.riga_id = r.id)
                         LIMIT 1', [$conto['id'], $art['id'], $art['prezzo']])->fetch();
            if ($esiste) {
                q('UPDATE righe SET quantita = quantita + ? WHERE id = ?', [$qta, $esiste['id']]);
                $ultima_riga = (int)$esiste['id'];
            } else {
                q('INSERT INTO righe (conto_id, articolo_id, descrizione, quantita, prezzo_unitario, aliquota_iva, operatore_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?)',
                  [$conto['id'], $art['id'], $art['nome'], $qta, $art['prezzo'], $art['aliquota_iva'], $autore]);
                $ultima_riga = (int)db()->lastInsertId();
            }
            ricalcola_totale($conto['id']);
            break;

        case 'quantita':
        case 'elimina':
        case 'nota':
        case 'varianti':
        case 'variante':
        case 'modifica':
            $conto = conto_aperto($tavolo_id, $conto_id);
            $riga  = $conto ? q('SELECT * FROM righe WHERE id = ? AND conto_id = ?',
                                [(int)($in['riga'] ?? 0), $conto['id']])->fetch() : null;
            if (!$riga) throw new ErroreConto('Riga non trovata');
            if ($riga['invio'] > 0 && !$puo_stornare) {
                throw new ErroreConto('Riga già inviata in cucina: chiedi alla cassa');
            }

            if ($azione === 'quantita') {
                if (q('SELECT a_peso FROM articoli WHERE id = ?', [$riga['articolo_id']])->fetchColumn()) {
                    throw new ErroreConto('Prodotto a peso: eliminalo e ripesalo');
                }
                $nuova = $riga['quantita'] + ((int)($in['delta'] ?? 0) >= 0 ? 1 : -1);
                if ($nuova <= 0) {
                    q('DELETE FROM righe WHERE id = ?', [$riga['id']]);
                } else {
                    q('UPDATE righe SET quantita = ? WHERE id = ?', [$nuova, $riga['id']]);
                }
            } elseif ($azione === 'elimina') {
                q('DELETE FROM righe WHERE id = ?', [$riga['id']]);
            } elseif ($azione === 'varianti') {
                salva_varianti($riga, (array)($in['scelte'] ?? []));
            } elseif ($azione === 'modifica') {
                // Prezzo (per pezzo o al kg, varianti escluse) e, per i prodotti a peso, il peso in kg
                $campi = [];
                if (isset($in['prezzo'])) {
                    $prezzo = round((float)str_replace(',', '.', (string)$in['prezzo']), 2);
                    if ($prezzo < 0 || $prezzo > 99999.99) throw new ErroreConto('Prezzo non valido');
                    $campi['prezzo_unitario'] = $prezzo;
                }
                if (isset($in['peso'])) {
                    if (!q('SELECT a_peso FROM articoli WHERE id = ?', [$riga['articolo_id']])->fetchColumn()) {
                        throw new ErroreConto('Il prodotto non è a peso');
                    }
                    $peso = round((float)str_replace(',', '.', (string)$in['peso']), 3);
                    if ($peso <= 0 || $peso > 99.999) throw new ErroreConto('Peso non valido');
                    $campi['quantita'] = $peso;
                }
                if ($campi) {
                    q('UPDATE righe SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($campi)))
                      . ' WHERE id = ?', array_merge(array_values($campi), [$riga['id']]));
                }
            } elseif ($azione === 'variante') {
                imposta_variante($riga, (int)($in['variante'] ?? 0), ($in['segno'] ?? '') === '-' ? '-' : '+');
            } else {
                $nota = trim(mb_substr((string)($in['testo'] ?? ''), 0, 150));
                q('UPDATE righe SET note = ? WHERE id = ?', [$nota === '' ? null : $nota, $riga['id']]);
            }
            ricalcola_totale($conto['id']);
            chiudi_se_vuoto($conto['id']);
            break;

        case 'coperti':
            $n = max(0, min(99, (int)($in['n'] ?? 0)));
            $conto = $n > 0 ? apri_conto($tavolo_id, $conto_id, $autore) : conto_aperto($tavolo_id, $conto_id);
            if ($conto) {
                q('UPDATE conti SET coperti = ? WHERE id = ?', [$n, $conto['id']]);
                ricalcola_totale($conto['id']);
                chiudi_se_vuoto($conto['id']);
            }
            break;

        case 'invia':
            $conto = conto_aperto($tavolo_id, $conto_id);
            if ($conto) invia_righe($conto['id']);
            break;

        case 'parcheggia':
            // Comanda Bar finita: orario di ritiro (obbligatorio), nome e note
            $conto = conto_aperto($tavolo_id, $conto_id);
            $n = $conto ? q('SELECT COUNT(*) FROM righe WHERE conto_id = ?', [$conto['id']])->fetchColumn() : 0;
            if (!$n || $conto['tipo'] !== 'asporto') throw new ErroreConto('Aggiungi almeno un prodotto prima di parcheggiare');

            $ora = (string)($in['orario'] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ora)) throw new ErroreConto("Scegli l'orario di ritiro");
            // Orario di oggi; se è molto prima di adesso si intende domani (es. alle 23:50 ordino per le 00:30)
            $ritiro = new DateTime("today $ora");
            if ($ritiro < new DateTime('-3 hours')) $ritiro->modify('+1 day');

            $nome = trim(mb_substr((string)($in['nome'] ?? ''), 0, 60));
            $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 255));
            q('UPDATE conti SET orario_ritiro = ?, nome_cliente = ?, note = ? WHERE id = ?',
              [$ritiro->format('Y-m-d H:i:s'), $nome === '' ? null : $nome, $note === '' ? null : $note, $conto['id']]);

            if (!empty($in['invia'])) invia_righe($conto['id']);
            break;

        case 'annulla':
            // Elimina una comanda Bar, o il conto di un tavolo (solo cassa/admin: il tavolo torna libero), con tutti gli articoli
            $conto = conto_aperto($tavolo_id, $conto_id);
            if (!$conto) throw new ErroreConto('Comanda non trovata');
            if ($conto['tipo'] !== 'asporto' && !$puo_stornare) throw new ErroreConto('Il conto del tavolo lo cancella la cassa');
            $inviate = q('SELECT COUNT(*) FROM righe WHERE conto_id = ? AND invio > 0', [$conto['id']])->fetchColumn();
            if ($inviate && !$puo_stornare) throw new ErroreConto('Comanda già inviata in cucina: chiedi alla cassa');
            if (q('SELECT 1 FROM documenti WHERE conto_id = ? AND tipo = "scontrino" AND stato = "attesa"', [$conto['id']])->fetch()) {
                throw new ErroreConto('Scontrino in corso su questo conto: aspetta la risposta della cassa');
            }
            q('DELETE FROM righe WHERE conto_id = ?', [$conto['id']]);
            q('DELETE FROM conti WHERE id = ?', [$conto['id']]);
            break;

        default:
            throw new ErroreConto('Azione sconosciuta');
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    if ($e instanceof ErroreConto) {
        rispondi(['errore' => $e->getMessage()] + risposta(), 400);
    }
    error_log('api/conto.php: ' . $e->getMessage());
    rispondi(['errore' => 'Errore del database'] + risposta(), 500);
}

// Riga appena inserita (le varianti dalla colonna categorie vanno su di lei)
$extra = isset($ultima_riga) ? ['riga' => $ultima_riga] : [];
// Comanda appena inviata: il programma desktop la stampa sulle sue stampanti (il sito non stampa)
if ($da_stampare) $extra['comanda'] = dati_comanda(...$da_stampare);

rispondi($extra + risposta());
