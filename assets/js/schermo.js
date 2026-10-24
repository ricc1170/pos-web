// ============================================================
//  schermo.js  -  incluso in tutte le pagine
//  Registra il service worker, che serve solo a rendere il POS installabile
//  come app sui tablet ("Aggiungi a schermata Home": si apre a tutto schermo).
//  Il passaggio automatico a tutto schermo al primo tocco è stato tolto su richiesta.
// ============================================================
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}
