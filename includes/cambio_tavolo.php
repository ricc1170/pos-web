<?php
// ============================================================
//  Cambio tavolo: sposta il conto aperto di un tavolo su un altro tavolo.
//  Se il tavolo di destinazione ha già un conto, i due conti si uniscono.
//  Il foglietto "CAMBIO TAVOLO" per le stampanti che avevano già ricevuto comande lo stampa
//  il programma desktop (stampanti configurate sul PC): qui si restituiscono le righe già inviate.
// ============================================================

class ErroreCambio extends Exception {}

// Restituisce [esito, messaggio, avviso]; esito = 'ok' | 'serve_unione' (destinazione occupata, va confermata).
// avviso = dati per il foglietto di cambio tavolo (null se nessuna comanda era stata inviata)
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
            return ['serve_unione', "Il tavolo {$t_a['nome']} è occupato: unire i due conti?", null];
        }

        // Righe già inviate: il programma trova le stampanti che le hanno ricevute e le avvisa dello spostamento
        $inviate = q('SELECT r.articolo_id, a.categoria_id, cat.destinazione, r.note
                      FROM righe r JOIN articoli a ON a.id = r.articolo_id LEFT JOIN categorie cat ON cat.id = a.categoria_id
                      WHERE r.conto_id = ? AND r.invio > 0', [$c_da['id']])->fetchAll();
        foreach ($inviate as &$r) {
            $r = ['articolo_id' => (int)$r['articolo_id'], 'categoria_id' => (int)$r['categoria_id'],
                  'destinazione' => (string)$r['destinazione'], 'note' => (string)$r['note']];
        }
        unset($r);

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

    $avviso = $inviate ? ['unione' => (bool)$c_a, 'da' => $t_da['nome'], 'a' => $t_a['nome'],
                          'sala_da' => $t_da['sala'], 'sala_a' => $t_a['sala'], 'righe' => $inviate] : null;
    return ['ok', $messaggio, $avviso];
}
