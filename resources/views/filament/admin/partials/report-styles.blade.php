{{-- Shared look of ledgers / statements / financial reports (Filament's CSS has no arbitrary utilities). --}}
<style>
    .rp-filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
    .rp-filters label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
    .rp-filters input, .rp-filters select { border: 1px solid rgb(127 127 127 / 0.35); border-radius: 0.5rem; padding: 0.4rem 0.6rem; background: transparent; color: inherit; }
    .rp-filters .check { flex-direction: row; align-items: center; gap: 0.4rem; }
    .rp-head { margin-bottom: 1rem; display: grid; gap: 0.2rem; font-size: 0.9rem; }
    .rp-head .name { font-size: 1.1rem; font-weight: 700; }
    .rp-muted { opacity: 0.65; }
    .rp-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .rp-table th, .rp-table td { padding: 0.45rem 0.4rem; border-bottom: 1px solid rgb(127 127 127 / 0.2); text-align: start; }
    .rp-table .num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rp-table .carried td, .rp-table .group td { font-weight: 600; background: rgb(127 127 127 / 0.08); }
    .rp-table tfoot td { font-weight: 700; border-top: 2px solid rgb(127 127 127 / 0.4); }
    .rp-table a { color: var(--primary-600); }
    .rp-ok { color: #16a34a; } .rp-bad { color: #dc2626; }
    @media print { .rp-filters { display: none; } }
</style>
