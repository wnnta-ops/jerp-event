<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

defineOptions({ layout: AuthLayout });

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login.attempt'), {
        onFinish: () => form.reset('password'),
    });
}

/* ── Dedaunan melayang di atas foto (energi + asri) ───────────── */
const leafColors = ['#ffb04a', '#ff8c00', '#9bbf5a', '#7fae4b', '#cfe89a', '#ffd27a'];
const leaves = Array.from({ length: 18 }, (_, i) => {
    const r = (n: number) => Math.random() * n;
    return {
        id: i,
        left: r(100),
        size: 7 + r(15),
        delay: -r(24),
        duration: 12 + r(16),
        drift: 24 + r(80),
        color: leafColors[i % leafColors.length],
        rotate: r(360),
        opacity: 0.4 + r(0.45),
        round: r(1) > 0.5,
    };
});

/* ── Parallax mengikuti kursor ─────────────────────────────────── */
const reduce =
    typeof window !== 'undefined' &&
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const mx = ref(0);
const my = ref(0);

function onMove(e: MouseEvent) {
    mx.value = e.clientX / window.innerWidth - 0.5;
    my.value = e.clientY / window.innerHeight - 0.5;
}

function par(dx: number, dy: number) {
    if (reduce) return {};
    return { transform: `translate3d(${mx.value * dx}px, ${my.value * dy}px, 0)` };
}

onMounted(() => {
    if (!reduce) window.addEventListener('mousemove', onMove, { passive: true });
});
onUnmounted(() => window.removeEventListener('mousemove', onMove));
</script>

<template>
    <div class="login-root">
        <!-- ════════ FOTO PERUMAHAN ASLI + OVERLAY BERANIMASI ════════ -->
        <div class="scene" aria-hidden="true">
            <!-- Foto (Ken-Burns zoom + parallax) -->
            <div class="photo-wrap" :style="par(-16, -10)">
                <div class="photo"></div>
            </div>

            <!-- Kabut lembut bergerak -->
            <div class="mist" :style="par(-8, -3)"></div>

            <!-- Cahaya hangat berpendar -->
            <div class="glow" :style="par(12, 9)"></div>

            <!-- Sinar cahaya halus -->
            <div class="rays" :style="par(6, 4)"></div>

            <!-- Penegas kontras untuk teks -->
            <div class="tint"></div>

            <!-- Dedaunan melayang -->
            <div class="leaves" :style="par(20, 8)">
                <span
                    v-for="lf in leaves"
                    :key="lf.id"
                    class="leaf"
                    :class="{ 'leaf-round': lf.round }"
                    :style="{
                        left: lf.left + '%',
                        width: lf.size + 'px',
                        height: lf.size + 'px',
                        background: lf.color,
                        opacity: lf.opacity,
                        '--delay': lf.delay + 's',
                        '--dur': lf.duration + 's',
                        '--drift': lf.drift + 'px',
                        '--rot': lf.rotate + 'deg',
                    }"
                ></span>
            </div>

            <div class="vignette"></div>
        </div>

        <!-- ════════ KONTEN (headline + kartu login) ════════ -->
        <div class="grid-content">
            <!-- Headline kiri -->
            <section class="headline">
                <div class="badge-pill">JAVA RESIDENCE · PT. MUP</div>
                <h2 class="welcome">Welcome Back to</h2>
                <h1 class="brand">Java Core System</h1>
                <p class="tagline">
                    Kelola properti, penjualan, dan keuangan dalam satu sistem —
                    sejuk, asri, dan penuh energi. 🌿
                </p>
                <div class="pills">
                    <span class="pill">🏡 Properti Terintegrasi</span>
                    <span class="pill">📊 Real-time</span>
                    <span class="pill">🔒 Aman</span>
                </div>
            </section>

            <!-- Kartu login kanan -->
            <section class="form-side">
                <div class="login-card">
                    <div class="card-head">
                        <div class="logo">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                                <path d="M3 11.5 12 4l9 7.5" stroke="#fff" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M5 10.5V20h14v-9.5" stroke="#fff" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M10 20v-5h4v5" stroke="#fff" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span class="logo-tag">JAVA<br />RESIDENCE</span>
                        </div>
                        <h1 class="title">Java Core System</h1>
                        <p class="subtitle">Sign in untuk lanjut</p>
                    </div>

                    <form
                        @submit.prevent="submit"
                        method="POST"
                        :action="route('login.attempt')"
                        class="space-y-4"
                        autocomplete="on"
                    >
                        <Input
                            v-model="form.email"
                            label="Email"
                            type="email"
                            name="email"
                            autocomplete="username"
                            inputmode="email"
                            placeholder="anda@mup.local"
                            required
                            autofocus
                            :error="form.errors.email"
                        />
                        <Input
                            v-model="form.password"
                            label="Password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            placeholder="••••••••"
                            required
                            :minlength="8"
                            :error="form.errors.password"
                        />
                        <label class="remember">
                            <input v-model="form.remember" type="checkbox" name="remember" class="rounded" />
                            <span>Tetap masuk</span>
                        </label>

                        <Button type="submit" :loading="form.processing" block size="lg">Masuk</Button>
                    </form>

                    <p class="copyright">© 2026 PT. MUP — Java Core System v2.0</p>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.login-root {
    position: relative;
    min-height: 100vh;
    width: 100%;
    overflow: hidden;
    background: #c9d4d8;
}

/* ── ADEGAN ──────────────────────────────────────────────── */
.scene { position: absolute; inset: 0; z-index: 0; }

/* Foto asli + Ken-Burns */
.photo-wrap { position: absolute; inset: -5%; will-change: transform; }
.photo {
    position: absolute;
    inset: 0;
    background-image: url('/images/login-bg.jpg');
    background-size: cover;
    background-position: 38% 40%;
    background-repeat: no-repeat;
    animation: kenburns 32s ease-in-out infinite alternate;
    will-change: transform;
}
@keyframes kenburns {
    0%   { transform: scale(1.03) translate(0, 0); }
    100% { transform: scale(1.13) translate(-1.6%, -1.1%); }
}

/* Kabut lembut */
.mist {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(60% 40% at 20% 78%, rgba(255,255,255,.18), transparent 70%),
        radial-gradient(50% 35% at 80% 85%, rgba(255,255,255,.14), transparent 70%);
    animation: mistDrift 26s ease-in-out infinite alternate;
}
@keyframes mistDrift {
    0%   { transform: translateX(-2%); opacity: .7; }
    100% { transform: translateX(3%);  opacity: 1; }
}

/* Cahaya hangat */
.glow {
    position: absolute;
    top: -12%;
    left: -6%;
    width: 55%;
    height: 65%;
    background: radial-gradient(circle at 32% 30%, rgba(255,196,110,.30), rgba(255,196,110,0) 62%);
    mix-blend-mode: screen;
    animation: glowPulse 8s ease-in-out infinite;
}
@keyframes glowPulse {
    0%, 100% { opacity: .65; transform: scale(1); }
    50%      { opacity: 1;   transform: scale(1.06); }
}

/* Sinar cahaya */
.rays {
    position: absolute;
    top: -20%;
    left: 0;
    width: 70%;
    height: 120%;
    background: conic-gradient(from 200deg at 18% 8%,
        rgba(255,255,255,0) 0deg,
        rgba(255,248,228,.22) 16deg,
        rgba(255,255,255,0) 34deg,
        rgba(255,248,228,.16) 52deg,
        rgba(255,255,255,0) 70deg);
    mix-blend-mode: screen;
    opacity: .55;
    transform-origin: 18% 8%;
    animation: raySwing 17s ease-in-out infinite alternate;
}
@keyframes raySwing {
    0%   { transform: rotate(-4deg); opacity: .4; }
    100% { transform: rotate(4deg);  opacity: .7; }
}

/* Penegas kontras teks (atas + kiri lebih gelap, kanan bersih) */
.tint {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, rgba(10,26,18,.50) 0%, rgba(10,26,18,.16) 20%, rgba(0,0,0,0) 44%),
        linear-gradient(100deg, rgba(8,28,18,.58) 0%, rgba(8,28,18,.22) 34%, rgba(0,0,0,0) 60%);
}

/* Dedaunan melayang */
.leaves { position: absolute; inset: 0; pointer-events: none; will-change: transform; }
.leaf {
    position: absolute;
    bottom: -6%;
    border-radius: 2px 80% 2px 80%;
    transform: rotate(var(--rot));
    animation: floatUp var(--dur) linear infinite;
    animation-delay: var(--delay);
    will-change: transform;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
}
.leaf-round { border-radius: 60% 40% 55% 45%; }
@keyframes floatUp {
    0%   { transform: translate(0, 0) rotate(var(--rot)); opacity: 0; }
    10%  { opacity: .9; }
    50%  { transform: translate(var(--drift), -52vh) rotate(calc(var(--rot) + 180deg)); }
    90%  { opacity: .8; }
    100% { transform: translate(0, -108vh) rotate(calc(var(--rot) + 360deg)); opacity: 0; }
}

.vignette {
    position: absolute;
    inset: 0;
    box-shadow: inset 0 0 220px rgba(15,30,22,.34);
    pointer-events: none;
}

/* ── KONTEN ──────────────────────────────────────────────── */
.grid-content {
    position: relative;
    z-index: 10;
    display: grid;
    min-height: 100vh;
    grid-template-rows: auto 1fr;
}
@media (min-width: 1024px) {
    .grid-content {
        grid-template-rows: 1fr;
        grid-template-columns: 1.1fr 0.9fr;
    }
}

/* Headline */
.headline {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: .35rem;
    padding: 4.5rem 1.5rem 0.5rem;
    color: #fff;
    text-align: center;
    animation: riseIn 1s cubic-bezier(.2,.7,.2,1) both;
    animation-delay: .1s;
}
@media (min-width: 1024px) {
    .headline {
        padding: 0 2rem 0 4.5rem;
        text-align: left;
        align-items: flex-start;
    }
}
.badge-pill {
    display: inline-block;
    align-self: center;
    font-size: .68rem;
    letter-spacing: .14em;
    font-weight: 700;
    color: #fff;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.4);
    padding: .3rem .8rem;
    border-radius: 999px;
    backdrop-filter: blur(4px);
    margin-bottom: .4rem;
    text-shadow: 0 1px 6px rgba(0,0,0,.4);
}
@media (min-width: 1024px) { .badge-pill { align-self: flex-start; } }
.welcome {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-style: italic;
    font-weight: 600;
    font-size: clamp(1.6rem, 3.6vw, 2.9rem);
    line-height: 1.05;
    text-shadow: 0 2px 22px rgba(0,0,0,.55);
    margin: 0;
}
.brand {
    font-weight: 900;
    font-size: clamp(2.1rem, 6vw, 4.6rem);
    line-height: 1.02;
    letter-spacing: -.01em;
    margin: .1rem 0 0;
    background: linear-gradient(90deg, #ffc266 0%, #ff8c00 45%, #ff7a00 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 4px 24px rgba(255,140,0,.55));
    animation: brandGlow 4.5s ease-in-out infinite;
}
@keyframes brandGlow {
    0%, 100% { filter: drop-shadow(0 4px 18px rgba(255,140,0,.45)); }
    50%      { filter: drop-shadow(0 6px 34px rgba(255,140,0,.75)); }
}
.tagline {
    max-width: 30rem;
    margin-top: .9rem;
    font-size: clamp(.85rem, 1.4vw, 1.02rem);
    line-height: 1.6;
    color: rgba(255,255,255,.95);
    text-shadow: 0 1px 14px rgba(0,0,0,.6);
}
.pills {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    justify-content: center;
    margin-top: 1.1rem;
}
@media (min-width: 1024px) { .pills { justify-content: flex-start; } }
.pill {
    font-size: .78rem;
    font-weight: 600;
    color: #fff;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.3);
    padding: .4rem .8rem;
    border-radius: 999px;
    backdrop-filter: blur(5px);
    text-shadow: 0 1px 6px rgba(0,0,0,.4);
}

/* Sisi form */
.form-side {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem 1rem 3rem;
}
@media (min-width: 1024px) { .form-side { padding: 2rem 3rem; } }

.login-card {
    width: 100%;
    max-width: 25rem;
    background: rgba(255,255,255,.97);
    border-radius: 1.5rem;
    padding: 2rem 1.9rem 1.7rem;
    box-shadow: 0 34px 80px -22px rgba(10,25,18,.6), 0 8px 24px -8px rgba(0,0,0,.28);
    border: 1px solid rgba(255,255,255,.7);
    backdrop-filter: blur(6px);
    animation: riseIn .9s cubic-bezier(.2,.7,.2,1) both;
    animation-delay: .25s;
}

.card-head { text-align: center; margin-bottom: 1.4rem; }
.logo {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    background: linear-gradient(135deg, #ffa733 0%, #ff8c00 100%);
    color: #fff;
    padding: .5rem .7rem;
    border-radius: .9rem;
    box-shadow: 0 8px 20px -6px rgba(255,140,0,.6);
    margin-bottom: .8rem;
}
.logo-tag {
    font-size: .52rem;
    line-height: 1.05;
    font-weight: 800;
    letter-spacing: .08em;
    text-align: left;
}
.title { font-size: 1.4rem; font-weight: 800; color: #1f2937; margin: 0; }
.subtitle { font-size: .85rem; color: #9ca3af; margin-top: .15rem; }
.remember {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .85rem;
    color: #4b5563;
    user-select: none;
}
.remember input { accent-color: #ff8c00; }
.copyright { text-align: center; font-size: .72rem; color: #9ca3af; margin-top: 1.4rem; }

@keyframes riseIn {
    0%   { opacity: 0; transform: translateY(26px); }
    100% { opacity: 1; transform: translateY(0); }
}

/* Hormati preferensi kurangi-gerak */
@media (prefers-reduced-motion: reduce) {
    .photo, .mist, .glow, .rays, .leaf, .brand, .headline, .login-card {
        animation: none !important;
    }
    .leaf { display: none; }
}
</style>
