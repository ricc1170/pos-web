<?php
// ============================================================
//  api/esporta.php  -  Esporta i dati del locale per il programma desktop con database locale (SQLite)
//  Usato UNA volta da importa_dal_sito.py (nel progetto pos): menu, sale e tavoli, operatori (con PIN),
//  varianti, note rapide, pagamenti, clienti, impostazioni. Niente conti né documenti.
//  Le fatture continuano la numerazione: per ogni anno "fattura_ultimo_<anno>" = ultima fattura emessa.
//
//  Solo admin, dopo il login di api/desktop.php (stessa sessione), con l'intestazione X-POS-Desktop: 1.
//    GET -> { tabelle: { nome: [righe] } }
// ============================================================
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function rispondi(array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati);
    exit;
}

if (($_SERVER['HTTP_X_POS_DESKTOP'] ?? '') !== '1') rispondi(['errore' => 'Richiesta non valida'], 400);
$op = operatore_loggato();
if (!$op) rispondi(['errore' => 'Sessione scaduta, rifare il login'], 401);
if ($op['ruolo'] !== 'admin') rispondi(['errore' => "Serve il PIN di un amministratore"], 403);

// Colonne come nel database locale del programma (pos_desktop/schema.sql)
$tabelle = [
    'operatori'          => 'id, nome, pin, ruolo, attivo',
    'sale'               => 'id, nome, ordine',
    'tavoli'             => 'id, sala_id, nome, posti, pos_x, pos_y, larghezza, altezza, forma, attivo',
    'categorie'          => 'id, nome, colore, ordine, destinazione',
    'articoli'           => 'id, categoria_id, nome, prezzo, aliquota_iva, colore, ordine, visibile, a_peso',
    'varianti'           => 'id, nome, prezzo, tutte, ordine, attiva',
    'varianti_categorie' => 'variante_id, categoria_id',
    'note_rapide'        => 'id, testo, ordine, attiva',
    'forme_pagamento'    => 'id, nome, ordine',
    'clienti'            => 'id, denominazione, nome, cognome, piva, cf, indirizzo, cap, comune, provincia, nazione,
                             codice_destinatario, pec, creato',
    'impostazioni'       => 'chiave, valore',
];
$dati = [];
foreach ($tabelle as $nome => $colonne) {
    $dati[$nome] = q("SELECT $colonne FROM $nome")->fetchAll();
}

// Ultima fattura di ogni anno (del POS o emessa con altri programmi): il programma continua da lì
$fatture = q('SELECT anno, MAX(numero) AS numero FROM documenti WHERE tipo = "fattura" GROUP BY anno')->fetchAll();
foreach ($fatture as $f) {
    $chiave = "fattura_ultimo_{$f['anno']}";
    $ultimo = max((int)$f['numero'], (int)impostazione($chiave, 0));
    $dati['impostazioni'] = array_values(array_filter($dati['impostazioni'], fn($i) => $i['chiave'] !== $chiave));
    $dati['impostazioni'][] = ['chiave' => $chiave, 'valore' => (string)$ultimo];
}

rispondi(['tabelle' => $dati]);
