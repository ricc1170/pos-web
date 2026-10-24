<?php
// ============================================================
//  Gestione sessione operatore (login con PIN)
// ============================================================
require_once __DIR__ . '/db.php';

// Dopo questi PIN sbagliati nell'intervallo, l'IP viene bloccato
const LOGIN_MAX_ERRORI = 5;
const LOGIN_MINUTI     = 10;

if (session_status() === PHP_SESSION_NONE) {
    // Cookie di sessione non leggibile da JavaScript e inviato solo in HTTPS quando il sito lo usa
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function ip_client(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? 'sconosciuto', 0, 45);
}

// Minuti di attesa se l'IP ha sbagliato troppe volte, altrimenti 0
function login_bloccato(): int {
    $r = q('SELECT COUNT(*) AS n, MIN(creato) AS primo FROM login_tentativi
            WHERE ip = ? AND creato > NOW() - INTERVAL ' . LOGIN_MINUTI . ' MINUTE', [ip_client()])->fetch();
    if ($r['n'] < LOGIN_MAX_ERRORI) return 0;
    $fine = q('SELECT TIMESTAMPDIFF(SECOND, NOW(), ? + INTERVAL ' . LOGIN_MINUTI . ' MINUTE)', [$r['primo']])->fetchColumn();
    return max(1, (int)ceil($fine / 60));
}

// Prova a loggare un operatore dal PIN. Restituisce true/false.
function login_pin(string $pin): bool {
    $st = q('SELECT id, nome, ruolo FROM operatori WHERE pin = ? AND attivo = 1', [$pin]);
    $op = $st->fetch();
    if ($op) {
        session_regenerate_id(true);
        $_SESSION['operatore'] = $op;
        q('DELETE FROM login_tentativi WHERE ip = ?', [ip_client()]);
        return true;
    }
    q('INSERT INTO login_tentativi (ip) VALUES (?)', [ip_client()]);
    q('DELETE FROM login_tentativi WHERE creato < NOW() - INTERVAL 1 DAY');
    return false;
}

// PIN valido: da 4 a 6 cifre
function pin_valido(string $pin): bool {
    return (bool)preg_match('/^\d{4,6}$/', $pin);
}

// PIN troppo facile da indovinare: cifre tutte uguali o in sequenza
function pin_debole(string $pin): bool {
    return count(array_unique(str_split($pin))) === 1
        || strpos('0123456789', $pin) !== false
        || strpos('9876543210', $pin) !== false;
}

// Da mettere in cima alle pagine di impostazioni: solo l'admin entra
function richiedi_admin(): array {
    richiedi_login();
    $op = operatore_loggato();
    if ($op['ruolo'] !== 'admin') {
        header('Location: sala.php');
        exit;
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $op;
}

// Controllo del token anti-CSRF dei form
function controlla_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        die('Richiesta non valida, ricarica la pagina');
    }
}

function logout() {
    $_SESSION = [];
    session_destroy();
}

function operatore_loggato(): ?array {
    return $_SESSION['operatore'] ?? null;
}

// Da mettere in cima a ogni pagina protetta
function richiedi_login() {
    if (!operatore_loggato()) {
        header('Location: index.php');
        exit;
    }
}
