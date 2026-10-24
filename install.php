<?php
// ============================================================
//  install.php  -  Installazione su un database vuoto (es. online)
//  Crea le tabelle da database.sql e gli operatori con PIN scelti qui.
//  Per sicurezza chiede la password del database (in config.php) e
//  non fa nulla se il POS è già installato. Dopo l'uso: CANCELLARLO.
// ============================================================
require_once __DIR__ . '/includes/auth.php';

$requisiti = [
    'PHP 7.4 o superiore (trovato ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '7.4', '>='),
    'Estensione PDO MySQL'                            => extension_loaded('pdo_mysql'),
    'Estensione mbstring'                             => extension_loaded('mbstring'),
];
$requisiti_ok = !in_array(false, $requisiti, true);

if ($requisiti_ok) {
    // MySQL 5.5.3+ (per utf8mb4); i default di data sono scritti per funzionare anche prima della 5.6
    $versione_db = db()->query('SELECT VERSION()')->fetchColumn();
    $requisiti["MySQL 5.5.3 o superiore (trovato $versione_db)"] = version_compare($versione_db, '5.5.3', '>=');
    $requisiti_ok = !in_array(false, $requisiti, true);
}

// Una tabella esiste e contiene già righe?
function tabella_piena(string $tabella): bool {
    if (!db()->query('SHOW TABLES LIKE ' . db()->quote($tabella))->fetch()) return false;
    return (bool)db()->query("SELECT 1 FROM `$tabella` LIMIT 1")->fetch();
}

// Installato = ci sono già operatori. Se un'installazione si è interrotta a metà,
// rieseguendola si riparte: tabelle esistenti e dati già caricati vengono saltati
$installato = $requisiti_ok && tabella_piena('operatori');

$errore = '';
$fatto  = false;
$f = [
    'nome_locale' => $_POST['nome_locale'] ?? 'PIZZERIA BUGLIANI SNC',
    'esempio'     => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['esempio']) : true,
    'op'          => $_POST['op'] ?? [
        ['nome' => 'Admin',  'pin' => '', 'ruolo' => 'admin'],
        ['nome' => 'Samadi', 'pin' => '', 'ruolo' => 'cassa'],
        ['nome' => 'Mario',  'pin' => '', 'ruolo' => 'cameriere'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requisiti_ok && !$installato) {
    // Operatori compilati (l'admin è obbligatorio, gli altri facoltativi)
    $operatori = [];
    $pin_usati = [];
    foreach (array_slice($f['op'], 0, 3) as $i => $o) {
        $nome  = trim(mb_substr((string)($o['nome'] ?? ''), 0, 50));
        $pin   = trim((string)($o['pin'] ?? ''));
        $ruolo = ['admin', 'cassa', 'cameriere'][$i];
        if ($i > 0 && $nome === '' && $pin === '') continue;
        if ($nome === '') {
            $errore = "Manca il nome dell'operatore $ruolo";
        } elseif (!pin_valido($pin)) {
            $errore = "PIN di $nome: da 4 a 6 cifre";
        } elseif (pin_debole($pin)) {
            $errore = "PIN di $nome troppo facile (niente cifre tutte uguali o in sequenza)";
        } elseif (isset($pin_usati[$pin])) {
            $errore = 'Due operatori non possono avere lo stesso PIN';
        }
        if ($errore) break;
        $pin_usati[$pin] = true;
        $operatori[] = [$nome, $pin, $ruolo];
    }

    if (!$errore && !hash_equals(DB_PASS, (string)($_POST['db_pass'] ?? ''))) {
        $errore = 'Password del database errata';
    } elseif (!$errore && trim($f['nome_locale']) === '') {
        $errore = 'Inserisci il nome del locale';
    }

    if (!$errore) {
        // Istruzioni di database.sql; salto la creazione del database "pos"
        $esempio_tab = ['sale', 'tavoli', 'categorie', 'articoli', 'varianti', 'varianti_categorie'];
        try {
            esegui_sql(db(), file_get_contents(__DIR__ . '/database.sql'), function ($istr) use ($f, $esempio_tab) {
                if (preg_match('/^(CREATE DATABASE|USE)\b/i', $istr)) return true;
                if (preg_match('/^INSERT (IGNORE )?INTO (\w+)/i', $istr, $m)) {
                    if ($m[2] === 'operatori') return true;                                 // li creo sotto con i PIN scelti
                    if (in_array($m[2], $esempio_tab, true) && !$f['esempio']) return true;  // dati di esempio facoltativi
                    if (tabella_piena($m[2])) return true;                                  // già caricati in un tentativo precedente
                }
                return false;
            });
            foreach ($operatori as $o) {
                q('INSERT INTO operatori (nome, pin, ruolo) VALUES (?, ?, ?)', $o);
            }
            q('UPDATE impostazioni SET valore = ? WHERE chiave = "nome_locale"', [trim($f['nome_locale'])]);
            $fatto = true;
        } catch (PDOException $e) {
            $errore = "Errore durante l'installazione: " . $e->getMessage()
                    . " (MySQL $versione_db). Puoi correggere e premere di nuovo Installa: riparte da dove si è fermata.";
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Installazione POS</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0a1a3a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/icona-192.png">
<link rel="icon" href="assets/icona-192.png">
<script src="assets/js/schermo.js?v=<?= @filemtime(__DIR__ . '/assets/js/schermo.js') ?>" defer></script>
</head>
<body>
  <div class="topbar"><span>POS - Installazione</span></div>

  <div class="install">
    <?php if (!$requisiti_ok): ?>
      <div class="scheda">
        <h2>Requisiti mancanti</h2>
        <?php foreach ($requisiti as $nome => $ok): ?>
          <div class="msg <?= $ok ? 'ok' : 'errore' ?>"><?= $ok ? '✔' : '✖' ?> <?= h($nome) ?></div>
        <?php endforeach; ?>
        <div class="hint">Attivali dal pannello dell'hosting e ricarica la pagina.</div>
      </div>

    <?php elseif ($fatto): ?>
      <div class="scheda">
        <h2>Installazione completata</h2>
        <div class="msg ok">Tabelle create e operatori inseriti.</div>
        <div class="msg errore">IMPORTANTE: ora cancella il file <b>install.php</b> dal server.</div>
        <a class="btn verde grande" href="index.php"><span class="ico">➜</span>Vai al login</a>
      </div>

    <?php elseif ($installato): ?>
      <div class="scheda">
        <h2>POS già installato</h2>
        <div class="msg ok">Il database contiene già le tabelle: non c'è niente da fare.</div>
        <div class="msg errore">Cancella il file <b>install.php</b> dal server.</div>
        <a class="btn grande" href="index.php"><span class="ico">➜</span>Vai al login</a>
      </div>

    <?php else: ?>
      <form class="scheda" method="post" autocomplete="off">
        <h2>Installazione POS</h2>
        <div class="hint" style="color:#9fc2e8">Database: <b><?= h(DB_NAME) ?></b> — verranno create le tabelle del POS.</div>
        <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

        <label>Password del database (quella in config.php, per conferma)
          <input type="password" name="db_pass" required>
        </label>

        <label>Nome del locale
          <input type="text" name="nome_locale" maxlength="100" required value="<?= h($f['nome_locale']) ?>">
        </label>

        <h3>Operatori (PIN da 4 a 6 cifre)</h3>
        <?php foreach (['Admin (obbligatorio)', 'Cassa (facoltativo)', 'Cameriere (facoltativo)'] as $i => $titolo): ?>
          <div class="due">
            <label><?= $titolo ?>
              <input type="text" name="op[<?= $i ?>][nome]" maxlength="50" value="<?= h($f['op'][$i]['nome'] ?? '') ?>" <?= $i === 0 ? 'required' : '' ?>>
            </label>
            <label>PIN
              <input type="password" name="op[<?= $i ?>][pin]" inputmode="numeric" pattern="\d{4,6}" maxlength="6" <?= $i === 0 ? 'required' : '' ?>>
            </label>
          </div>
        <?php endforeach; ?>

        <label class="check">
          <input type="checkbox" name="esempio" <?= $f['esempio'] ? 'checked' : '' ?>>
          Carica i dati di esempio (sale, tavoli, categorie e articoli della pizzeria)
        </label>

        <button type="submit" class="btn verde grande"><span class="ico">✔</span>Installa</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
