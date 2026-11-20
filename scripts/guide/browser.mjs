// Shared helpers for the user-guide screenshots and PDF (php artisan guide:build).
import puppeteer from 'puppeteer-core';

export const BASE = process.env.GUIDE_URL ?? 'http://127.0.0.1:8123';
const CHROME = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

export async function launch() {
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--lang=ar', '--force-color-profile=srgb'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1.5 });
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
    return { browser, page };
}

export async function login(page, email = 'admin@newpos.test', password = 'password') {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => localStorage.setItem('theme', 'light'));
    const inputs = await page.$$('form input:not([type=hidden]):not([type=checkbox])');
    await inputs[0].type(email);
    await inputs[1].type(password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('form button[type=submit]')]);
}

/** Wait until Livewire / Alpine settle. */
export async function settle(page, ms = 600) {
    await page.waitForNetworkIdle({ idleTime: 300, timeout: 15000 }).catch(() => {});
    await new Promise((r) => setTimeout(r, ms));
}

/** Click the first visible button / link whose text is (or starts with) `text`. */
export async function clickText(page, text) {
    const ok = await page.evaluate((text) => {
        const norm = (s) => (s ?? '').replace(/\s+/g, ' ').trim();
        const els = [...document.querySelectorAll('button, a, [role=button], label')].filter((el) => el.getClientRects().length > 0);
        const el = els.find((e) => norm(e.innerText) === text) ?? els.find((e) => norm(e.innerText).startsWith(text))
            ?? els.find((e) => (e.getAttribute('title') ?? e.getAttribute('aria-label') ?? '') === text);
        if (!el) return false;
        el.scrollIntoView({ block: 'center' });
        el.click();
        return true;
    }, text);
    if (!ok) throw new Error(`not found: ${text}`);
    await settle(page, 900);
}

/**
 * Marks elements before a screenshot: a red frame, plus a numbered badge when the callout has `n`
 * (the number of the step in the help text). A callout is a visible text (button, field label, column header…),
 * {text, n} or {css, n}. Returns the callouts that were not found.
 */
export async function callouts(page, list) {
    return page.evaluate((list) => {
        for (const old of [...document.querySelectorAll('.guide-callout')]) old.parentNode.removeChild(old);
        // "المبلغ*" (required field) matches "المبلغ"
        const norm = (s) => (s ?? '').replace(/\s+/g, ' ').replace(/\s*\*$/, '').trim();
        const visible = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
        const modals = [...document.querySelectorAll('.fi-modal-window')].filter(visible);
        const root = modals.length ? modals[modals.length - 1] : document.body;
        const all = [...root.querySelectorAll('button, a, label, th, h2, h3, legend, span, p, dt, div')].filter(visible);
        const find = (c) => {
            if (c.css) return [...root.querySelectorAll(c.css)].find(visible) ?? null;
            const text = c.text;
            const exact = all.filter((e) => norm(e.innerText) === text);
            return exact.find((e) => ![...e.children].some((ch) => norm(ch.innerText) === text))
                ?? [...root.querySelectorAll('input[placeholder]')].find((e) => e.getAttribute('placeholder') === text && visible(e))
                ?? all.find((e) => (e.getAttribute('title') ?? '') === text)
                ?? all.filter((e) => norm(e.innerText).startsWith(text) && norm(e.innerText).length < text.length + 25).find((e) => e.children.length === 0)
                ?? null;
        };
        const missing = [];
        for (const raw of list) {
            const c = typeof raw === 'string' ? { text: raw } : raw;
            const found = find(c);
            if (!found) {
                missing.push(c.text ?? c.css);
                continue;
            }
            // frame the whole field / button / column, not just its label
            const el = c.exact ? found : (found.closest('button, a, th, .fi-fo-field, .fi-fo-field-wrp, .fi-section-header, .fi-in-entry') ?? found);
            const r = el.getBoundingClientRect();
            const top = r.top + window.scrollY;
            const left = r.left + window.scrollX;
            const ring = document.createElement('div');
            ring.className = 'guide-callout';
            Object.assign(ring.style, {
                position: 'absolute', zIndex: 2147483646, top: top - 4 + 'px', left: left - 4 + 'px', width: r.width + 8 + 'px',
                height: r.height + 8 + 'px', border: '2.5px solid #e11d48', borderRadius: '9px', pointerEvents: 'none',
            });
            document.body.appendChild(ring);
            if (c.n) {
                const size = 26;
                const badge = document.createElement('div');
                badge.className = 'guide-callout';
                badge.textContent = String(c.n);
                Object.assign(badge.style, {
                    position: 'absolute', zIndex: 2147483647, width: size + 'px', height: size + 'px', borderRadius: '50%',
                    background: '#e11d48', color: '#fff', font: '700 14px/26px Cairo, sans-serif', textAlign: 'center',
                    boxShadow: '0 0 0 3px #fff, 0 2px 6px rgba(0,0,0,.35)', pointerEvents: 'none',
                    // on the outer corner of the frame (top-right in Arabic, where reading starts)
                    top: Math.max(2, top - size + 4) + 'px',
                    left: Math.min(document.documentElement.scrollWidth - size - 2, Math.max(2, left + r.width - 4)) + 'px',
                });
                document.body.appendChild(badge);
            }
        }
        return missing;
    }, list);
}

/** Before a 'main' shot (and before the callouts are placed): the sticky top bar would be painted over a long page. */
export async function hideTopbar(page) {
    await page.addStyleTag({ content: '.fi-topbar-ctn, .fi-topbar { display: none !important; }' });
    await new Promise((r) => setTimeout(r, 200));
}

/** Screenshot of the page content (without the sidebar), of the open modal, or of the viewport. */
export async function shoot(page, path, target = 'main') {
    const selector = target === 'modal' ? '.fi-modal-window' : target === 'main' ? '.fi-main' : null;
    if (selector) {
        const visible = [];
        for (const el of await page.$$(selector)) {
            if (await el.isVisible()) visible.push(el);
        }
        if (visible.length) {
            await visible[visible.length - 1].screenshot({ path, type: 'jpeg', quality: 82, captureBeyondViewport: true });
            return;
        }
    }
    await page.screenshot({ path, type: 'jpeg', quality: 82 });
}
