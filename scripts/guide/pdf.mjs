// Prints the user guide (route guide.print) to a PDF with page numbers. Run through `php artisan guide:build`.
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { launch, login, BASE } from './browser.mjs';

const OUT = process.argv[2] ?? 'public/guide/user-guide.pdf';
mkdirSync(dirname(OUT), { recursive: true });

const { browser, page } = await launch();
await login(page);
await page.goto(`${BASE}/guide/print`, { waitUntil: 'networkidle0', timeout: 120000 });
await page.evaluateHandle('document.fonts.ready');
// every screenshot loaded before printing
await page.evaluate(() => Promise.all([...document.images].map((img) => (img.complete ? null : new Promise((r) => { img.onload = img.onerror = r; })))));

await page.pdf({
    path: OUT,
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: `<div style="width:100%;font:9px Tahoma,sans-serif;color:#9ca3af;text-align:center;direction:rtl">
        صفحة <span class="pageNumber"></span> من <span class="totalPages"></span> · © ${new Date().getFullYear()} CoreBugs</div>`,
});
await browser.close();
console.log(`✓ ${OUT}`);
