{{-- Restyle halaman login FO agar mengikuti desain ipro-fo (latar foto + tint
     hijau gelap, kartu putih membulat, brand oranye "Java Core System"). --}}
<style>
    .fi-simple-layout {
        background:
            linear-gradient(rgba(10,26,18,.55), rgba(8,28,18,.62)),
            url('{{ asset('images/login-bg.jpg') }}') center 40% / cover fixed no-repeat;
        min-height: 100vh;
    }
    .fi-simple-main {
        background: rgba(255,255,255,.97) !important;
        border-radius: 1.5rem !important;
        box-shadow: 0 34px 80px -22px rgba(10,25,18,.6), 0 8px 24px -8px rgba(0,0,0,.28) !important;
        border: 0 !important;
    }
    .dark .fi-simple-main { background: rgba(17,24,39,.96) !important; }

    /* Brand block (di-inject sebelum form) */
    .jc-brand { text-align: center; margin-bottom: 1.25rem; }
    .jc-brand__logo {
        width: 52px; height: 52px; margin: 0 auto .65rem;
        border-radius: 15px; display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #ffa733 0%, #ff8c00 100%);
        box-shadow: 0 8px 20px -6px rgba(255,140,0,.6); color: #fff;
    }
    .jc-brand__title {
        font-weight: 900; font-size: 1.7rem; line-height: 1.1; letter-spacing: -.01em;
        background: linear-gradient(90deg, #ffc266 0%, #ff8c00 45%, #ff7a00 100%);
        -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
    }
    .jc-brand__sub { font-size: .8rem; color: #6b7280; margin-top: .25rem; }
    .dark .jc-brand__sub { color: #9ca3af; }
    .jc-brand__badge {
        display: inline-block; margin-bottom: .75rem; padding: .2rem .7rem; border-radius: 999px;
        font-size: .65rem; font-weight: 700; letter-spacing: .08em; color: #b45309;
        background: rgba(255,140,0,.12); border: 1px solid rgba(255,140,0,.3);
    }
    /* Sembunyikan brand bawaan Filament (logo teks) — diganti brand kita */
    .fi-simple-header { display: none !important; }
</style>
