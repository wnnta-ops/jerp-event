<div>
{{-- Login utama J-ERP — desain mengikuti PERSIS ipro-fo (Java Core System):
     dua kolom, latar foto + tint hijau gelap, brand gradien oranye, kartu putih. --}}
<style>
    .jcl-wrap { position: fixed; inset: 0; overflow: auto; font-family: 'Inter', system-ui, sans-serif; }
    .jcl-bg {
        position: fixed; inset: 0; z-index: 0;
        background:
            linear-gradient(rgba(10,26,18,.50), rgba(8,28,18,.58)),
            url('{{ asset('images/login-bg.jpg') }}') center 40% / cover no-repeat;
    }
    .jcl-grid {
        position: relative; z-index: 1; min-height: 100vh; display: grid; align-items: center;
        gap: 2.5rem; padding: 2rem 1.5rem; max-width: 1180px; margin: 0 auto;
    }
    @media (min-width: 1024px) { .jcl-grid { grid-template-columns: 1.1fr .9fr; } }

    .jcl-left { color: #fff; text-align: center; }
    @media (min-width: 1024px) { .jcl-left { text-align: left; } }
    .jcl-badge {
        display: inline-block; padding: .3rem .85rem; border-radius: 999px; font-size: .68rem; font-weight: 700;
        letter-spacing: .08em; margin-bottom: 1rem; color: #fff;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.4); backdrop-filter: blur(4px);
    }
    .jcl-welcome { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-weight: 600; font-size: clamp(1.6rem,3.6vw,2.9rem); line-height: 1.1; }
    .jcl-brand {
        font-weight: 900; font-size: clamp(2.1rem,6vw,4.6rem); line-height: 1.04; letter-spacing: -.01em; margin: .15rem 0 1rem;
        background: linear-gradient(90deg,#ffc266 0%,#ff8c00 45%,#ff7a00 100%);
        -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        filter: drop-shadow(0 5px 26px rgba(255,140,0,.35));
    }
    .jcl-tag { font-size: 1rem; line-height: 1.5; color: rgba(255,255,255,.92); max-width: 30rem; margin: 0 auto; }
    @media (min-width: 1024px) { .jcl-tag { margin: 0; } }
    .jcl-pills { display: flex; flex-wrap: wrap; gap: .55rem; justify-content: center; margin-top: 1.3rem; }
    @media (min-width: 1024px) { .jcl-pills { justify-content: flex-start; } }
    .jcl-pill {
        padding: .38rem .85rem; border-radius: 999px; font-size: .8rem; font-weight: 600; color: #fff;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.4); backdrop-filter: blur(4px);
    }

    .jcl-card {
        background: rgba(255,255,255,.97); border-radius: 1.5rem; padding: 2rem 1.9rem 1.6rem; width: 100%; max-width: 26rem; margin: 0 auto;
        box-shadow: 0 34px 80px -22px rgba(10,25,18,.6), 0 8px 24px -8px rgba(0,0,0,.28);
    }
    .jcl-logo {
        display: inline-flex; align-items: center; gap: .45rem; padding: .42rem .8rem; border-radius: 13px; color: #fff;
        background: linear-gradient(135deg,#ffa733 0%,#ff8c00 100%); box-shadow: 0 8px 20px -6px rgba(255,140,0,.6);
    }
    .jcl-logo span { font-size: .5rem; font-weight: 800; line-height: 1.05; letter-spacing: .05em; text-align: left; }
    .jcl-logo-wrap { text-align: center; margin-bottom: .85rem; }
    .jcl-card h1 { text-align: center; font-size: 1.4rem; font-weight: 800; color: #1f2937; margin: 0; }
    .jcl-card .jcl-sub { text-align: center; font-size: .85rem; color: #9ca3af; margin: .15rem 0 1.4rem; }
    .jcl-field { margin-bottom: 1rem; }
    .jcl-field label { display: block; font-size: .8rem; font-weight: 700; color: #374151; margin-bottom: .35rem; }
    .jcl-field input { width: 100%; border: 1px solid #e5e7eb; border-radius: .7rem; padding: .72rem .85rem; font-size: .95rem; color: #111827; background: #fff; }
    .jcl-field input::placeholder { color: #b6bcc6; }
    .jcl-field input:focus { outline: none; border-color: #ff8c00; box-shadow: 0 0 0 3px rgba(255,140,0,.18); }
    .jcl-err { color: #dc2626; font-size: .75rem; margin-top: .3rem; display: block; }
    .jcl-remember { display: flex; align-items: center; gap: .5rem; font-size: .85rem; color: #4b5563; margin-bottom: 1.1rem; cursor: pointer; }
    .jcl-remember input { accent-color: #ff8c00; width: 1rem; height: 1rem; }
    .jcl-btn {
        width: 100%; border: 0; border-radius: .8rem; padding: .82rem; font-size: 1rem; font-weight: 800; color: #fff; cursor: pointer;
        background: linear-gradient(135deg,#ffa733 0%,#ff8c00 100%); box-shadow: 0 10px 24px -8px rgba(255,140,0,.6); transition: filter .15s;
    }
    .jcl-btn:hover { filter: brightness(1.06); }
    .jcl-copy { text-align: center; font-size: .72rem; color: #9ca3af; margin-top: 1.1rem; }
</style>

<div class="jcl-wrap">
    <div class="jcl-bg"></div>
    <div class="jcl-grid">
        {{-- Kiri: headline --}}
        <div class="jcl-left">
            <span class="jcl-badge">JAVA RESIDENCE · PT. MUP</span>
            <div class="jcl-welcome">Welcome Back to</div>
            <div class="jcl-brand">Java Core System</div>
            <p class="jcl-tag">Kelola properti, penjualan, dan keuangan dalam satu sistem — sejuk, asri, dan penuh energi. 🌿</p>
            <div class="jcl-pills">
                <span class="jcl-pill">🏡 Properti Terintegrasi</span>
                <span class="jcl-pill">📊 Real-time</span>
                <span class="jcl-pill">🔒 Aman</span>
            </div>
        </div>

        {{-- Kanan: kartu login --}}
        <div>
            <div class="jcl-card">
                <div class="jcl-logo-wrap">
                    <span class="jcl-logo">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                            <path d="M3 11.5 12 4l9 7.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M5 10.5V20h14v-9.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M10 20v-5h4v5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>JAVA<br>RESIDENCE</span>
                    </span>
                </div>
                <h1>Java Core System</h1>
                <p class="jcl-sub">Sign in untuk lanjut</p>

                <form wire:submit="submit">
                    <div class="jcl-field">
                        <label for="email">Email</label>
                        <input id="email" type="email" wire:model="email" autocomplete="username" inputmode="email" placeholder="anda@mup.local" required autofocus>
                        @error('email') <span class="jcl-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="jcl-field">
                        <label for="password">Password</label>
                        <input id="password" type="password" wire:model="password" autocomplete="current-password" placeholder="••••••••" required>
                        @error('password') <span class="jcl-err">{{ $message }}</span> @enderror
                    </div>
                    <label class="jcl-remember">
                        <input type="checkbox" wire:model="remember"> Tetap masuk
                    </label>
                    <button type="submit" class="jcl-btn">
                        <span wire:loading.remove wire:target="submit">Masuk</span>
                        <span wire:loading wire:target="submit">Memproses…</span>
                    </button>
                </form>

                <p class="jcl-copy">© 2026 PT. MUP — Java Core System v2.0</p>
            </div>
        </div>
    </div>
</div>
</div>
