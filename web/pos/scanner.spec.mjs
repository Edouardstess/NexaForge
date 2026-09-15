import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
const b = await chromium.launch({ args: ['--no-proxy-server'] });
const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
const errs = [];
p.on('pageerror', e => errs.push('PAGEERROR ' + e.message));
p.on('console', m => { if (m.type()==='error' && !m.text().includes('CERT')) errs.push('CONSOLE ' + m.text()); });

await p.goto('http://127.0.0.1:5174/index.html', { waitUntil: 'load' });
await p.fill('#identifier','kesye@nexa.test'); await p.fill('#password','demo1234'); await p.click('#signin');
await p.waitForSelector('#grid .item', { timeout: 20000 });
await p.click('#sessbtn'); await p.waitForSelector('.modal');
await p.fill('#float-HTG','500,00'); await p.click('.confirm');
await p.waitForFunction(() => document.querySelector('#sessbtn').textContent.includes('Fèmen'));

// Une douchette tape vite puis envoie Entrée.
const scan = async (code) => {
  for (const ch of code) await p.keyboard.press(ch, { delay: 8 });
  await p.keyboard.press('Enter');
  await p.waitForTimeout(120);
};
await scan('7501234567890');           // Kola
await scan('7501234567890');
await scan('7509876543210');           // Diri
console.log('1. DOUCHÈT  → liy nan tikè:', await p.$$eval('#lines .line', l => l.length),
            '· total:', await p.textContent('#tot'));

// Un code inconnu doit le dire, pas ajouter n'importe quoi.
await scan('0000000000000');
await p.waitForSelector('.toast.bad', { timeout: 5000 });
console.log('2. KÒD ENKONI →', (await p.textContent('.toast.bad')).trim());
await p.evaluate(() => document.querySelector('.toast')?.remove());

// Une frappe humaine (lente) ne doit PAS être prise pour un scan.
await p.click('#q');
for (const ch of 'kola') await p.keyboard.press(ch, { delay: 150 });
console.log('3. MOUN K AP TAPE → rechèch:', await p.inputValue('#q'),
            '· liy toujou:', await p.$$eval('#lines .line', l => l.length));
await p.fill('#q','');

// Encaisser, puis imprimer depuis le toast.
await p.click('#paybtn'); await p.waitForSelector('.modal');
await p.click('.quick:text-is("Egzak")'); await p.click('#confirmsale');
await p.waitForSelector('.toast .tprint', { timeout: 10000 });
await p.click('.toast .tprint');
await p.waitForSelector('.receipt', { timeout: 10000 });
const receipt = await p.textContent('.receipt');
console.log('4. RESI:\n' + receipt.split('\n').map(l => '   |' + l).join('\n'));
await p.screenshot({ path: 'receipt.png' });
console.log('ERÈ JS:', errs.length ? errs : 'okenn');
await b.close();
