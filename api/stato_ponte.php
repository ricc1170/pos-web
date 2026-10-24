<?php
// ============================================================
//  api/stato_ponte.php  -  Il ponte di stampa è collegato? (per l'avviso in sala)
//  Risposta: { collegato: true|false }
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stampa.php';

header('Content-Type: application/json; charset=utf-8');

if (!operatore_loggato()) {
    http_response_code(401);
    exit(json_encode(['errore' => 'Sessione scaduta']));
}

echo json_encode(['collegato' => ponte_collegato()]);
