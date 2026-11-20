// Smoke test of a running install: signs in, opens every admin / POS page (and the first record of each list),
// and reports pages that fail. Usage: GUIDE_URL=https://site node scripts/guide/smoke.mjs <email> <password>
import { readFileSync } from 'node:fs';
import { launch, login, settle, BASE } from './browser.mjs';

const [email, password] = process.argv.slice(2);
const urls = JSON.parse(readFileSync(new URL('./smoke-urls.json', import.meta.url)));
const { browser, page } = await launch();
const errors = [];
page.on('pageerror', (e) => errors.push(`js: ${e.message.split('\n')[0]}`));

await login(page, email, password);
let failed = 0;
const visit = async (url) => {
    errors.length = 0;
    const response = await page.goto(BASE + url, { waitUntil: 'networkidle2', timeout: 90000 }).catch((e) => ({ status: () => `timeout ${e.message.slice(0, 40)}` }));
    await settle(page, 300);
    const status = response?.status?.() ?? '?';
    const body = await page.evaluate(() => document.body?.innerText.slice(0, 4000) ?? '');
    const bad = status !== 200 || /Server Error|Internal Server Error|خطأ في الخادم|Whoops|Exception/.test(body);
    if (bad || errors.length) {
        failed += bad ? 1 : 0;
        console.log(`${bad ? '✗' : '~'} ${url} [${status}] ${errors.join(' | ')} ${bad ? body.replace(/\s+/g, ' ').slice(0, 160) : ''}`);
    }
    return bad;
};

for (const url of urls) {
    const bad = await visit(url);
    // the first record of a list: its view page
    if (!bad && url.startsWith('/admin/') && !url.endsWith('/create')) {
        const first = await page.evaluate(() => [...document.querySelectorAll('.fi-ta-row a[href]')].map((a) => a.getAttribute('href'))
            .find((h) => /\/\d+(\/(edit|view))?$/.test(h ?? '')));
        if (first) await visit(first.replace(/^https?:\/\/[^/]+/, ''));
    }
}
console.log(failed ? `${failed} page(s) failed` : `all ${urls.length} pages OK`);
await browser.close();
process.exit(failed ? 1 : 0);
