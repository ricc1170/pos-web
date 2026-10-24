<?php
// ============================================================
//  statistiche.php  -  Statistiche di vendita (solo admin)
//  Periodo: pulsanti rapidi (oggi, ieri, 7 giorni, mese, anno) o date libere.
//  Giornata di lavoro con inizio e fine scelti in Dati del locale (orario_giornata(), predefinita 10:00 - 03:00):
//  il giorno cambia a metà delle ore di chiusura, quindi un conto chiuso all'1:30 conta per la sera prima.
//  Contano solo i conti CHIUSI (scontrino, fattura); i conti cancellati non lasciano traccia.
//  SQL compatibile MySQL 5.5 (database online).
// ============================================================
require_once __DIR__ . '/includes/auth.php';
$op = richiedi_admin();

[$apre, $chiude] = orario_giornata();
$giorno = 'giorno_lavoro_sql';

// "Oggi" secondo l'orologio del database (come gli orari dei conti)
$oggi = new DateTime((string)q('SELECT ' . $giorno('NOW()'))->fetchColumn());

$data_valida = function ($s): ?DateTime {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s ? $d : null;
};

$rapidi = ['oggi' => 'Oggi', 'ieri' => 'Ieri', '7g' => 'Ultimi 7 giorni', 'mese' => 'Questo mese', 'anno' => "Quest'anno"];
$scelta = $_GET['p'] ?? 'oggi';
$dal = $data_valida($_GET['dal'] ?? '');
$al  = $data_valida($_GET['al'] ?? '');
if ($dal && $al) {
    $scelta = '';
    if ($dal > $al) [$dal, $al] = [$al, $dal];
} else {
    if (!isset($rapidi[$scelta])) $scelta = 'oggi';
    $al = clone $oggi;
    $dal = clone $oggi;
    if ($scelta === 'ieri') { $dal->modify('-1 day'); $al->modify('-1 day'); }
    if ($scelta === '7g')   $dal->modify('-6 days');
    if ($scelta === 'mese') $dal->modify('first day of this month');
    if ($scelta === 'anno') $dal->setDate((int)$oggi->format('Y'), 1, 1);
}
$par = [$dal->format('Y-m-d'), $al->format('Y-m-d')];
$giorni = (int)$dal->diff($al)->days + 1;

$mesi = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
$data_it = fn(DateTime $d) => $d->format('j') . ' ' . $mesi[(int)$d->format('n')] . ' ' . $d->format('Y');
$periodo = $giorni === 1 ? $data_it($dal) : $data_it($dal) . ' – ' . $data_it($al);

// Conti chiusi nel periodo (alias c)
$nel_periodo = 'c.stato = "chiuso" AND ' . $giorno('c.data_chiusura') . ' BETWEEN ? AND ?';
$importo_riga = 'ROUND(r.quantita * (r.prezzo_unitario + r.prezzo_varianti), 2)';
$pezzi_riga = 'CASE WHEN COALESCE(a.a_peso, 0) = 1 THEN 1 ELSE r.quantita END';   // un prodotto a peso = 1 pezzo

// ---------- Riepilogo ----------
$tot = q("SELECT COUNT(*) AS conti, COALESCE(SUM(c.totale), 0) AS incasso, COALESCE(SUM(c.coperti), 0) AS coperti,
                 COALESCE(SUM(c.tipo = 'tavolo'), 0) AS conti_tavolo,
                 COALESCE(SUM(CASE WHEN c.tipo = 'tavolo' THEN c.totale END), 0) AS incasso_tavoli
          FROM conti c WHERE $nel_periodo", $par)->fetch();
$tot['conti_bar'] = $tot['conti'] - $tot['conti_tavolo'];
$tot['incasso_bar'] = $tot['incasso'] - $tot['incasso_tavoli'];

$pagamenti = q("SELECT f.nome, COUNT(DISTINCT p.conto_id) AS conti, SUM(p.importo) AS importo
                FROM pagamenti p JOIN conti c ON c.id = p.conto_id JOIN forme_pagamento f ON f.id = p.forma_pagamento_id
                WHERE $nel_periodo GROUP BY f.id, f.nome ORDER BY importo DESC", $par)->fetchAll();

// ---------- Andamento: giorno per giorno (per mese oltre i due mesi) ----------
$per_mese = $giorni > 62;
$chiave = $per_mese ? 'DATE_FORMAT(' . $giorno('c.data_chiusura') . ', "%Y-%m")' : $giorno('c.data_chiusura');
$righe_tempo = q("SELECT $chiave AS k, SUM(c.totale) AS incasso, COUNT(*) AS conti
                  FROM conti c WHERE $nel_periodo GROUP BY k", $par)->fetchAll(PDO::FETCH_UNIQUE);
$tempo = [];   // anche i giorni senza incassi, a zero
for ($d = clone $dal; $d <= $al; $d->modify($per_mese ? 'first day of next month' : '+1 day')) {
    $k = $d->format($per_mese ? 'Y-m' : 'Y-m-d');
    $nome = $per_mese ? $mesi[(int)$d->format('n')] . ' ' . $d->format('Y')
                      : ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'][(int)$d->format('w')] . ' ' . $data_it($d);
    $breve = $per_mese ? $mesi[(int)$d->format('n')] : $d->format('j');
    $tempo[] = [$breve, (float)($righe_tempo[$k]['incasso'] ?? 0), $nome, (int)($righe_tempo[$k]['conti'] ?? 0)];
}

// ---------- Fasce orarie: quando si ordina (ora di inserimento degli articoli) ----------
$righe_ore = q("SELECT HOUR(r.creata_il) AS ora, SUM($importo_riga) AS importo, COUNT(DISTINCT r.conto_id) AS conti
                FROM righe r JOIN conti c ON c.id = r.conto_id WHERE $nel_periodo GROUP BY ora", $par)->fetchAll(PDO::FETCH_UNIQUE);
$ore = [];
if ($righe_ore) {
    // Ore nell'ordine della giornata (dal cambio di giorno in poi): dall'apertura alla chiusura,
    // allargato alle ore fuori orario solo se ci sono vendite
    $prima = intdiv((spostamento_giornata() + 1440 + 30) % 1440, 60);   // ora del cambio di giorno, arrotondata
    $ordine = array_map(fn($i) => ($prima + $i) % 24, range(0, 23));
    $posizione = array_flip($ordine);
    $usate = array_map(fn($h) => $posizione[$h], array_keys($righe_ore));
    $da = min($posizione[intdiv(minuti($apre), 60)], ...$usate);
    $a = max($posizione[intdiv((minuti($chiude) + 1439) % 1440, 60)], ...$usate);   // ora dell'ultimo minuto aperto
    foreach (array_slice($ordine, $da, $a - $da + 1) as $h) {
        $ore[] = [sprintf('%02d', $h), (float)($righe_ore[$h]['importo'] ?? 0),
                  sprintf('%02d:00 – %02d:59', $h, $h), (int)($righe_ore[$h]['conti'] ?? 0)];
    }
}

// ---------- Prodotti e categorie ----------
$prodotti = q("SELECT r.descrizione AS nome, COALESCE(cat.nome, '—') AS categoria, MAX(COALESCE(a.a_peso, 0)) AS a_peso,
                      SUM(r.quantita) AS qta, SUM($importo_riga) AS importo
               FROM righe r JOIN conti c ON c.id = r.conto_id
               LEFT JOIN articoli a ON a.id = r.articolo_id LEFT JOIN categorie cat ON cat.id = a.categoria_id
               WHERE $nel_periodo GROUP BY r.articolo_id, r.descrizione, cat.nome ORDER BY importo DESC, qta DESC", $par)->fetchAll();

$categorie = q("SELECT COALESCE(cat.nome, 'Altro') AS nome, SUM($pezzi_riga) AS pezzi, SUM($importo_riga) AS importo
                FROM righe r JOIN conti c ON c.id = r.conto_id
                LEFT JOIN articoli a ON a.id = r.articolo_id LEFT JOIN categorie cat ON cat.id = a.categoria_id
                WHERE $nel_periodo GROUP BY cat.id, cat.nome ORDER BY importo DESC", $par)->fetchAll();
// Il coperto non è una riga: è la differenza tra il totale dei conti e i loro articoli
$coperto = round($tot['incasso'] - array_sum(array_column($categorie, 'importo')), 2);
if ($coperto > 0) {
    $categorie[] = ['nome' => 'Coperto', 'pezzi' => $tot['coperti'], 'importo' => $coperto];
    usort($categorie, fn($x, $y) => $y['importo'] <=> $x['importo']);
}

// ---------- Operatori: chi ha inserito gli articoli, chi ha aperto i conti ----------
$operatori = [];
foreach (q("SELECT r.operatore_id AS id, COALESCE(o.nome, '—') AS nome, SUM($pezzi_riga) AS pezzi, SUM($importo_riga) AS importo
            FROM righe r JOIN conti c ON c.id = r.conto_id
            LEFT JOIN articoli a ON a.id = r.articolo_id LEFT JOIN operatori o ON o.id = r.operatore_id
            WHERE $nel_periodo GROUP BY r.operatore_id, o.nome", $par) as $o) {
    $operatori[(int)$o['id']] = $o + ['conti' => 0];
}
foreach (q("SELECT c.operatore_id AS id, COALESCE(o.nome, '—') AS nome, COUNT(*) AS conti
            FROM conti c LEFT JOIN operatori o ON o.id = c.operatore_id
            WHERE $nel_periodo GROUP BY c.operatore_id, o.nome", $par) as $o) {
    $operatori[(int)$o['id']] = ($operatori[(int)$o['id']] ?? ['nome' => $o['nome'], 'pezzi' => 0, 'importo' => 0])
                                + ['conti' => 0];
    $operatori[(int)$o['id']]['conti'] = (int)$o['conti'];
}
usort($operatori, fn($x, $y) => $y['importo'] <=> $x['importo']);

// ---------- Formattazione ----------
$valuta = impostazione('valuta', '€');
$euro = fn($n) => $valuta . ' ' . number_format((float)$n, 2, ',', '.');
$intero = fn($n) => number_format((float)$n, 0, ',', '.');
$qta = fn($n, $peso) => $peso ? number_format((float)$n, 3, ',', '.') . ' kg' : $intero($n);
$compatto = function (float $n) use ($valuta): string {
    if ($n >= 1000) return $valuta . ' ' . rtrim(rtrim(number_format($n / 1000, 1, ',', ''), '0'), ',') . 'K';
    return $valuta . ' ' . number_format($n, 0, ',', '.');
};

// Barra orizzontale proporzionale (dentro le tabelle)
$barra = fn(float $v, float $max) => '<span class="st-barra"><i style="width:' . ($max > 0 ? round(100 * $v / $max, 1) : 0) . '%"></i></span>';

// Scala dell'asse: passo "pulito" (1, 2, 2,5, 5 × 10^n), circa 4 righe
function scala(float $max): array {
    if ($max <= 0) return [1.0, [0.0]];
    $grezzo = $max / 4;
    $p = 10 ** floor(log10($grezzo));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $p >= $grezzo) { $passo = $m * $p; break; }
    }
    $cima = ceil($max / $passo) * $passo;
    return [$cima, range(0, $cima, $passo)];
}

// Grafico a colonne (una serie). $punti = [[etichetta breve, valore, nome completo, conti], ...]
function colonne(array $punti, callable $euro, callable $compatto, string $vuoto): string {
    $valori = array_column($punti, 1);
    if (!$punti || max($valori) <= 0) return '<p class="st-vuoto">' . h($vuoto) . '</p>';
    [$cima, $tacche] = scala(max($valori));
    $i_max = array_search(max($valori), $valori);
    $ogni = (int)ceil(count($punti) / 16);   // etichette sotto: al massimo 16
    $out = '<div class="st-grafico"><div class="st-asse">';
    foreach ($tacche as $t) {
        $out .= '<span style="bottom:' . round(100 * $t / $cima, 2) . '%">' . h($compatto($t)) . '</span>';
    }
    $out .= '</div><div class="st-colonne">';
    foreach ($tacche as $t) {
        $out .= '<b class="st-griglia" style="bottom:' . round(100 * $t / $cima, 2) . '%"></b>';
    }
    foreach ($punti as $i => [$breve, $v, $nome, $conti]) {
        $tip = $nome . ': ' . $euro($v) . ($conti ? ' · ' . $conti . ($conti === 1 ? ' conto' : ' conti') : '');
        $out .= '<div class="st-col" tabindex="0" data-tip="' . h($tip) . '" aria-label="' . h($tip) . '">'
              . '<i style="height:' . round(100 * $v / $cima, 2) . '%">'
              . ($i === $i_max ? '<em>' . h($compatto($v)) . '</em>' : '') . '</i>'
              . '<span>' . ($i % $ogni === 0 ? h($breve) : '') . '</span></div>';
    }
    return $out . '</div></div>';
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= h(impostazione('nome_locale')) ?> - Statistiche</title>
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
    <span class="data">Statistiche · <?= h($periodo) ?></span>
    <span><?= date('H:i') ?></span>
  </div>

  <div class="sale-tabs">
    <?php foreach ($rapidi as $k => $nome): ?>
      <a class="sala-tab <?= $k === $scelta ? 'attiva' : '' ?>" href="statistiche.php?p=<?= $k ?>"><?= h($nome) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <div class="navcol">
      <a class="btn menu" href="sala.php"><span class="ico">🏠</span>Menu</a>
      <a class="btn" href="impostazioni.php"><span class="ico">⚙</span>Impostazioni</a>
    </div>

    <div class="st-pagina">

      <form class="st-date" method="get">
        <label>Dal <input type="date" name="dal" value="<?= $dal->format('Y-m-d') ?>"></label>
        <label>al <input type="date" name="al" value="<?= $al->format('Y-m-d') ?>"></label>
        <button type="submit">Mostra</button>
        <span class="st-nota">Giornata dalle <?= $apre ?> alle <?= $chiude ?>
          (<a href="generali.php">cambia</a>) · contano solo i conti chiusi</span>
      </form>

      <!-- Riepilogo -->
      <div class="st-tiles">
        <div class="st-tile hero"><span class="lbl">Incasso</span><span class="val"><?= $euro($tot['incasso']) ?></span></div>
        <div class="st-tile"><span class="lbl">Conti chiusi</span><span class="val"><?= $intero($tot['conti']) ?></span>
          <span class="sub"><?= $intero($tot['conti_tavolo']) ?> tavoli · <?= $intero($tot['conti_bar']) ?> bar/asporto</span></div>
        <div class="st-tile"><span class="lbl">Conto medio</span><span class="val"><?= $tot['conti'] ? $euro($tot['incasso'] / $tot['conti']) : '—' ?></span></div>
        <div class="st-tile"><span class="lbl">Coperti</span><span class="val"><?= $intero($tot['coperti']) ?></span>
          <span class="sub"><?= $tot['coperti'] ? $euro($tot['incasso_tavoli'] / $tot['coperti']) . ' a coperto (tavoli)' : '' ?></span></div>
      </div>

      <div class="st-righe">
        <section class="st-card">
          <h2>Tavoli e bar</h2>
          <table class="st-tab">
            <thead><tr><th></th><th class="num">Conti</th><th class="num">Incasso</th><th class="num">Conto medio</th></tr></thead>
            <tbody>
              <?php foreach ([['Tavoli', $tot['conti_tavolo'], $tot['incasso_tavoli']], ['Bar e asporto', $tot['conti_bar'], $tot['incasso_bar']]] as [$nome, $n, $inc]): ?>
                <tr><td><?= $nome ?></td><td class="num"><?= $intero($n) ?></td><td class="num"><?= $euro($inc) ?></td>
                    <td class="num"><?= $n ? $euro($inc / $n) : '—' ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>

        <section class="st-card">
          <h2>Pagamenti</h2>
          <?php if ($pagamenti): $max = max(array_column($pagamenti, 'importo')); ?>
            <table class="st-tab">
              <thead><tr><th></th><th class="num">Conti</th><th class="num">Importo</th><th class="num">%</th><th class="st-col-barra"></th></tr></thead>
              <tbody>
                <?php foreach ($pagamenti as $p): ?>
                  <tr><td><?= h($p['nome']) ?></td><td class="num"><?= $intero($p['conti']) ?></td><td class="num"><?= $euro($p['importo']) ?></td>
                      <td class="num"><?= $tot['incasso'] > 0 ? number_format(100 * $p['importo'] / $tot['incasso'], 0) . '%' : '' ?></td>
                      <td class="st-col-barra"><?= $barra((float)$p['importo'], (float)$max) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?><p class="st-vuoto">Nessun pagamento nel periodo.</p><?php endif; ?>
        </section>
      </div>

      <?php if ($giorni > 1): ?>
        <section class="st-card">
          <h2>Incasso <?= $per_mese ? 'mese per mese' : 'giorno per giorno' ?></h2>
          <?= colonne($tempo, $euro, $compatto, 'Nessun incasso nel periodo.') ?>
          <?php if ($tot['conti']): ?>
          <details class="st-dettagli"><summary>Tabella</summary>
            <table class="st-tab">
              <thead><tr><th><?= $per_mese ? 'Mese' : 'Giorno' ?></th><th class="num">Conti</th><th class="num">Incasso</th></tr></thead>
              <tbody><?php foreach ($tempo as [, $v, $nome, $n]): ?>
                <tr><td><?= h($nome) ?></td><td class="num"><?= $intero($n) ?></td><td class="num"><?= $euro($v) ?></td></tr>
              <?php endforeach; ?></tbody>
            </table>
          </details>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="st-card">
        <h2>Fasce orarie <small>importo degli articoli per ora in cui sono stati ordinati</small></h2>
        <?= colonne($ore, $euro, $compatto, 'Nessuna vendita nel periodo.') ?>
        <?php if ($ore): ?>
          <details class="st-dettagli"><summary>Tabella</summary>
            <table class="st-tab">
              <thead><tr><th>Ora</th><th class="num">Conti</th><th class="num">Importo</th></tr></thead>
              <tbody><?php foreach ($ore as [, $v, $nome, $n]): ?>
                <tr><td><?= h($nome) ?></td><td class="num"><?= $intero($n) ?></td><td class="num"><?= $euro($v) ?></td></tr>
              <?php endforeach; ?></tbody>
            </table>
          </details>
        <?php endif; ?>
      </section>

      <div class="st-righe">
        <section class="st-card">
          <h2>Categorie</h2>
          <?php if ($categorie): $max = max(array_column($categorie, 'importo')); ?>
            <table class="st-tab">
              <thead><tr><th></th><th class="num">Pezzi</th><th class="num">Importo</th><th class="num">%</th><th class="st-col-barra"></th></tr></thead>
              <tbody>
                <?php foreach ($categorie as $c): ?>
                  <tr><td><?= h($c['nome']) ?></td><td class="num"><?= $intero($c['pezzi']) ?></td><td class="num"><?= $euro($c['importo']) ?></td>
                      <td class="num"><?= $tot['incasso'] > 0 ? number_format(100 * $c['importo'] / $tot['incasso'], 0) . '%' : '' ?></td>
                      <td class="st-col-barra"><?= $barra((float)$c['importo'], (float)$max) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?><p class="st-vuoto">Nessuna vendita nel periodo.</p><?php endif; ?>
        </section>

        <section class="st-card">
          <h2>Operatori <small>articoli inseriti e conti aperti</small></h2>
          <?php if ($operatori): $max = max(array_column($operatori, 'importo')); ?>
            <table class="st-tab">
              <thead><tr><th></th><th class="num">Conti</th><th class="num">Articoli</th><th class="num">Importo</th><th class="st-col-barra"></th></tr></thead>
              <tbody>
                <?php foreach ($operatori as $o): ?>
                  <tr><td><?= h($o['nome']) ?></td><td class="num"><?= $intero($o['conti']) ?></td><td class="num"><?= $intero($o['pezzi']) ?></td>
                      <td class="num"><?= $euro($o['importo']) ?></td><td class="st-col-barra"><?= $barra((float)$o['importo'], (float)$max) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?><p class="st-vuoto">Nessuna vendita nel periodo.</p><?php endif; ?>
        </section>
      </div>

      <section class="st-card">
        <h2>Prodotti <small><?= count($prodotti) ?> venduti, dal più incassato</small></h2>
        <?php if ($prodotti): $max = (float)$prodotti[0]['importo']; ?>
          <table class="st-tab">
            <thead><tr><th>#</th><th>Prodotto</th><th>Categoria</th><th class="num">Quantità</th><th class="num">Importo</th><th class="st-col-barra"></th></tr></thead>
            <tbody>
              <?php foreach ($prodotti as $i => $p): ?>
                <tr<?= $i >= 20 ? ' class="st-altri" hidden' : '' ?>><td class="num"><?= $i + 1 ?></td><td><?= h($p['nome']) ?></td><td><?= h($p['categoria']) ?></td>
                    <td class="num"><?= $qta($p['qta'], $p['a_peso']) ?></td><td class="num"><?= $euro($p['importo']) ?></td>
                    <td class="st-col-barra"><?= $barra((float)$p['importo'], $max) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($prodotti) > 20): ?>
            <button type="button" class="st-tutti" onclick="document.querySelectorAll('.st-altri').forEach(r => r.hidden = false); this.remove()">
              Mostra tutti i <?= count($prodotti) ?> prodotti</button>
          <?php endif; ?>
        <?php else: ?><p class="st-vuoto">Nessuna vendita nel periodo.</p><?php endif; ?>
      </section>

    </div>
  </div>

</body>
</html>
