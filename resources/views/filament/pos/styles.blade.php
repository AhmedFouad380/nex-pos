{{-- Cashier screen. Self-contained styles; colours follow the panel palette (--primary-*), category colours come from --cat. --}}
<style>
    [x-cloak] { display: none !important; }

    .pos { --pos-bg: #fff; --pos-soft: #f4f5f7; --pos-softer: #fafafb; --pos-border: #e5e7eb; --pos-text: #111827; --pos-muted: #6b7280;
        --pos-ok: #059669; --pos-bad: #e11d48; --pos-warn: #d97706; --pos-radius: 1rem;
        display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(380px, 1fr); gap: 1rem; height: calc(100vh - 7rem); color: var(--pos-text); }
    .dark .pos { --pos-bg: #111827; --pos-soft: #1f2937; --pos-softer: #172033; --pos-border: #374151; --pos-text: #f9fafb; --pos-muted: #9ca3af; }
    .pos-copyright { position: fixed; left: .5rem; bottom: .25rem; z-index: 5; font-size: .65rem; color: #9ca3af; opacity: .8; pointer-events: none; }
    @media (max-width: 1100px) { .pos { grid-template-columns: 1fr; height: auto; } }
    .pos svg { width: 1.15rem; height: 1.15rem; flex: none; }
    .pos section { background: var(--pos-bg); border: 1px solid var(--pos-border); border-radius: var(--pos-radius); padding: 0.9rem;
        display: flex; flex-direction: column; min-height: 0; box-shadow: 0 1px 2px rgb(0 0 0 / 0.04), 0 8px 24px -12px rgb(0 0 0 / 0.12); }

    /* ---------- inputs & buttons ---------- */
    .pos-input { width: 100%; border: 1px solid var(--pos-border); border-radius: 0.6rem; padding: 0.5rem 0.65rem; background: var(--pos-bg); color: var(--pos-text); font: inherit; }
    .pos-input:focus { outline: 2px solid var(--primary-500); outline-offset: -1px; }
    .pos-input:disabled { background: var(--pos-soft); }
    .pos-muted { color: var(--pos-muted); font-size: 0.85rem; }
    .pos-error, .pos-warn { color: var(--pos-bad); }
    .pos kbd, .pos-kbd { font: 600 0.7rem/1 ui-monospace, monospace; padding: 0.2rem 0.35rem; border-radius: 0.35rem; border: 1px solid currentColor; opacity: 0.7; }
    .pos-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; border: 1px solid var(--pos-border); background: var(--pos-soft);
        color: var(--pos-text); border-radius: 0.6rem; padding: 0.5rem 0.8rem; font: inherit; cursor: pointer; white-space: nowrap; transition: all .15s; }
    .pos-btn:hover:not(:disabled) { border-color: var(--primary-400); color: var(--primary-700); }
    .dark .pos-btn:hover:not(:disabled) { color: var(--primary-300); }
    .pos-btn:disabled, .pos-pay-btn:disabled { opacity: 0.45; cursor: not-allowed; }
    .pos-btn-icon { padding: 0.5rem; }
    .pos-btn-primary { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
    .pos-btn-danger { background: var(--pos-bad); border-color: var(--pos-bad); color: #fff; }
    .pos-btn-danger-soft:hover:not(:disabled) { color: var(--pos-bad); border-color: var(--pos-bad); }
    .pos-btn-lg { padding: 0.8rem 1rem; font-size: 1.05rem; font-weight: 600; flex: 1; }

    /* ---------- products side ---------- */
    .pos-topbar { display: flex; gap: 0.6rem; align-items: center; }
    .pos-searchbar { position: relative; flex: 1; display: flex; align-items: center; }
    .pos-searchbar .pos-search-ico { position: absolute; inset-inline-start: 0.85rem; color: var(--pos-muted); width: 1.3rem; height: 1.3rem; }
    .pos-searchbar .pos-kbd { position: absolute; inset-inline-end: 0.75rem; color: var(--pos-muted); }
    .pos-search { font-size: 1.1rem; padding: 0.8rem 1rem; padding-inline-start: 2.8rem; border-radius: 0.8rem; background: var(--pos-softer); }
    .pos-search:focus { background: var(--pos-bg); }
    .pos-tools { display: flex; gap: 0.35rem; align-items: center; }
    .pos-tool { display: inline-flex; flex-direction: column; align-items: center; gap: 0.15rem; padding: 0.35rem 0.55rem; border-radius: 0.6rem; border: 0;
        background: transparent; color: var(--pos-muted); cursor: pointer; font: inherit; font-size: 0.7rem; text-decoration: none; }
    .pos-tool svg { width: 1.35rem; height: 1.35rem; }
    .pos-tool:hover { background: var(--pos-soft); color: var(--primary-600); }
    .pos-tool.is-danger:hover { color: var(--pos-bad); }
    .pos-clock { font-weight: 600; font-variant-numeric: tabular-nums; color: var(--pos-muted); padding-inline-start: 0.4rem; }
    @media (max-width: 1400px) { .pos-tool span { display: none; } }

    .pos-chips { display: flex; gap: 0.45rem; overflow-x: auto; padding: 0.75rem 0 0.6rem; scrollbar-width: thin; }
    .pos-chip { --cat: var(--primary-500); display: inline-flex; align-items: center; gap: 0.4rem; border: 1px solid var(--pos-border); border-radius: 999px;
        padding: 0.4rem 0.9rem; background: var(--pos-bg); color: var(--pos-text); cursor: pointer; white-space: nowrap; font: inherit; transition: all .15s; }
    .pos-chip svg { width: 1rem; height: 1rem; }
    .pos-dot { width: 0.5rem; height: 0.5rem; border-radius: 999px; background: var(--cat); opacity: 0.55; }
    .pos-chip:hover { border-color: var(--pos-muted); }
    .pos-chip.is-active { background: color-mix(in oklab, var(--primary-500) 12%, var(--pos-bg)); border-color: var(--primary-500); color: var(--primary-700); font-weight: 600; }
    .dark .pos-chip.is-active { color: var(--primary-300); }
    .pos-chip.is-active .pos-dot { opacity: 1; }

    .pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); grid-auto-rows: max-content; gap: 0.6rem; overflow-y: auto; align-content: start; flex: 1; padding: 2px; }
    .pos-card { --cat: var(--primary-500); position: relative; text-align: start; border: 1px solid var(--pos-border); border-radius: 0.85rem; padding: 0.7rem;
        background: var(--pos-bg); color: var(--pos-text); cursor: pointer; display: flex; flex-direction: column; gap: 0.4rem; min-height: 6.5rem;
        transition: transform .12s, box-shadow .12s, border-color .12s; }
    .pos-card:hover { border-color: var(--primary-400); transform: translateY(-1px); box-shadow: 0 6px 16px -10px rgb(0 0 0 / 0.35); }
    .pos-card:active { transform: scale(0.97); }
    .pos-card.is-out { opacity: 0.45; filter: grayscale(0.6); }
    .pos-card-top { display: flex; justify-content: flex-end; min-height: 1.1rem; }
    .pos-card-name { font-weight: 600; font-size: 0.92rem; line-height: 1.3; flex: 1; }
    .pos-elsewhere { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.72rem; font-weight: 600; color: var(--primary-700); }
    .dark .pos-elsewhere { color: var(--primary-300); }
    .pos-elsewhere svg { width: 0.9rem; height: 0.9rem; }
    .pos-card.is-out:has(.pos-elsewhere:not([style*="none"])) { opacity: 0.75; }
    .pos-card-price { font-weight: 700; font-size: 1rem; color: var(--pos-text); font-variant-numeric: tabular-nums; }
    .pos-stock { font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.4rem; border-radius: 999px; color: var(--pos-muted);
        background: var(--pos-soft); font-variant-numeric: tabular-nums; }
    .pos-stock.is-few { color: var(--pos-warn); background: color-mix(in oklab, var(--pos-warn) 14%, transparent); }
    .pos-stock.is-low { color: var(--pos-bad); background: color-mix(in oklab, var(--pos-bad) 12%, transparent); }
    .pos-nothing { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; color: var(--pos-muted); padding: 3rem 0; }
    .pos-nothing svg { width: 2.5rem; height: 2.5rem; }

    /* ---------- cart side ---------- */
    .pos-customer { display: flex; flex-direction: column; gap: 0.55rem; padding-bottom: 0.75rem; border-bottom: 1px dashed var(--pos-border); }
    .pos-customer-current { display: flex; gap: 0.6rem; align-items: center; }
    .pos-customer-info { display: flex; flex-direction: column; flex: 1; min-width: 0; }
    .pos-owes { color: var(--pos-bad); font-weight: 600; }
    .pos-avatar { width: 2.4rem; height: 2.4rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
        font-weight: 700; color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 14%, var(--pos-bg)); flex: none; }
    .dark .pos-avatar { color: var(--primary-300); }
    .pos-avatar.is-small { width: 1.7rem; height: 1.7rem; font-size: 0.8rem; }
    .pos-customer-search { display: flex; gap: 0.4rem; position: relative; }
    .pos-dropdown { position: absolute; inset-inline: 0; top: calc(100% + 4px); z-index: 20; background: var(--pos-bg); border: 1px solid var(--pos-border);
        border-radius: 0.7rem; max-height: 16rem; overflow-y: auto; box-shadow: 0 16px 32px -12px rgb(0 0 0 / 0.25); }
    .pos-dropdown button { display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: start; padding: 0.55rem 0.75rem; background: none; border: 0;
        color: var(--pos-text); cursor: pointer; font: inherit; }
    .pos-dropdown button:hover { background: var(--pos-soft); }
    .pos-badge { display: inline-flex; align-items: center; gap: 0.25rem; background: color-mix(in oklab, var(--primary-500) 15%, transparent);
        color: var(--primary-700); border-radius: 999px; padding: 0.2rem 0.6rem; font-size: 0.75rem; font-weight: 600; }
    .pos-badge svg { width: 0.9rem; height: 0.9rem; }
    .pos-editing { display: flex; align-items: center; gap: 0.4rem; background: color-mix(in oklab, var(--pos-warn) 14%, transparent); color: var(--pos-warn);
        border-radius: 0.6rem; padding: 0.4rem 0.7rem; margin-top: 0.6rem; font-size: 0.85rem; }

    .pos-lines { flex: 1; overflow-y: auto; padding: 0.5rem 0; display: flex; flex-direction: column; gap: 0.45rem; }
    .pos-line { border: 1px solid var(--pos-border); border-radius: 0.7rem;
        padding: 0.5rem 0.65rem; background: var(--pos-softer); transition: background .3s, box-shadow .3s; }
    .pos-line.is-bumped { animation: pos-bump .45s ease-out; }
    @keyframes pos-bump { 0% { background: color-mix(in oklab, var(--primary-400) 18%, transparent); } 100% { background: var(--pos-softer); } }
    .pos-line.is-over { border-color: var(--pos-bad); background: color-mix(in oklab, var(--pos-bad) 6%, transparent); }
    .pos-line-head { display: flex; justify-content: space-between; gap: 0.5rem; align-items: start; }
    .pos-line-name { font-weight: 600; flex: 1; }
    .pos-tool-badged { position: relative; }
    .pos-tool-count { position: absolute; top: 0; inset-inline-end: 0; min-width: 1.1rem; height: 1.1rem; padding: 0 .25rem; border-radius: 9999px;
        background: var(--pos-bad); color: #fff; font-size: .7rem; line-height: 1.1rem; text-align: center; }
    .pos-wallet-lines { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .75rem; }
    .pos-wallet-line { display: flex; flex-direction: column; gap: .1rem; padding: .5rem .7rem; border: 1px solid var(--pos-border); border-radius: .6rem; min-width: 11rem; font-size: .85rem; }
    .pos-wallet-line.is-warning { border-color: var(--pos-warn); }
    .pos-wallet-line.is-danger { border-color: var(--pos-bad); opacity: .75; }
    .pos-wallet-line.is-best { border-color: var(--pos-ok); box-shadow: 0 0 0 2px color-mix(in oklab, var(--pos-ok) 25%, transparent); }
    .pos-wallet-line em { font-style: normal; color: var(--pos-ok); font-weight: 700; font-size: .75rem; }
    .pos-wallet-kind { font-weight: 700; }
    .pos-wallet-kind.is-in { color: var(--pos-ok); }
    .pos-wallet-kind.is-out { color: var(--pos-warn); }
    .pos-wallet-commission { width: 6rem; }
    .pos-wallet-cash { font-size: 1.05rem; }
    .pos-advance-warning { color: var(--pos-warn); font-weight: 600; }
    .pos-advance-success { color: var(--pos-ok); font-weight: 600; }
    .pos-advance-danger { color: var(--pos-bad); font-weight: 600; }
    .pos-advance-info { color: var(--primary-600); font-weight: 600; }
    .pos-below-cost { flex-basis: 100%; font-size: 0.78rem; font-weight: 700; color: var(--pos-bad); }
    .pos-cost { display: inline-flex; align-items: center; gap: 0.3rem; }
    .pos-cost-value { font-size: 0.8rem; font-weight: 600; color: var(--pos-muted, #6b7280); white-space: nowrap;
        background: color-mix(in oklab, var(--primary-500) 10%, transparent); padding: 0.05rem 0.45rem; border-radius: 999px; }
    .pos-cost-btn { background: transparent; border: 0; padding: 0.1rem; cursor: pointer; color: var(--pos-muted, #9ca3af); display: inline-flex; }
    .pos-cost-btn:hover { color: var(--primary-600); }
    .pos-cost-btn svg { width: 1.1rem; height: 1.1rem; }
    .pos-line-body { display: flex; align-items: center; gap: 0.4rem; margin-top: 0.35rem; flex-wrap: wrap; }
    .pos-qty { display: inline-flex; border: 1px solid var(--pos-border); border-radius: 999px; overflow: hidden; background: var(--pos-bg); }
    .pos-qty button { width: 2.1rem; background: transparent; border: 0; color: var(--primary-600); cursor: pointer; font-size: 1.15rem; font-weight: 700; }
    .pos-qty button:hover { background: color-mix(in oklab, var(--primary-500) 12%, transparent); }
    .pos-qty input { width: 3.6rem; text-align: center; border: 0; background: transparent; color: var(--pos-text); font: inherit; font-weight: 700; }
    .pos-line.is-over .pos-qty { border-color: var(--pos-bad); }
    .pos-unit { width: auto; }
    .pos-price { width: 6.5rem; }
    .pos-line-discount { display: inline-flex; gap: 0.3rem; }
    .pos-line-discount .pos-input { width: 5.5rem; }
    .pos-line-total { margin-inline-start: auto; font-variant-numeric: tabular-nums; font-size: 1.02rem; }
    .pos-x { border: 0; background: none; color: var(--pos-muted); cursor: pointer; padding: 0.15rem; border-radius: 0.4rem; }
    .pos-x:hover { color: var(--pos-bad); background: color-mix(in oklab, var(--pos-bad) 10%, transparent); }
    .pos-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.6rem; text-align: center; color: var(--pos-muted); padding: 2.5rem 1rem; flex: 1; }
    .pos-empty svg { width: 3rem; height: 3rem; color: var(--pos-border); }

    .pos-summary { border-radius: 0.9rem; padding: 0.75rem 0.9rem; margin-top: 0.4rem; background: var(--pos-softer); border: 1px solid var(--pos-border); }
    .pos-summary-row { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--pos-muted); font-variant-numeric: tabular-nums; }
    .pos-summary .pos-input { padding: 0.25rem 0.45rem; }
    .pos-invoice-discount { flex-wrap: wrap; }
    .pos-invoice-discount > span:nth-child(2) { display: inline-flex; gap: 0.3rem; align-items: center; }
    .pos-invoice-discount .pos-input { width: 6rem; }
    .pos-grand { display: flex; justify-content: space-between; align-items: baseline; margin-top: 0.35rem; padding-top: 0.45rem;
        border-top: 1px solid var(--pos-border); font-size: 1.05rem; font-weight: 700; }
    .pos-grand span:last-child { font-size: 1.9rem; font-weight: 800; letter-spacing: -0.01em; color: var(--primary-600); font-variant-numeric: tabular-nums; }
    .dark .pos-grand span:last-child { color: var(--primary-400); }

    .pos-pay { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.5rem; margin-top: 0.65rem; }
    .pos-pay-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.25rem; padding: 0.75rem 0.5rem;
        border-radius: 0.85rem; border: 1px solid var(--pos-border); background: var(--pos-bg); color: var(--pos-text); font: inherit;
        font-weight: 700; cursor: pointer; position: relative; transition: transform .1s, box-shadow .15s; }
    .pos-pay-btn svg { width: 1.5rem; height: 1.5rem; color: var(--pos-muted); }
    .pos-pay-btn kbd { position: absolute; top: 0.35rem; inset-inline-end: 0.4rem; font-size: 0.6rem; }
    .pos-pay-btn:hover:not(:disabled) { border-color: var(--primary-400); }
    .pos-pay-btn.is-cash { background: var(--primary-600); border-color: var(--primary-600); color: #fff; grid-column: 1 / -1; flex-direction: row; gap: 0.6rem; font-size: 1.1rem; padding: 0.9rem; }
    .pos-pay-btn.is-cash svg { color: #fff; }
    .pos-pay-btn.is-cash:hover:not(:disabled) { background: var(--primary-700); }
    .pos-pay-btn.is-cash:disabled { background: var(--pos-soft); border-color: var(--pos-border); color: var(--pos-muted); opacity: 1; }
    .pos-pay-btn.is-cash:disabled svg { color: var(--pos-muted); }
    .pos-pay-btn.is-wide { flex-direction: row; width: 100%; font-size: 1.1rem; padding: 0.9rem; }
    .pos-secondary { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
    .pos-secondary .pos-btn { flex: 1; font-size: 0.85rem; padding: 0.45rem 0.5rem; }
    .pos-secondary svg { width: 1rem; height: 1rem; }

    /* ---------- modals ---------- */
    .pos-backdrop { position: fixed; inset: 0; background: rgb(15 23 42 / 0.55); backdrop-filter: blur(3px); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .pos-modal { background: var(--pos-bg); color: var(--pos-text); border-radius: 1.1rem; padding: 1.3rem; width: min(30rem, 100%); display: flex; flex-direction: column;
        gap: 0.7rem; max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 60px -20px rgb(0 0 0 / 0.45); }
    .pos-modal-wide { width: min(48rem, 100%); }
    .pos-modal h3 { font-size: 1.2rem; font-weight: 700; margin: 0; }
    .pos-modal label { display: flex; flex-direction: column; gap: 0.3rem; }
    .pos-modal-total { display: flex; justify-content: space-between; align-items: baseline; font-weight: 700; }
    .pos-modal-total strong { font-size: 1.9rem; font-variant-numeric: tabular-nums; }
    .pos-row { display: flex; gap: 0.4rem; align-items: center; }
    .pos-quick { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .pos-quick .pos-btn { flex: 1; font-weight: 700; font-variant-numeric: tabular-nums; }
    .pos-totals { display: flex; flex-direction: column; gap: 0.3rem; padding: 0.6rem 0; border-top: 1px solid var(--pos-border); }
    .pos-totals > div { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; font-variant-numeric: tabular-nums; }
    .pos-modal .pos-grand { border-top: 0; margin: 0; padding: 0; }
    .pos-modal .pos-grand span:last-child { font-size: 1.4rem; }
    .pos-change { font-size: 1.35rem; font-weight: 800; color: var(--pos-ok); background: color-mix(in oklab, var(--pos-ok) 10%, transparent);
        border-radius: 0.7rem; padding: 0.45rem 0.7rem; }
    .pos-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.4rem; }
    .pos-table { width: 100%; border-collapse: collapse; }
    .pos-table td { padding: 0.5rem; border-bottom: 1px solid var(--pos-border); }
    .pos-table tr:hover td { background: var(--pos-softer); }
    .pos-table tr.is-void td { text-decoration: line-through; color: var(--pos-muted); }

    /* ---------- toast & success ---------- */
    .pos-toast { position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%); z-index: 70; padding: 0.8rem 1.2rem; border-radius: 0.8rem;
        display: flex; align-items: center; gap: 0.5rem; color: #fff; background: var(--pos-ok); box-shadow: 0 14px 30px rgb(0 0 0 / 0.25); max-width: 90vw; font-weight: 600; }
    .pos-toast.is-error { background: var(--pos-bad); }
    .pos-toast.is-warn { background: var(--pos-warn); }
    .pos-success { position: fixed; inset: 0; z-index: 65; display: flex; align-items: center; justify-content: center; background: rgb(15 23 42 / 0.45); backdrop-filter: blur(2px); }
    .pos-success-card { background: var(--pos-bg); border-radius: 1.4rem; padding: 2rem 2.6rem; text-align: center; display: flex; flex-direction: column; align-items: center;
        gap: 0.35rem; box-shadow: 0 30px 70px -20px rgb(0 0 0 / 0.5); animation: pos-pop .35s cubic-bezier(.2, 1.4, .4, 1); }
    .pos-success-card h3 { font-size: 1.4rem; font-weight: 800; margin: 0.4rem 0 0; }
    .pos-success-total { font-size: 2.2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .pos-success-change { font-size: 1.15rem; color: var(--pos-ok); }
    .pos-success-change strong { font-size: 1.5rem; }
    .pos .pos-check { width: 5rem; height: 5rem; }
    .pos-check circle { fill: none; stroke: var(--pos-ok); stroke-width: 3; stroke-dasharray: 151; stroke-dashoffset: 151; animation: pos-draw .5s ease-out forwards; }
    .pos-check path { fill: none; stroke: var(--pos-ok); stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40;
        animation: pos-draw .35s .4s ease-out forwards; }
    @keyframes pos-draw { to { stroke-dashoffset: 0; } }
    @keyframes pos-pop { from { transform: scale(.85); opacity: 0; } to { transform: none; opacity: 1; } }
    .pos-print-frame { position: absolute; width: 0; height: 0; border: 0; visibility: hidden; }

    /* ---------- open shift ---------- */
    .pos-open-shift { max-width: 27rem; margin: 3rem auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 1.2rem; padding: 1.8rem;
        display: flex; flex-direction: column; gap: 0.9rem; box-shadow: 0 20px 40px -24px rgb(0 0 0 / 0.35); }
    .dark .pos-open-shift { background: #111827; border-color: #374151; }
    .pos-open-branch { font-size: 1.15rem; font-weight: 700; margin: 0.25rem 0 0.5rem; }
    .pos-link { display: inline-block; margin-top: 0.35rem; font-weight: 700; color: var(--primary-600); text-decoration: underline; }
    .pos-branch { display: inline-flex; align-items: center; gap: 0.3rem; font-weight: 700; color: var(--primary-700); white-space: nowrap; }
    .dark .pos-branch { color: var(--primary-300); }
    .pos-branch svg { width: 1.1rem; height: 1.1rem; }
    .pos-open-shift-help { display: flex; justify-content: center; margin-top: 0.75rem; }
    .pos-open-shift h2 { font-size: 1.35rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; }
    .pos-open-shift h2 svg { width: 1.6rem; height: 1.6rem; color: var(--primary-500); }
    .pos-open-shift label { display: flex; flex-direction: column; gap: 0.3rem; }
    .pos-open-shift .pos-input { width: 100%; border: 1px solid #d1d5db; border-radius: 0.6rem; padding: 0.55rem 0.7rem; font: inherit; background: transparent; color: inherit; }
    .pos-open-shift .pos-btn { display: inline-flex; justify-content: center; border-radius: 0.7rem; padding: 0.8rem; font: inherit; font-weight: 700; cursor: pointer; border: 0; }
</style>
