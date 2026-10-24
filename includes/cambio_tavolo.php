<?php
// ============================================================
//  Cambio tavolo: sposta il conto aperto di un tavolo su un altro tavolo.
//  Se il tavolo di destinazione ha già un conto, i due conti si uniscono.
//  Le stampanti che avevano già ricevuto comande stampano "CAMBIO TAVOLO".
// ============================================================
require_once __DIR__ . '/stampa.php';

class ErroreCambio extends Exception {}

// Restituisce [esito, messaggio]; esito = 'ok' | 'serve_unione' (destinazione occupata, va confermata)
function cambia_tavolo(int $da, int $a, bool $unisci): array {
    if ($da === $a) throw new ErroreCambio('Scegli un tavolo diverso');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Blocco i due tavoli sempre nello stesso ordine (niente stalli tra due dispositivi)
        q('SELECT id FROM tavoli WHERE id IN (?, ?) ORDER BY id FOR UPDATE', [$da, $a]);
        $t_da = q('SELECT t.*, s.nome AS sala FROM tavoli t JOIN sale s ON s.id = t.sala_id WHERE t.id = ?', [$da])->fetch();
        $t_a  = q('SELECT t.*, s.nome AS sala FROM tavoli t JOIN sale s ON s.id = t.sala_id WHERE t.id = ? AND t.attivo = 1', [$a])->fetch();
        if (!$t_da || !$t_a) throw new ErroreCambio('Tavolo non trovato');

        $c_da = q('SELECT * FROM conti WHERE tavolo_id = ? AND stato = "aperto" LIMIT 1', [$da])->fetch();
        if (!$c_da) throw new ErroreCambio("Il tavolo {$t_da['nome']} non ha un conto aperto");
        if (q('SELECT 1 FROM documenti WHERE conto_id = ? AND tipo = "scontrino" AND stato = "attesa"', [$c_da['id']])->fetch()) {
            throw new ErroreCambio("Il tavolo {$t_da['nome']} ha uno scontrino in corso");
        }
        $c_a = q('SELECT * FROM conti WHERE tavolo_id = ? AND stato = "aperto" LIMIT 1', [$a])->fetch();
        if ($c_a && !$unisci) {
            $pdo->rollBack();
            return ['serve_unione', "Il tavolo {$t_a['nome']} è occupato: unire i due conti?"];
        }

        // Stampanti che hanno già ricevuto comande di questo conto (per avvisarle dello spostamento)
        $stampanti = [];
        foreach (q('SELECT a.stampa, a.stampante_id, a.categoria_id FROM righe r JOIN articoli a ON a.id = r.articolo_id
                    WHERE r.conto_id = ? AND r.invio > 0', [$c_da['id']])->fetchAll() as $art) {
            if ($st = stampante_articolo($art)) $stampanti[$st['id']] = $st;
        }

        if (!$c_a) {
            q('UPDATE conti SET tavolo_id = ? WHERE id = ?', [$a, $c_da['id']]);
            $messaggio = "Conto spostato dal tavolo {$t_da['nome']} al tavolo {$t_a['nome']}";
        } else {
            // Unione: righe e documenti passano al conto di destinazione; le comande inviate
            // vengono rinumerate dopo quelle del tavolo di destinazione
            $max = (int)q('SELECT COALESCE(MAX(invio), 0) FROM righe WHERE conto_id = ?', [$c_a['id']])->fetchColumn();
            q('UPDATE righe SET conto_id = ?, invio = IF(invio > 0, invio + ?, 0) WHERE conto_id = ?', [$c_a['id'], $max, $c_da['id']]);
            q('UPDATE documenti SET conto_id = ? WHERE conto_id = ?', [$c_a['id'], $c_da['id']]);
            q('UPDATE conti SET coperti = coperti + ? WHERE id = ?', [$c_da['coperti'], $c_a['id']]);
            q('DELETE FROM conti WHERE id = ?', [$c_da['id']]);
            q('UPDATE conti SET totale =
                 (SELECT COALESCE(SUM(ROUND(quantita * (prezzo_unitario + prezzo_varianti), 2)), 0) FROM righe WHERE conto_id = ?)
                 + coperti * ?
               WHERE id = ?', [$c_a['id'], (float)impostazione('coperto', 0), $c_a['id']]);
            $messaggio = "Tavolo {$t_da['nome']} unito al tavolo {$t_a['nome']}";
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Foglietto per la cucina/bar: dopo il salvataggio, una stampante lenta non blocca
    $esiti = [];
    foreach ($stampanti as $st) {
        $w = (int)$st['larghezza'];
        $foglio = ESC_INIT . ESC_CENTRO
                . ESC_GRANDE . a_capo($c_a ? 'UNIONE TAVOLI' : 'CAMBIO TAVOLO', intdiv($w, 2))
                . a_capo("{$t_da['nome']} -> {$t_a['nome']}", intdiv($w, 2)) . ESC_NORMALE
                . a_capo("{$t_da['sala']} -> {$t_a['sala']}", $w)
                . a_capo(date('d/m H:i'), $w)
                . fine_stampa();
        [$ok, ] = stampa($st, $foglio, 'Cambio tavolo');
        $esiti[] = ($ok ? '✔ ' : '✖ ') . $st['nome'];
    }
    return ['ok', $messaggio . ($esiti ? ' — avviso stampato: ' . implode(' · ', $esiti) : '')];
}
