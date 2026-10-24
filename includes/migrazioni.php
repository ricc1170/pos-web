<?php
// ============================================================
//  Aggiornamento automatico del database
//  A ogni richiesta controlla i file migrazioni/NNN_nome.sql e applica
//  quelli non ancora registrati nella tabella `migrazioni`.
//  Così online basta caricare i file nuovi: il database si aggiorna da solo.
//
//  Regola: ogni nuova migrazione va aggiunta ANCHE a database.sql
//  (la modifica + INSERT INTO migrazioni), così le installazioni nuove
//  partono già aggiornate.
// ============================================================

// Migrazioni già comprese nei database creati prima di questo sistema
const MIGRAZIONI_BASE = 4;

// Esegue un file SQL istruzione per istruzione (senza commenti)
function esegui_sql(PDO $pdo, string $sql, ?callable $salta = null) {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $istr) {
        $istr = trim($istr);
        if ($istr === '' || ($salta && $salta($istr))) continue;
        $pdo->exec($istr);
    }
}

function aggiorna_database(PDO $pdo) {
    $file = glob(__DIR__ . '/../migrazioni/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($file);

    $esiste = (bool)$pdo->query('SHOW TABLES LIKE "migrazioni"')->fetch();
    if (!$esiste) {
        // POS non ancora installato: ci pensa install.php
        if (!$pdo->query('SHOW TABLES LIKE "operatori"')->fetch()) return;
        // Database creato prima di questo sistema: le prime migrazioni sono già dentro
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrazioni (
                      nome     VARCHAR(100) PRIMARY KEY,
                      eseguita TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB');
        $st = $pdo->prepare('INSERT IGNORE INTO migrazioni (nome) VALUES (?)');
        foreach ($file as $f) {
            if ((int)basename($f) <= MIGRAZIONI_BASE) $st->execute([basename($f)]);
        }
    }

    $fatte = $pdo->query('SELECT nome FROM migrazioni')->fetchAll(PDO::FETCH_COLUMN);
    $da_fare = array_filter($file, fn($f) => !in_array(basename($f), $fatte, true));
    if (!$da_fare) return;

    // Una sola richiesta alla volta applica le migrazioni
    if (!$pdo->query('SELECT GET_LOCK("pos_migrazioni", 30)')->fetchColumn()) return;
    try {
        $fatte = $pdo->query('SELECT nome FROM migrazioni')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($da_fare as $f) {
            $nome = basename($f);
            if (in_array($nome, $fatte, true)) continue;
            try {
                esegui_sql($pdo, file_get_contents($f));
            } catch (PDOException $e) {
                error_log("Migrazione $nome fallita: " . $e->getMessage());
                http_response_code(500);
                die("Aggiornamento del database non riuscito ($nome). Avvisa l'amministratore.");
            }
            $pdo->prepare('INSERT INTO migrazioni (nome) VALUES (?)')->execute([$nome]);
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK("pos_migrazioni")');
    }
}
