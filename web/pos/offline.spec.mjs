import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const browser = await chromium.launch({ args: ['--no-proxy-server'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 880 } });

const watch = (p, tag) => p.on('request', r => {
  if (r.url().endsWith('/orders') && r.method() === 'POST')
    console.log('      [' + tag + '] POST origin=' + (JSON.parse(r.postData() ?? '{}').origin));
});

const login = async (p) => {
  await p.goto('http://127.0.0.1:5174/index.html', { waitUntil: 'load' });
  if (await p.locator('#identifier').count()) {
    await p.fill('#identifier', 'kesye@nexa.test');
    await p.fill('#password', 'demo1234');
    await p.click('#signin');
  }
  await p.waitForSelector('#grid .item', { timeout: 20000 });
};
const ring = async (p, name, n = 1) => {
  for (let i = 0; i < n; i++) await p.click(`#grid .item:has(.nm:text-is("${name}"))`);
};
const payExact = async (p) => {
  await p.click('#paybtn');
  await p.waitForSelector('.modal');
  await p.click('.quick:text-is("Egzak")');
  await p.click('#confirmsale');
};
// Retient la requête POUR DE BON : une fonction async vide se résout tout de
// suite et Playwright laisse alors passer la requête — le piège du premier
// essai, qui faisait croire à une coupure alors que le serveur recevait tout.
const hold = () => new Promise(() => {});

let page = await ctx.newPage(); watch(page, 'p1');

/* === 1. coupure réseau pendant l'envoi ============================== */
await login(page);
await ring(page, 'Kola Kouwòn', 2);
await page.route('**/api/v1/orders', r => r.abort('internetdisconnected'));
await payExact(page);
await page.waitForSelector('.queue:not([hidden])', { timeout: 10000 });
console.log('1. KOUPI REZO  → vant nan fil la');

await page.unroute('**/api/v1/orders');
await page.evaluate(() => window.dispatchEvent(new Event('online')));
await page.waitForFunction(() => document.querySelector('#queue').hidden, { timeout: 15000 });
console.log('   rekoneksyon → fil vid, vant lan pati');

/* === 2a. la vente est sur le disque AVANT toute tentative réseau =====
 *
 * Ce qu'on prouve ici : au moment où le réseau est sollicité, la vente est
 * déjà persistée, et un redémarrage la renvoie.
 *
 * Ce qu'on NE prouve PAS : que le serveur ne l'a jamais reçue. Playwright
 * relâche une route retenue quand la page se ferme, donc la requête part
 * quand même — une vraie coupure de courant, elle, ne partirait pas. Le
 * chaînon manquant est une propriété de la panne, pas du code.
 */
await ring(page, 'Diri Tchako', 1);
await page.route('**/api/v1/orders', hold);          // le serveur ne reçoit RIEN
await payExact(page);
await page.waitForTimeout(600);
console.log('2a. SOU DISK ANVAN REZO → nan fil:',
  await page.evaluate(() => JSON.parse(localStorage.getItem('nexa.queue') ?? '[]').length));
await page.close();                                   // la machine s'éteint

page = await ctx.newPage(); watch(page, 'p2');
await login(page);
await page.waitForFunction(() => document.querySelector('#queue').hidden, { timeout: 20000 });
console.log('    apre demaraj → fil vid');

/* === 2b. la caisse réessaie une vente que le serveur a DÉJÀ ==========
 *
 * Le cas que 2a finit par produire aussi : le serveur a enregistré, la caisse
 * n'a pas vu la réponse, elle renvoie au redémarrage. La clé d'idempotence
 * doit lui rendre la vente existante, jamais en créer une deuxième.  [D-11]
 */
// La vente est passée, mais la caisse n'a jamais vu la réponse. Au
// redémarrage elle réessaie : la clé d'idempotence doit lui rendre la vente
// existante, pas en créer une deuxième.
await ring(page, 'Prestij', 3);
await page.route('**/api/v1/orders', async (route) => {
  // fetch() envoie la requête au serveur SANS la rendre à la page ; sans
  // fulfill(), la réponse n'arrive jamais. route.continue() aurait laissé la
  // réponse passer, ce qui n'aurait rien simulé du tout.
  await route.fetch();
  await hold();
});
await payExact(page);
await page.waitForTimeout(1500);
console.log('2b. REESEYE YON VANT SÈVÈ A GEN DEJA → nan fil:',
  await page.evaluate(() => JSON.parse(localStorage.getItem('nexa.queue') ?? '[]').length));
await page.close();

page = await ctx.newPage(); watch(page, 'p3');
await login(page);
await page.waitForFunction(() => document.querySelector('#queue').hidden, { timeout: 20000 });
console.log('    apre demaraj → fil vid');

await page.screenshot({ path: 'powercut.png' });
await browser.close();
