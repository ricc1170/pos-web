<?php
// ============================================================
//  stampanti.php  -  Configurazione stampanti di rete (solo admin)
//  Centro: elenco · Destra: scheda (nome, IP, porta, tipo, carta, attiva)
//  "Prova di stampa" invia un foglietto alla stampante salvata.
// ============================================================
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stampa.php';
$op = richiedi_admin();

$tipi      = ['cucina' => '🍕 Cucina', 'bar' => '🍹 Bar', 'cassa' => '💶 Cassa'];
// Caratteri per riga a dimensione normale (dipende da carta e font impostati sulla stampante)
$larghezze = [32 => '32 caratteri (es. Bixolon SRP-350)', 42 => '42 caratteri', 48 => '48 caratteri'];

$errore = '';
$dati   = null;

// Ponte di stampa: serve quando il POS gira su internet (sito online)
$serve_ponte = usa_ponte('192.168.0.1');

// Indirizzo del POS per i file scaricati. Online sempre https (la chiave del ponte non deve viaggiare in chiaro),
// anche se la pagina è stata aperta con http; in rete locale si lascia com'è.
function indirizzo_pos(string $percorso): string {
    $host   = $_SERVER['HTTP_HOST'];
    $locale = preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https || !$locale ? 'https' : 'http') . '://' . $host
         . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/' . $percorso;
}

// Download del ponte: script già configurato con indirizzo del sito e chiave, e file di avvio
if (isset($_GET['scarica'])) {
    if ($_GET['scarica'] === 'ponte') {
        $url = indirizzo_pos('api/ponte.php');

        $script = strtr(file_get_contents(__DIR__ . '/includes/ponte-modello.ps1'),
                        ['{{URL}}' => $url, '{{CHIAVE}}' => chiave_ponte()]);
        $nome = 'ponte.ps1';
        // BOM: PowerShell 5 legge correttamente le lettere accentate
        $contenuto = "\xEF\xBB\xBF" . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $script);
    } elseif ($_GET['scarica'] === 'schermo') {
        // Avvia il ponte e apre il POS a tutto schermo (modalità chiosco)
        $url  = indirizzo_pos('');

        $nome  = 'POS a tutto schermo.bat';
        $contenuto = str_replace(["\r\n", "\n"], ["\n", "\r\n"],
                                 strtr(file_get_contents(__DIR__ . '/includes/schermo-modello.bat'), ['{{URL}}' => $url]));
    } elseif ($_GET['scarica'] === 'installa') {
        $nome = 'installa-ponte.bat';
        $contenuto = "@echo off\r\ntitle Installazione ponte di stampa POS\r\n"
                   . "if not exist \"%~dp0ponte.ps1\" (echo Metti ponte.ps1 nella stessa cartella di questo file. & pause & exit /b 1)\r\n"
                   . "powershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0ponte.ps1\" -Installa\r\npause\r\n";
    } else {
        $nome = 'avvia-ponte.bat';
        $contenuto = "@echo off\r\ntitle Ponte di stampa POS\r\n"
                   . "powershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0ponte.ps1\"\r\npause\r\n";
    }
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"$nome\"");
    header('Cache-Control: no-store');
    exit($contenuto);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    controlla_csrf();
    $id     = (int)($_POST['id'] ?? 0);
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'nuova_chiave') {
        // Il vecchio ponte.ps1 smette di funzionare: va riscaricato
        salva_impostazione('ponte_chiave', bin2hex(random_bytes(16)));
        $_SESSION['msg'] = 'Nuova chiave creata: riscarica ponte.ps1 e sostituiscilo sul PC del locale.';
        header('Location: stampanti.php' . ($id ? "?id=$id" : ''));
        exit;
    }

    if ($azione === 'cancella' || $azione === 'prova') {
        $st = q('SELECT * FROM stampanti WHERE id = ?', [$id])->fetch();
        if ($st && $azione === 'cancella') {
            q('DELETE FROM stampanti WHERE id = ?', [$id]);
            $_SESSION['msg'] = "Stampante \"{$st['nome']}\" cancellata.";
            header('Location: stampanti.php');
            exit;
        }
        if ($st) {
            [$ok, $testo] = stampa($st, scontrino_prova($st), 'Prova di stampa');
            $_SESSION[$ok ? 'msg' : 'err'] = $testo;
        }
        header("Location: stampanti.php?id=$id");
        exit;
    }

    $dati = [
        'id'        => $id,
        'nome'      => trim($_POST['nome'] ?? ''),
        'ip'        => trim($_POST['ip'] ?? ''),
        'porta'     => (int)($_POST['porta'] ?? 9100),
        'tipo'      => array_key_exists($_POST['tipo'] ?? '', $tipi) ? $_POST['tipo'] : 'cucina',
        'larghezza' => array_key_exists((int)($_POST['larghezza'] ?? 0), $larghezze) ? (int)$_POST['larghezza'] : 48,
        'attiva'    => isset($_POST['attiva']) ? 1 : 0,
    ];

    if ($dati['nome'] === '' || mb_strlen($dati['nome']) > 40) {
        $errore = 'Il nome è obbligatorio (max 40 caratteri)';
    } elseif (!filter_var($dati['ip'], FILTER_VALIDATE_IP)) {
        $errore = 'Indirizzo IP non valido (es. 192.168.1.50)';
    } elseif ($dati['porta'] < 1 || $dati['porta'] > 65535) {
        $errore = 'Porta non valida (di solito 9100)';
    }

    if (!$errore) {
        $valori = [$dati['nome'], $dati['ip'], $dati['porta'], $dati['tipo'], $dati['larghezza'], $dati['attiva']];
        if ($id) {
            q('UPDATE stampanti SET nome = ?, ip = ?, porta = ?, tipo = ?, larghezza = ?, attiva = ? WHERE id = ?', [...$valori, $id]);
        } else {
            q('INSERT INTO stampanti (nome, ip, porta, tipo, larghezza, attiva) VALUES (?, ?, ?, ?, ?, ?)', $valori);
            $id = (int)db()->lastInsertId();
        }
        $_SESSION['msg'] = "Stampante \"{$dati['nome']}\" salvata.";
        header("Location: stampanti.php?id=$id");
        exit;
    }
}

// ------------------------------------------------------------
//  Dati per la pagina
// ------------------------------------------------------------
$stampanti = q('SELECT * FROM stampanti ORDER BY attiva DESC, FIELD(tipo, "cucina", "bar", "cassa"), nome')->fetchAll();

if (!$dati) {
    $id_sel = (int)($_GET['id'] ?? 0);
    $dati = $id_sel ? q('SELECT * FROM stampanti WHERE id = ?', [$id_sel])->fetch() : null;
    if (!$dati) {
        $dati = ['id' => 0, 'nome' => '', 'ip' => '192.168.0.', 'porta' => 9100,
                 'tipo' => 'cucina', 'larghezza' => 32, 'attiva' => 1];
    }
}

// Stato del ponte e ultime stampe passate dalla coda
if ($serve_ponte) {
    $in_attesa = (int)q('SELECT COUNT(*) FROM coda_stampa WHERE stato = "attesa"')->fetchColumn();
    $ultime = q('SELECT c.descrizione, c.stato, c.tentativi, c.errore, c.creato, s.nome AS stampante
                 FROM coda_stampa c JOIN stampanti s ON s.id = c.stampante_id
                 ORDER BY c.id DESC LIMIT 5')->fetchAll();
    $visto = impostazione('ponte_visto');
}

$msg = $_SESSION['msg'] ?? '';
$err = $_SESSION['err'] ?? '';
unset($_SESSION['msg'], $_SESSION['err']);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Stampanti</title>
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

  <div class="topbar">
    <span>POS - Rel. 1.0 - Int.: <?= h(impostazione('nome_locale')) ?></span>
    <span class="cassa">Cassa: <?= h(impostazione('numero_cassa', '1')) ?></span>
  </div>

  <div class="inforow">
    <span>Utente <?= h($op['nome']) ?></span>
    <span class="data">Impostazioni · Stampanti</span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
      <a class="btn verde" href="stampanti.php"><span class="ico">＋</span>Nuova</a>
    </div>

    <!-- Elenco stampanti -->
    <div class="ordini">
      <?php if ($serve_ponte): ?>
        <!-- Ponte di stampa: il sito online non raggiunge le stampanti, ci pensa il PC del locale -->
        <div class="ponte-box">
          <div class="ponte-stato <?= ponte_collegato() ? 'ok' : '' ?>">
            <b>Ponte di stampa:</b>
            <?= ponte_collegato() ? '● collegato' : '○ non collegato' ?>
            <?php if ($visto): ?><small>(ultimo contatto <?= date('d/m H:i:s', strtotime($visto)) ?>)</small><?php endif; ?>
            · <?= $in_attesa ?> stampe in attesa
          </div>
          <div class="hint">
            Il sito online non può raggiungere le stampanti del locale: le stampe passano dal
            <b>ponte</b>, un programma che gira su un PC del locale (acceso durante il servizio).
            Sul PC del locale scarica <b>ponte.ps1</b> e <b>installa-ponte.bat</b> nella stessa cartella e fai doppio clic
            su <b>installa-ponte.bat</b> (una volta sola): da quel momento il ponte parte da solo all'accesso a Windows.
            <b>avvia-ponte.bat</b> serve solo per avviarlo a mano. <b>POS a tutto schermo.bat</b> (da mettere sul Desktop)
            avvia il ponte e apre il POS a tutto schermo.
          </div>
          <div class="ponte-azioni">
            <a class="btn" href="stampanti.php?scarica=ponte"><span class="ico">⬇</span>ponte.ps1</a>
            <a class="btn verde" href="stampanti.php?scarica=installa"><span class="ico">⬇</span>installa-ponte.bat</a>
            <a class="btn" href="stampanti.php?scarica=avvio"><span class="ico">⬇</span>avvia-ponte.bat</a>
            <a class="btn" href="stampanti.php?scarica=schermo"><span class="ico">⬇</span>POS a tutto schermo.bat</a>
            <form method="post" onsubmit="return confirm('Creare una nuova chiave? Il ponte attuale smetterà di funzionare finché non riscarichi ponte.ps1.')">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">
              <button type="submit" name="azione" value="nuova_chiave" class="btn scuro"><span class="ico">🔑</span>Nuova chiave</button>
            </form>
          </div>
          <?php if ($ultime): ?>
            <div class="ponte-ultime">
              <?php foreach ($ultime as $u): ?>
                <div class="<?= $u['stato'] ?>">
                  <?= date('H:i:s', strtotime($u['creato'])) ?> · <?= h($u['descrizione']) ?> → <?= h($u['stampante']) ?>:
                  <b><?= ['attesa' => 'in attesa', 'stampata' => 'stampata', 'errore' => 'NON stampata'][$u['stato']] ?></b>
                  <?php if ($u['errore'] && $u['stato'] !== 'stampata'): ?>
                    <small>(<?= h($u['errore']) ?><?= $u['tentativi'] ? ", {$u['tentativi']} tentativi" : '' ?>)</small>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php foreach ($stampanti as $s): ?>
        <a class="ordine <?= $s['attiva'] ? '' : 'sospesa' ?> <?= $s['id'] == $dati['id'] ? 'sel' : '' ?>"
           href="stampanti.php?id=<?= $s['id'] ?>">
          <span class="ico-op">🖨</span>
          <span class="cliente"><?= h($s['nome']) ?></span>
          <span class="dettagli"><?= $tipi[$s['tipo']] ?> · <?= h($s['ip']) ?>:<?= (int)$s['porta'] ?></span>
          <span class="dettagli"><?= h($larghezze[$s['larghezza']] ?? $s['larghezza'] . ' caratteri') ?></span>
          <?php if (!$s['attiva']): ?><span class="stato vuota">Disattivata</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$stampanti): ?>
        <div class="vuoto">Nessuna stampante configurata. Usa il modulo a destra per aggiungerne una.</div>
      <?php endif; ?>
    </div>

    <!-- Scheda stampante -->
    <form class="scheda" method="post" autocomplete="off">
      <h2><?= $dati['id'] ? 'Modifica stampante' : 'Nuova stampante' ?></h2>
      <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="msg errore"><?= h($err) ?></div><?php endif; ?>
      <?php if ($errore): ?><div class="msg errore"><?= h($errore) ?></div><?php endif; ?>

      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="id" value="<?= (int)$dati['id'] ?>">

      <label>Nome
        <input type="text" name="nome" maxlength="40" required value="<?= h($dati['nome']) ?>" placeholder="es. Cucina forno">
      </label>

      <div class="due">
        <label>Indirizzo IP
          <input type="text" name="ip" maxlength="45" required inputmode="decimal" value="<?= h($dati['ip']) ?>">
        </label>
        <label>Porta
          <input type="number" name="porta" min="1" max="65535" required value="<?= (int)$dati['porta'] ?>">
        </label>
      </div>

      <label>Tipo (cosa stampa)</label>
      <div class="scelta tre">
        <?php foreach ($tipi as $val => $etichetta): ?>
          <label><input type="radio" name="tipo" value="<?= $val ?>" <?= $dati['tipo'] === $val ? 'checked' : '' ?>><span><?= $etichetta ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="hint">Cucina e Bar: comande dei prodotti con quella destinazione (vedi Categorie) · Cassa: conti e scontrini.</div>

      <label>Larghezza riga (a dimensione normale)
        <select name="larghezza">
          <?php foreach ($larghezze as $val => $etichetta): ?>
            <option value="<?= $val ?>" <?= (int)$dati['larghezza'] === $val ? 'selected' : '' ?>><?= $etichetta ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="check">
        <input type="checkbox" name="attiva" <?= $dati['attiva'] ? 'checked' : '' ?>>
        Attiva
      </label>

      <div class="hint">
        <?= $serve_ponte
            ? 'La prova di stampa passa dal ponte di stampa (vedi a sinistra): deve essere collegato.'
            : 'Questo POS è nella rete del locale: stampa direttamente sulla stampante.' ?>
      </div>

      <div class="scheda-piede <?= $dati['id'] ? 'tre' : '' ?>">
        <button type="submit" name="azione" value="salva" class="btn verde"><span class="ico">✔</span>Salva</button>
        <?php if ($dati['id']): ?>
          <button type="submit" name="azione" value="prova" class="btn" formnovalidate>
            <span class="ico">🖨</span>Prova di stampa</button>
          <button type="submit" name="azione" value="cancella" class="btn rosso" formnovalidate
                  onclick="return confirm('Cancellare questa stampante?')"><span class="ico">🗑</span>Cancella</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

</body>
</html>
