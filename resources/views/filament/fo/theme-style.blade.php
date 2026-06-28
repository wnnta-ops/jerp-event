{{-- Theme FO Filament agar TERASA seperti ipro-fo (BO/Bankloan): sidebar oranye
     muda, label grup oranye, item aktif/hover oranye, kartu membulat. Mode terang
     ditint oranye (seperti ipro-fo bg-primary-100); mode gelap tetap default +
     aksen oranye (dari Color::Orange). Diinject via render hook HEAD_END. --}}
<style>
    /* ── Sidebar (light) — tint oranye seperti ipro-fo (primary-100/200/900) ── */
    html:not(.dark) .fi-sidebar,
    html:not(.dark) .fi-sidebar-header {
        background-color: #fff7ed !important;            /* orange-50 */
        border-color: #fed7aa !important;                /* orange-200 */
    }
    html:not(.dark) .fi-sidebar-group-label {
        color: #c2410c !important;                       /* orange-700 */
        font-weight: 800; letter-spacing: .03em;
    }
    html:not(.dark) .fi-sidebar-item-button {
        color: #7c2d12 !important;                       /* orange-900 */
        font-weight: 600;
    }
    html:not(.dark) .fi-sidebar-item-button:hover {
        background-color: #ffedd5 !important;            /* orange-100 */
        color: #9a3412 !important;
    }
    html:not(.dark) .fi-sidebar-item.fi-active > .fi-sidebar-item-button,
    html:not(.dark) .fi-sidebar-item-active .fi-sidebar-item-button {
        background-color: #fed7aa !important;            /* orange-200 */
        color: #7c2d12 !important; font-weight: 800;
    }
    html:not(.dark) .fi-sidebar-item-icon { color: #ea580c !important; }  /* orange-600 */

    /* ── Topbar: garis aksen oranye tipis ── */
    .fi-topbar > nav { border-bottom: 2px solid rgba(255,140,0,.25); }

    /* ── Kartu / section: lebih membulat + bayangan halus (gaya ipro-fo) ── */
    .fi-section, .fi-wi-stats-overview-stat, .fi-ta-ctn, .fi-fo-component-ctn .fi-section {
        border-radius: 1rem !important;
    }
    .fi-section { box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 8px 24px -16px rgba(0,0,0,.18) !important; }

    /* ── Tombol primary: gradien oranye seperti ipro-fo ── */
    .fi-btn.fi-color-primary {
        background-image: linear-gradient(135deg, #ffa733 0%, #ff8c00 100%);
    }

    /* ── Brand header sidebar (logo "J" → nuansa oranye via primary) ── */
    html:not(.dark) .fi-logo { color: #ea580c; }
</style>
