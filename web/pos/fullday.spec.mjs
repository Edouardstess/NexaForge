import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const browser = await chromium.launch({ args: ['--no-proxy-server'] });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errs = [];
page.on('pageerror', e => errs.push('PAGEERROR ' + e.message));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('CERT')) errs.push('CONSOLE ' + m.text()); });

await page.goto('http://127.0.0.1:5174/index.html', { waitUntil: 'load' });
await page.fill('#identifier', 'pwopriyete@nexa.test');   // propriétaire : tous les écrans
await page.fill('#password', 'demo1234');
await page.click('#signin');
await page.waitForSelector('#grid .item', { timeout: 20000 });
console.log('ekran ki vizib :', await page.$$eval('#nav button', b => b.map(x => x.textContent)));

/* ---- 1. ouvrir la caisse avec un fond compté ---- */
await page.click('#sessbtn');
await page.waitForSelector('.modal');
await page.fill('#float-HTG', '1 000,00');
await page.fill('#float-USD', '20.00');
await page.click('.confirm');
await page.waitForFunction(() => document.querySelector('#sessbtn').textContent.includes('Fèmen'), { timeout: 10000 });
console.log('1. KÈS LOUVRI  →', await page.textContent('#sessionmeta'));

/* ---- 2. deux ventes ---- */
const ring = async (n, times = 1) => { for (let i = 0; i < times; i++) await page.click(`#grid .item:has(.nm:text-is("${n}"))`); };
const pay = async () => {
  await page.click('#paybtn'); await page.waitForSelector('.modal');
  await page.click('.quick:text-is("Egzak")'); await page.click('#confirmsale');
  await page.waitForSelector('.toast', { timeout: 10000 });
  await page.waitForTimeout(400);
};
await ring('Kola Kouwòn', 4); await pay();
await ring('Diri Tchako', 2); await pay();
console.log('2. DE VANT     → fèt');

/* ---- 3. rembourser deux Kola ---- */
await page.click('#nav button:text-is("Vant")');
await page.waitForSelector('.orow');
const kolaOrder = await page.$$eval('.orow', rows => {
  const r = rows.find(x => x.textContent.includes('200,00'));   // 4 × 50,00
  return r ? r.querySelector('.nm').textContent : null;
});
await page.click(`.orow:has(.nm:text-is("${kolaOrder}"))`);
await page.waitForSelector('.modal');
const firstItem = await page.getAttribute('.qtyin', 'id');
await page.fill('#' + firstItem, '2');
await page.fill('#refundreason', 'Kliyan an retounen de boutèy');
// Attendre le toast DU REMBOURSEMENT, pas celui de la vente qui traîne.
await page.evaluate(() => document.querySelector('.toast')?.remove());
await page.click('.confirm');
await page.waitForSelector('.toast', { timeout: 10000 });
console.log('3. RANBOUSMAN  →', (await page.textContent('.toast')).trim());

/* ---- 4. le rapport du jour ---- */
await page.click('#nav button:text-is("Rapò")');
await page.waitForSelector('.tile');
const tiles = await page.$$eval('.tile', ts => ts.map(t => ({
  k: t.querySelector('.lbl').textContent, v: t.querySelector('.big').textContent,
  s: t.querySelector('.shopmeta')?.textContent,
})));
console.log('4. RAPÒ        →', JSON.stringify(tiles));
await page.screenshot({ path: 'day-report.png' });

/* ---- 5. les conflits ---- */
await page.click('#nav button:text-is("Konfli")');
await page.waitForSelector('.panel');
console.log('5. KONFLI      →', (await page.textContent('.vhead')).replace(/\s+/g, ' ').trim());

/* ---- 6. fermer la caisse, Z compris ---- */
await page.click('#nav button:text-is("Kès")');
await page.click('#sessbtn');
await page.waitForSelector('.modal');
const z = await page.$$eval('.zbox .kv', ks => ks.map(k => k.textContent));
console.log('6. Z           →', JSON.stringify(z));
await page.screenshot({ path: 'day-close.png' });
// Compter juste ce que le serveur attend : 1000 d'ouverture + les ventes − rendus.
// Tiroir attendu : 1 000 de fond + 950 encaissés − 100 remboursés = 1 850,00.
// Le fond en dollars n'a pas bougé : il doit être recompté tel quel.
await page.fill('#count-HTG', '1 850,00');   // 1000 fon + 950 vant − 100 ranbouse
await page.fill('#count-USD', '20.00');
await page.click('.confirm');
await page.waitForFunction(() => document.querySelector('#sessbtn')?.textContent.includes('Louvri'), { timeout: 10000 });
console.log('   fèmti       →', (await page.textContent('.toast')).trim());
console.log('   Z konplè    →', JSON.stringify(await (async () => {
  const r = await fetch('http://127.0.0.1:8000/api/v1/health'); return r.ok ? 'api vivan' : 'api mouri';
})()));

console.log('ERÈ JS :', errs.length ? errs : 'okenn');
await browser.close();
