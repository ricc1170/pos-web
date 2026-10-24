<?php
// ============================================================
//  fattura_xml.php?id=N  -  Scarica la fattura elettronica (XML FatturaPA)
//  Il file si carica sul portale "Fatture e Corrispettivi" o si passa al commercialista.
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documenti.php';
richiedi_login();

$op = operatore_loggato();
$doc = q('SELECT * FROM documenti WHERE id = ? AND tipo = "fattura"', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$doc || !in_array($op['ruolo'], ['cassa', 'admin'], true)) {
    http_response_code(404);
    exit('Fattura non trovata');
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . nome_file_fattura($doc) . '"');
header('Cache-Control: no-store');
echo xml_fattura((int)$doc['id']);
