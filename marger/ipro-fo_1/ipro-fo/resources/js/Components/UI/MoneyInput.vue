<script setup lang="ts">
/**
 * Input nominal Rupiah dengan pemisah ribuan otomatis (mis. 1.000.000).
 * v-model = nilai numerik; tampilan diformat id-ID. tabular-nums agar angka 0 jelas.
 *
 * `decimals` (bawaan 0) mengizinkan angka di belakang koma. Dibuat sebagai OPSI, bukan perilaku
 * baru untuk semua: jalur decimals = 0 sengaja dibiarkan persis seperti semula (toLocaleString
 * apa adanya) supaya belasan pemakaian yang sudah ada tidak bergeser sedikit pun. Yang butuh sen
 * hanyalah kolom yang servernya memang menyimpan decimal(20,2) — mis. Alokasi Kasbon.
 */
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: number | string | null;
    placeholder?: string;
    min?: number;
    disabled?: boolean;
    /** Banyak angka di belakang koma yang boleh diketik. 0 = rupiah penuh (bawaan). */
    decimals?: number;
}>(), {
    placeholder: '0', min: 0, disabled: false, decimals: 0,
});
const emit = defineEmits<{ (e: 'update:modelValue', v: number): void }>();

/** Titik ribuan pada bagian bulat — dipakai saat mengetik dengan desimal. */
const ribuan = (bulat: string) => bulat.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

/**
 * Teks → angka. Hanya koma PERTAMA yang dianggap pemisah desimal; titik selalu dibuang sebagai
 * pemisah ribuan. Tanpa aturan itu, "1.000.000" akan terbaca 1 (titik dikira desimal) — salah
 * sejuta kali lipat, dan jurnalnya tetap balance.
 */
const parse = (s: any): number => {
    const teks = String(s ?? '');
    if (props.decimals <= 0) return Number(teks.replace(/[^\d]/g, '')) || 0;

    const [b, ...sisa] = teks.split(',');
    const bulat = b.replace(/[^\d]/g, '');
    const pecahan = sisa.join('').replace(/[^\d]/g, '').slice(0, props.decimals);
    const n = Number((bulat || '0') + (pecahan ? '.' + pecahan : ''));

    return Number.isFinite(n) ? n : 0;
};

const fmt = (n: any): string => {
    const x = Number(n) || 0;
    if (! x) return '';

    return props.decimals > 0
        ? x.toLocaleString('id-ID', { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals })
        : x.toLocaleString('id-ID');
};

/**
 * Tampilan SELAGI MENGETIK pada mode desimal: bagian bulat diberi titik ribuan, bagian di
 * belakang koma dibiarkan apa adanya — termasuk koma yang baru diketik dan belum berangka.
 * Memformat penuh di sini akan menelan koma itu begitu ditekan, sehingga sen tidak pernah bisa
 * diketik sama sekali.
 */
function tampilKetik(mentah: string): string {
    const adaKoma = mentah.includes(',');
    const [b, ...sisa] = mentah.split(',');
    const bulat = b.replace(/[^\d]/g, '');
    const pecahan = sisa.join('').replace(/[^\d]/g, '').slice(0, props.decimals);

    if (! bulat && ! adaKoma) return '';

    return ribuan(bulat || '0') + (adaKoma ? ',' + pecahan : '');
}

const display = ref(fmt(props.modelValue));
watch(() => props.modelValue, (v) => { if (parse(display.value) !== (Number(v) || 0)) display.value = fmt(v); });

function onInput(e: Event) {
    const mentah = (e.target as HTMLInputElement).value;
    const num = parse(mentah);
    display.value = props.decimals > 0 ? tampilKetik(mentah) : (num ? num.toLocaleString('id-ID') : '');
    emit('update:modelValue', num);
}
</script>
<template>
    <input
        :value="display" @input="onInput" inputmode="numeric" :placeholder="placeholder" :disabled="disabled"
        class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900 disabled:cursor-not-allowed disabled:bg-neutral-100 dark:disabled:bg-neutral-800"
    />
</template>
