// ============================================================
//  sw.js  -  Service worker minimo: serve solo a rendere il POS
//  installabile come app a tutto schermo. Non salva niente in cache:
//  ogni richiesta va al server come sempre (dati sempre aggiornati).
// ============================================================
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
