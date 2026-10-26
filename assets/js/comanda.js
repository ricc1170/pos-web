// ============================================================
//  comanda.js  -  Logica della schermata comanda
//  Ogni azione chiama api/conto.php, che risponde con lo stato
//  aggiornato del conto: la pagina si limita a ridisegnarlo.
// ============================================================

let stato = { conto: null, righe: [] };
let rigaSel = null;   // id della riga selezionata nel conto
let qta = '';         // quantità digitata sul tastierino

const $ = sel => document.querySelector(sel);

// Banco (comanda Bar non parcheggiata): operatore selezionato col tasto.
// Si ricorda su questo dispositivo, così ricaricando la pagina resta lo stesso.
const alBanco = POS.bar && !POS.conto;
const tastiOp = [...document.querySelectorAll('.banco-op')].map(a => +a.dataset.op);
const salvato = +localStorage.getItem('banco_operatore');
POS.banco = tastiOp.includes(salvato) ? salvato : POS.operatore;

function euro(n) {
  return POS.valuta + ' ' + n.toFixed(2).replace('.', ',');
}

function qtaTesto(n) {
  return Number.isInteger(n) ? String(n) : String(+n.toFixed(3)).replace('.', ',');
}

// ---------- Chiamate al server ----------
// Restituisce la risposta JSON (con .errore se qualcosa non va)
async function api(azione, dati = {}) {
  try {
    const res = await fetch('api/conto.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ azione, tavolo: POS.tavolo, conto: POS.conto, bar: alBanco, banco: POS.banco, ...dati })
    });
    const json = await res.json();
    if (res.status === 401) { location.href = 'index.php'; return { errore: 'Sessione scaduta' }; }
    if (json.errore) avviso(json.errore, true);
    if ('righe' in json) {
      stato = json;
      disegna();
    }
    return json;
  } catch (e) {
    avviso('Connessione al server non riuscita');
    return { errore: 'Connessione al server non riuscita' };
  }
}

// Messaggio in basso; i problemi (es. errori del server) in rosso e più a lungo
function avviso(testo, problema = false) {
  const el = $('#avviso');
  el.textContent = testo;
  el.classList.toggle('problema', problema);
  el.classList.add('visibile');
  clearTimeout(avviso.timer);
  avviso.timer = setTimeout(() => el.classList.remove('visibile'), problema ? 6000 : 3500);
}

// ---------- Disegno del conto ----------
function disegna() {
  const box = $('#righe');
  box.innerHTML = '';
  if (!stato.righe.some(r => r.id === rigaSel)) rigaSel = null;

  let invioPrec = null;
  for (const r of stato.righe) {
    // Separatore a ogni nuova comanda inviata
    if (r.invio !== invioPrec) {
      const sep = document.createElement('div');
      sep.className = 'sep';
      sep.textContent = r.invio > 0 ? 'Comanda ' + r.invio + ' - inviata' : 'Da inviare';
      box.appendChild(sep);
      invioPrec = r.invio;
    }

    const el = document.createElement('div');
    el.className = 'riga' + (r.invio > 0 ? ' inviata' : '') + (r.id === rigaSel ? ' sel' : '');
    el.innerHTML = '<span class="q"></span><span class="d"></span><span class="p"></span>';
    el.querySelector('.q').textContent = r.a_peso ? 'kg' : qtaTesto(r.quantita);
    el.querySelector('.d').textContent = r.descrizione;
    if (r.a_peso) {
      // Peso e prezzo al kg sotto il prodotto
      const d = document.createElement('div');
      d.className = 'variante';
      d.textContent = r.quantita.toFixed(3).replace('.', ',') + ' kg × ' + euro(r.prezzo_unitario) + '/kg';
      el.querySelector('.d').appendChild(d);
    }
    el.querySelector('.p').textContent = euro(r.quantita * r.prezzo_unitario);
    // Varianti sotto il prodotto: "+ Bufala 2,00" in verde, "− Basilico" in rosso
    for (const v of r.varianti || []) {
      const d = document.createElement('div');
      d.className = 'variante ' + (v.segno === '+' ? 'piu' : 'meno');
      d.textContent = (v.segno === '+' ? '+ ' : '− ') + v.nome + (v.prezzo ? ' ' + euro(v.prezzo) : '');
      el.querySelector('.d').appendChild(d);
    }
    if (r.note) {
      const n = document.createElement('div');
      n.className = 'nota';
      n.textContent = '» ' + r.note;
      el.querySelector('.d').appendChild(n);
    }
    el.addEventListener('click', () => {
      rigaSel = r.id;
      // Con i pulsanti varianti aperti, passano a questa riga (può essere di un'altra categoria)
      if (!$('#grigliaVarianti').hidden) apriGrigliaVarianti(); else disegna();
    });
    box.appendChild(el);
  }

  const c = stato.conto;
  $('#totale').textContent = euro(c ? c.totale : 0);
  if ($('#contaProdotti')) {
    $('#contaProdotti').textContent = qtaTesto(stato.righe.reduce((s, r) => s + (r.a_peso ? 1 : r.quantita), 0));
  }
  if ($('#copertiLbl')) $('#copertiLbl').textContent = 'Coperti ' + (c ? c.coperti : 0);
  if ($('#testaBar')) {
    // Comanda Bar: parcheggiata -> orario e nome; al banco -> di chi è la comanda
    const t = $('#testaBar');
    if (c && c.orario) {
      t.innerHTML = 'Ritiro <b></b> ';
      t.querySelector('b').textContent = c.orario;
      t.append(c.nome || '');
    } else if (alBanco) {
      const o = (stato.banco || []).find(o => o.id === POS.banco);
      t.innerHTML = 'Comanda di <b></b>';
      t.querySelector('b').textContent = o ? o.nome : '';
    }
  }

  if ($('#richiamaTesto') && 'parcheggiate' in stato) {
    $('#richiamaTesto').textContent = 'Richiama' + (stato.parcheggiate ? ' (' + stato.parcheggiate + ')' : '');
  }

  // Tasti operatore: selezionato + numero di articoli in corso
  for (const o of stato.banco || []) {
    const a = document.querySelector('.banco-op[data-op="' + o.id + '"]');
    if (!a) continue;
    a.classList.toggle('attivo', alBanco && o.id === POS.banco);
    a.classList.toggle('occupato', o.pezzi > 0);
    a.querySelector('.pezzi').textContent = o.pezzi > 0 ? qtaTesto(o.pezzi) : '';
  }
  $('#qtaDisplay').textContent = qta ? 'x ' + qta : '';

  // Pulsanti riga attivi solo se c'è una riga modificabile selezionata
  const r = stato.righe.find(r => r.id === rigaSel);
  const modificabile = r && (r.invio === 0 || POS.puoStornare);
  document.querySelectorAll('.riga-azioni .btn').forEach(b => b.disabled = !modificabile);

  // Riga destinazione delle varianti evidenziata; pulsanti varianti aggiornati
  const dest = !$('#grigliaVarianti').hidden && rigaVariabile();
  document.querySelectorAll('.riga').forEach((el, i) => el.classList.toggle('dest-varianti', !!dest && stato.righe[i] && stato.righe[i].id === dest.id));
  if (dest) disegnaGriglia();
}

// ---------- Categorie e prodotti ----------
let ultimaRiga = null;   // riga dell'ultimo prodotto inserito (destinazione delle varianti)

function mostraCategoria(id) {
  chiudiGrigliaVarianti();
  document.querySelectorAll('.cat').forEach(b => b.classList.toggle('attiva', b.dataset.cat === id));
  let visibili = 0;
  document.querySelectorAll('.prod').forEach(p => {
    const si = p.dataset.cat === id;
    p.hidden = !si;
    if (si) visibili++;
  });
  $('.prodotti .vuoto').hidden = visibili > 0;
}

document.querySelectorAll('.cat[data-cat]').forEach(b =>
  b.addEventListener('click', () => mostraCategoria(b.dataset.cat)));

document.querySelectorAll('.prod').forEach(p =>
  p.addEventListener('click', () => {
    // Prodotto a peso: qui non c'è la bilancia (è sul PC della cassa), il peso si scrive a mano
    if (p.dataset.peso === '1') {
      const t = prompt('Peso in kg (es. 0,350):', '');
      if (t === null) return;
      const peso = parseFloat(t.replace(',', '.'));
      if (!(peso > 0)) { avviso('Peso non valido', true); return; }
      api('aggiungi', { articolo: +p.dataset.id, peso }).then(j => {
        if (j.riga) { ultimaRiga = j.riga; rigaSel = null; disegna(); }
      });
      return;
    }
    const n = parseInt(qta, 10) || 1;
    // Quantità usata: il tastierino si richiude
    if (qta) apriTastierino(false);
    qta = '';
    api('aggiungi', { articolo: +p.dataset.id, qta: n }).then(j => {
      if (j.riga) { ultimaRiga = j.riga; rigaSel = null; disegna(); }
    });
  }));

// ---------- Varianti dalla colonna categorie ----------
// Vanno sulla riga selezionata o, se nessuna, sull'ultimo prodotto inserito.
let senza = false;          // "− SENZA" premuto prima della variante
let ultimaVariante = null;  // { riga, id }: "−" subito dopo la trasforma in "senza"

function rigaVariabile() {
  const id = rigaSel || ultimaRiga;
  let r = stato.righe.find(r => r.id === id);
  // Dopo un ricaricamento: l'ultima riga ancora da inviare
  if (!r) r = [...stato.righe].reverse().find(r => r.invio === 0);
  return r || null;
}

function apriGrigliaVarianti() {
  const r = rigaVariabile();
  if (!r) { avviso('Inserisci prima un prodotto', true); return; }
  const elenco = POS.varianti.filter(v => v.tutte || v.categorie.includes(r.categoria_id));
  if (!elenco.length) { avviso('Nessuna variante per ' + r.descrizione + ' (Impostazioni > Varianti)', true); return; }

  document.querySelectorAll('.cat').forEach(b => b.classList.remove('attiva'));
  $('#catVarianti').classList.add('attiva');
  $('.prodotti:not(.griglia-varianti)').hidden = true;
  $('#grigliaVarianti').hidden = false;
  $('#variantiPer').textContent = r.descrizione;
  senza = false;
  ultimaVariante = null;

  const box = $('#variantiPulsanti');
  box.innerHTML = '';
  for (const v of elenco) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'var-pulsante';
    b.dataset.id = v.id;
    b.innerHTML = '<span class="nome"></span><span class="prezzo"></span>';
    b.querySelector('.nome').textContent = v.nome;
    b.querySelector('.prezzo').textContent = v.prezzo ? '+' + euro(v.prezzo) : '';
    b.addEventListener('click', () => tocca(v));
    box.appendChild(b);
  }
  disegnaGriglia();
}

function chiudiGrigliaVarianti() {
  if ($('#grigliaVarianti').hidden) return;
  $('#grigliaVarianti').hidden = true;
  $('.prodotti:not(.griglia-varianti)').hidden = false;
  $('#catVarianti').classList.remove('attiva');
}

// Colora i pulsanti con le varianti già presenti sulla riga
function disegnaGriglia() {
  const r = rigaVariabile();
  $('#btnSenza').classList.toggle('attivo', senza);
  if (!r) return;
  $('#variantiPer').textContent = r.descrizione;
  document.querySelectorAll('.var-pulsante').forEach(b => {
    const v = r.varianti.find(v => v.id === +b.dataset.id);
    b.classList.toggle('scelta-piu', !!v && v.segno === '+');
    b.classList.toggle('scelta-meno', !!v && v.segno === '-');
  });
}

async function tocca(v) {
  const r = rigaVariabile();
  if (!r) return;
  const segno = senza ? '-' : '+';
  senza = false;
  await api('variante', { riga: r.id, variante: v.id, segno });
  ultimaVariante = segno === '+' ? { riga: r.id, id: v.id } : null;
  disegnaGriglia();
}

$('#catVarianti').addEventListener('click', apriGrigliaVarianti);
$('#btnSenza').addEventListener('click', async () => {
  // Subito dopo una variante: la trasformo in "senza"; altrimenti la prossima variante sarà "senza"
  if (ultimaVariante) {
    const u = ultimaVariante;
    ultimaVariante = null;
    await api('variante', { riga: u.riga, variante: u.id, segno: '-' });
  } else {
    senza = !senza;
  }
  disegnaGriglia();
});

// ---------- Tastierino a scomparsa ----------
function apriTastierino(apri) {
  $('#tastierino').classList.toggle('chiuso', !apri);
  $('#btnTastierino .testo').textContent = apri ? 'Chiudi tastierino' : 'Tastierino';
}

$('#btnTastierino').addEventListener('click', () =>
  apriTastierino($('#tastierino').classList.contains('chiuso')));

// ---------- Tastierino ----------
document.querySelectorAll('[data-num]').forEach(k =>
  k.addEventListener('click', () => {
    const v = k.dataset.num;
    if (v === 'C') qta = '';
    else if (qta.length < 2) qta = (qta + v).replace(/^0+/, '');
    disegna();
  }));

// ---------- Pulsanti azione ----------
const azioni = {
  piu:     () => api('quantita', { riga: rigaSel, delta: 1 }),
  meno:    () => api('quantita', { riga: rigaSel, delta: -1 }),
  elimina: () => {
    const r = stato.righe.find(r => r.id === rigaSel);
    if (r.invio > 0 && !confirm('La riga è già stata inviata in cucina. Eliminarla?')) return;
    api('elimina', { riga: rigaSel });
  },
  varianti: () => apriVarianti(stato.righe.find(r => r.id === rigaSel)),
  nota: () => {
    const r = stato.righe.find(r => r.id === rigaSel);
    const t = prompt('Nota per ' + r.descrizione + ':', r.note || '');
    if (t !== null) api('nota', { riga: rigaSel, testo: t });
  },
  coperti: () => {
    // Numero digitato sul tastierino, altrimenti lo chiedo
    let n = qta;
    if (!n) {
      n = prompt('Numero di coperti:', stato.conto ? stato.conto.coperti : '');
      if (n === null) return;
    }
    if (qta) apriTastierino(false);
    qta = '';
    api('coperti', { n: parseInt(n, 10) || 0 });
  },
  invia: () => {
    if (!stato.righe.some(r => r.invio === 0)) { avviso('Niente da inviare'); return; }
    // Le comande le stampa il programma della cassa: qui si segnano solo come inviate
    api('invia').then(j => { if (!j.errore) avviso('Comanda inviata'); });
  },
  parcheggio: () => {
    if (!stato.righe.length) { avviso('Aggiungi almeno un prodotto'); return; }
    apriParcheggio();
  }
};

document.querySelectorAll('[data-az]').forEach(b =>
  b.addEventListener('click', () => azioni[b.dataset.az]()));

// ---------- Documenti: fattura (proforma e scontrino si fanno dal programma della cassa) ----------

// Righe non ancora inviate in cucina: prima di chiudere il conto le mando (lo chiedo)
async function inviaPrimaDiChiudere() {
  const n = stato.righe.filter(r => r.invio === 0).length;
  if (n && confirm(n + ' righe non ancora inviate in cucina. Inviarle adesso?')) await api('invia');
}

azioni.fattura = async () => {
  if (!stato.righe.length) { avviso('Il conto è vuoto', true); return; }
  await inviaPrimaDiChiudere();
  location.href = 'fattura.php?conto=' + stato.conto.id;
};

// ---------- Finestra Varianti ----------
// scelte: id variante -> '+' o '-' (assente = nessuna modifica)
let scelte = {};
let rigaVarianti = null;

function apriVarianti(r) {
  const elenco = POS.varianti.filter(v => v.tutte || v.categorie.includes(r.categoria_id));
  if (!elenco.length) { avviso('Nessuna variante per questo prodotto (Impostazioni > Varianti)', true); return; }
  rigaVarianti = r;
  scelte = {};
  for (const v of r.varianti) scelte[v.id] = v.segno;
  $('#variantiTitolo').textContent = 'Varianti: ' + r.descrizione;

  const box = $('#variantiElenco');
  box.innerHTML = '';
  for (const v of elenco) {
    const riga = document.createElement('div');
    riga.className = 'var-riga';
    riga.dataset.id = v.id;
    riga.innerHTML = '<span class="var-nome"></span><span class="var-prezzo"></span>'
                   + '<button type="button" class="var-btn meno">−</button><button type="button" class="var-btn piu">+</button>';
    riga.querySelector('.var-nome').textContent = v.nome;
    riga.querySelector('.var-prezzo').textContent = v.prezzo ? '+' + euro(v.prezzo) : '';
    // Secondo tocco sullo stesso segno = annulla la scelta
    riga.querySelector('.meno').addEventListener('click', () => { scelte[v.id] = scelte[v.id] === '-' ? undefined : '-'; disegnaVarianti(); });
    riga.querySelector('.piu').addEventListener('click', () => { scelte[v.id] = scelte[v.id] === '+' ? undefined : '+'; disegnaVarianti(); });
    box.appendChild(riga);
  }
  disegnaVarianti();
  $('#varianti').hidden = false;
}

function disegnaVarianti() {
  const piu = [], meno = [];
  let extra = 0;
  document.querySelectorAll('.var-riga').forEach(riga => {
    const v = POS.varianti.find(v => v.id === +riga.dataset.id);
    const s = scelte[v.id];
    riga.classList.toggle('scelta-piu', s === '+');
    riga.classList.toggle('scelta-meno', s === '-');
    if (s === '+') { piu.push(v.nome); extra += v.prezzo; }
    if (s === '-') meno.push(v.nome);
  });
  $('#variantiRiepilogo').textContent =
    (piu.length ? '+ ' + piu.join(', ') : '') + (piu.length && meno.length ? '   ' : '') +
    (meno.length ? '− ' + meno.join(', ') : '') +
    (extra ? '   (+' + euro(extra) + ' a pezzo)' : '') || 'Nessuna variante scelta';
}

$('#variantiChiudi').addEventListener('click', () => $('#varianti').hidden = true);
$('#variantiFatto').addEventListener('click', async () => {
  const lista = Object.entries(scelte).filter(([, s]) => s).map(([id, segno]) => ({ id: +id, segno }));
  const j = await api('varianti', { riga: rigaVarianti.id, scelte: lista });
  if (!j.errore) $('#varianti').hidden = true;
});

// ---------- Finestra Parcheggio (solo comande Bar) ----------
function scegliOra(ora) {
  $('#orario').value = ora;
  document.querySelectorAll('[data-ora]').forEach(x => x.classList.toggle('attiva', x.dataset.ora === ora));
}

function apriParcheggio() {
  const c = stato.conto;
  scegliOra(c.orario || '');
  $('#nome').value = c.nome || '';
  $('#noteConto').value = c.note || '';
  $('#parcheggio').hidden = false;
}

if (POS.bar) {
  document.querySelectorAll('[data-ora]').forEach(b =>
    b.addEventListener('click', () => scegliOra(b.dataset.ora)));

  $('#chiudiParcheggio').addEventListener('click', () => $('#parcheggio').hidden = true);

  // "Parcheggia e invia" o "Parcheggia senza inviare": lo dice il pulsante premuto
  $('#formParcheggio').addEventListener('submit', async e => {
    e.preventDefault();
    const invia = !e.submitter || e.submitter.value === '1';
    const j = await api('parcheggia', {
      orario: $('#orario').value,
      nome:   $('#nome').value,
      note:   $('#noteConto').value,
      invia
    });
    if (!j.errore) dopoChiusura(invia ? 'Comanda parcheggiata e inviata' : 'Comanda parcheggiata senza inviare');
  });

  $('#annullaComanda').addEventListener('click', async () => {
    if (!confirm('Eliminare questa comanda e tutti i suoi articoli?')) return;
    const j = await api('annulla');
    if (!j.errore) dopoChiusura('Comanda eliminata');
  });
}

// Comanda parcheggiata o eliminata: al banco si resta, con una comanda vuota per lo stesso
// operatore (la risposta del server è già quella nuova); da una comanda richiamata si torna al banco
function dopoChiusura(messaggio) {
  if (!alBanco) { location.href = 'comanda.php?bar=1'; return; }
  $('#parcheggio').hidden = true;
  rigaSel = null;
  avviso(messaggio);
}

// ---------- Cambio operatore al banco ----------
document.querySelectorAll('.banco-op').forEach(a =>
  a.addEventListener('click', e => {
    localStorage.setItem('banco_operatore', a.dataset.op);
    if (!alBanco) { esci(e); return; }   // da una comanda richiamata: torno al banco
    e.preventDefault();
    POS.banco = +a.dataset.op;
    rigaSel = null;
    qta = '';
    api('stato');
  }));

// Uscita dalla comanda (pulsante Sala o Richiama)
async function esci(e) {
  const link = e.currentTarget;

  // Al banco la comanda in corso resta sotto il tasto dell'operatore: si esce senza domande
  if (alBanco) return;

  // Comanda Bar con articoli ma non ancora parcheggiata: propongo il parcheggio
  if (POS.bar && stato.righe.length && !stato.conto.orario) {
    if (confirm('Comanda non parcheggiata. Parcheggiarla ora?')) {
      e.preventDefault();
      apriParcheggio();
    }
    return;
  }

  // Uscendo con righe non inviate, chiedo se mandarle in cucina
  const daInviare = stato.righe.filter(r => r.invio === 0).length;
  if (daInviare && confirm(daInviare + ' righe non inviate. Inviarle in cucina ora?')) {
    e.preventDefault();
    await api('invia');
    location.href = link.href;
  }
}

$('#btnSala').addEventListener('click', esci);
if ($('#btnRichiama')) $('#btnRichiama').addEventListener('click', esci);

// ---------- Coperti all'apertura di un tavolo libero ----------
async function scegliCoperti(n) {
  $('#finestraCoperti').hidden = true;
  if (n > 0) await api('coperti', { n });
}

if ($('#finestraCoperti')) {
  document.querySelectorAll('[data-coperti]').forEach(b =>
    b.addEventListener('click', () => scegliCoperti(+b.dataset.coperti)));
  $('#copertiNessuno').addEventListener('click', () => scegliCoperti(0));
  $('#copertiAltro').addEventListener('click', () => {
    const n = parseInt(prompt('Numero di coperti:', '13'), 10);
    if (n > 0) scegliCoperti(Math.min(n, 99));
  });
}

// ---------- Avvio ----------
const primaCat = document.querySelector('.cat');
if (primaCat) mostraCategoria(primaCat.dataset.cat);
api('stato').then(() => {
  // Tavolo libero (nessun conto aperto): la prima cosa è scegliere i coperti
  if ($('#finestraCoperti') && !stato.conto) $('#finestraCoperti').hidden = false;
});
