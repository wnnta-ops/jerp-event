<script setup lang="ts">
// Batang bertumpuk horizontal — komposisi tiap baris, satu segmen per kunci.
// Warna mengikuti KUNCI (entitas), bukan urutan besarannya, sehingga menyaring
// baris tidak pernah mengecat ulang segmen yang tersisa.
import { computed } from 'vue';
import { S, rp, maxAman, kosong, type Kunci, type BarisTumpuk } from './viz';

const props = withDefaults(defineProps<{
    rows: BarisTumpuk[];
    keys: Kunci[];
    hitung?: boolean;
    satuan?: string;
}>(), { hitung: false, satuan: '' });

const W = 560, P = { l: 144, r: 16 }, BH = 24, GP = 10, PT = 6;
const H = computed(() => PT * 2 + props.rows.length * (BH + GP));
const IW = W - P.l - P.r;

const nilai = (r: BarisTumpuk, k: string): number => {
    const v = r[k];
    return typeof v === 'number' && Number.isFinite(v) ? v : 0;
};

const total = computed(() => props.rows.map((r) => props.keys.reduce((a, k) => a + nilai(r, k.k), 0)));
const puncak = computed(() => maxAman(total.value));
const hampa = computed(() => kosong(total.value));

function label(v: number): string {
    return props.hitung ? `${v.toLocaleString('id-ID')}${props.satuan ? ' ' + props.satuan : ''}` : rp(v);
}

const baris = computed(() => props.rows.map((r, i) => {
    let x = P.l;
    const seg = props.keys.map((k, j) => {
        const v = nilai(r, k.k);
        const w = IW * v / puncak.value;
        const s = {
            key: k.k, x, w,
            // sudut membulat hanya di ujung akhir supaya tumpukan terbaca menyatu
            rx: j === props.keys.length - 1 ? 4 : 2,
            fill: S[k.c],
            judul: `${r.n} · ${k.n}: ${label(v)}`,
            // angka hanya ditempel bila segmennya cukup lebar — bukan di setiap segmen
            teks: w > 58 ? label(v) : '',
            tx: x + w / 2 - 1,
        };
        x += w;
        return s;
    }).filter((s) => s.w > 0);
    return { n: r.n, y: PT + i * (BH + GP), seg };
}));
</script>

<template>
    <div class="viz">
        <p v-if="!rows.length || hampa" class="py-10 text-center text-sm text-neutral-400">Belum ada data.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${W} ${H}`" role="img" preserveAspectRatio="xMidYMid meet">
                <g v-for="b in baris" :key="b.n">
                    <text :x="P.l - 10" :y="b.y + BH / 2 + 4" text-anchor="end" class="dlab">{{ b.n }}</text>
                    <g v-for="s in b.seg" :key="s.key">
                        <!-- lebar dikurangi 2px: itulah jeda permukaan antar segmen -->
                        <rect data-mark :x="s.x.toFixed(1)" :y="b.y" :width="Math.max(1, s.w - 2).toFixed(1)"
                              :height="BH" :rx="s.rx" :fill="s.fill"><title>{{ s.judul }}</title></rect>
                        <text v-if="s.teks" :x="s.tx.toFixed(1)" :y="b.y + BH / 2 + 4" text-anchor="middle" class="seglab">{{ s.teks }}</text>
                    </g>
                </g>
            </svg>
            <div class="mt-2 flex flex-wrap gap-2.5 text-[11.5px] text-neutral-600 dark:text-neutral-300">
                <span v-for="(k, i) in keys" :key="i" class="inline-flex items-center gap-1.5">
                    <i class="inline-block h-2.5 w-2.5 rounded-[3px]" :style="{ background: S[k.c] }"></i>{{ k.n }}
                </span>
            </div>
        </template>
    </div>
</template>
