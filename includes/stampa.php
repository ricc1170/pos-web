<?php
// ============================================================
//  Dati delle stampe per il programma desktop.
//  Il sito NON stampa più: stampanti (indirizzo, carta), smistamento dei prodotti e cassa fiscale
//  sono configurati sul PC della cassa (programma Python, pos_desktop/stampa.py).
//  Qui si preparano solo i dati che il programma stampa sulle sue stampanti.
// ============================================================

// Righe della comanda numero $invio del conto, con quello che serve al programma per scegliere la stampante
// (articolo, categoria, destinazione della categoria, note) e per comporre il foglio.
function dati_comanda(int $conto_id, int $invio): ?array {
    $c = q('SELECT c.*, t.nome AS tavolo, s.nome AS sala
            FROM conti c LEFT JOIN tavoli t ON t.id = c.tavolo_id LEFT JOIN sale s ON s.id = t.sala_id
            WHERE c.id = ?', [$conto_id])->fetch();
    $righe = q('SELECT r.id, r.articolo_id, a.categoria_id, cat.destinazione, r.quantita, r.descrizione, r.note,
                       r.operatore_id, COALESCE(a.a_peso, 0) AS a_peso
                FROM righe r LEFT JOIN articoli a ON a.id = r.articolo_id LEFT JOIN categorie cat ON cat.id = a.categoria_id
                WHERE r.conto_id = ? AND r.invio = ? ORDER BY r.id', [$conto_id, $invio])->fetchAll();
    if (!$c || !$righe) return null;

    $operatore = (string)q('SELECT nome FROM operatori WHERE id = ?', [$righe[0]['operatore_id']])->fetchColumn();
    foreach ($righe as &$r) {
        $r = [
            'articolo_id'  => $r['articolo_id'] === null ? null : (int)$r['articolo_id'],
            'categoria_id' => $r['categoria_id'] === null ? null : (int)$r['categoria_id'],
            'destinazione' => (string)$r['destinazione'],   // cucina / pizzeria / bar / banco / nessuna
            'descrizione'  => $r['descrizione'],
            'quantita'     => (float)$r['quantita'],
            'a_peso'       => (bool)$r['a_peso'],
            'note'         => (string)$r['note'],
            'varianti'     => q('SELECT segno, nome FROM righe_varianti WHERE riga_id = ? ORDER BY segno = "-", id',
                                [$r['id']])->fetchAll(),
        ];
    }
    unset($r);
    return [
        'invio'     => $invio,
        'tipo'      => $c['tipo'],
        'tavolo'    => (string)$c['tavolo'],
        'sala'      => (string)$c['sala'],
        'orario'    => $c['orario_ritiro'] ? date('H:i', strtotime($c['orario_ritiro'])) : null,
        'nome'      => (string)$c['nome_cliente'],
        'coperti'   => (int)$c['coperti'],
        'note'      => (string)$c['note'],
        'operatore' => $operatore,
        'righe'     => $righe,
    ];
}
