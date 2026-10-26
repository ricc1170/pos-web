<?php
// ============================================================
//  Connessione PDO condivisa + funzioni di comodo
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/migrazioni.php';

// Orari (ritiro asporto, ora a video) sempre in ora italiana, qualunque sia il fuso del server
date_default_timezone_set('Europe/Rome');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
        } catch (PDOException $e) {
            // Il dettaglio (host, utente) va solo nel log, non a video
            error_log('Connessione al database fallita: ' . $e->getMessage());
            http_response_code(500);
            die('Errore di connessione al database. Controlla config.php.');
        }
        // Applica le migrazioni nuove (vedi includes/migrazioni.php)
        aggiorna_database($pdo);
    }
    return $pdo;
}

// Connessione (e migrazioni) subito, prima che la pagina inizi a scrivere:
// così un eventuale messaggio d'errore appare da solo e leggibile
db();

// Esegue una query con parametri e restituisce lo statement
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// Legge una singola impostazione dalla tabella impostazioni
function impostazione(string $chiave, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT chiave, valore FROM impostazioni') as $r) {
            $cache[$r['chiave']] = $r['valore'];
        }
    }
    return $cache[$chiave] ?? $default;
}

function salva_impostazione(string $chiave, string $valore) {
    q('INSERT INTO impostazioni (chiave, valore) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE valore = VALUES(valore)', [$chiave, $valore]);
}

// Giornata di lavoro (Dati del locale): inizio e fine "HH:MM"; la fine può essere dopo mezzanotte (10:00 - 03:00)
const ORARIO_GIORNATA = ['10:00', '03:00'];

function orario_valido(string $s): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
}

function orario_giornata(): array {
    $inizio = (string)impostazione('giornata_inizio', ORARIO_GIORNATA[0]);
    $fine   = (string)impostazione('giornata_fine', ORARIO_GIORNATA[1]);
    return [orario_valido($inizio) ? $inizio : ORARIO_GIORNATA[0], orario_valido($fine) ? $fine : ORARIO_GIORNATA[1]];
}

function minuti(string $hhmm): int {
    return (int)substr($hhmm, 0, 2) * 60 + (int)substr($hhmm, 3, 2);
}

// Il giorno passa al successivo a metà delle ore di chiusura (10:00 - 03:00 -> alle 6:30: un conto all'1:30 va alla
// sera prima, un caffè alle 9:50 al giorno nuovo). Ogni giornata prende la data della sua apertura.
// Restituisce i minuti da togliere all'ora per ottenere quella data (negativi se l'apertura viene dopo il taglio).
function spostamento_giornata(): int {
    [$inizio, $fine] = array_map('minuti', orario_giornata());
    $chiuso = ($inizio - $fine + 1440) % 1440;          // durata della chiusura (0 = aperti 24 ore)
    $taglio = $chiuso === 0 ? $inizio : ($fine + intdiv($chiuso, 2)) % 1440;
    return $taglio <= $inizio ? $taglio : $taglio - 1440;
}

// Giornata di lavoro di una data/ora SQL: DATE(colonna - INTERVAL n MINUTE)
function giorno_lavoro_sql(string $colonna): string {
    return "DATE($colonna - INTERVAL " . spostamento_giornata() . ' MINUTE)';
}

// Scorciatoia per stampare testo in sicurezza (anti-XSS)
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
