<!--
    Pilihan posisi hutang — Hutang Usaha (AP) / Biaya yang Masih Harus Dibayar (BYMHD) /
    Tampilkan Semua. Dipakai KEDELAPAN layar Query di modul Account Payable.

    Satu komponen, bukan delapan dropdown yang digambar sendiri-sendiri: labelnya adalah
    istilah akuntansi yang harus sama di semua laporan, dan daftar pilihannya datang dari
    server (PosisiHutang::opsi()) supaya layar tidak bisa menawarkan nilai yang tidak dikenal
    penyaringnya — pilihan yang tak dikenal akan jatuh diam-diam ke "Tampilkan Semua", dan
    operator akan melihat angka yang tidak sesuai dengan yang ia pilih.

    Komponen ini TIDAK melakukan navigasi sendiri. Tiap layar punya cara memuat ulang yang
    berbeda (router.get dengan parameternya sendiri, tombol "Tampilkan", atau penyaring di
    klien), jadi ia hanya memancarkan nilai barunya lewat v-model dan layar yang memutuskan
    apa yang terjadi sesudahnya.
-->
<script setup lang="ts">
type Opsi = { nilai: string; label: string };

const props = withDefaults(defineProps<{
    modelValue?: string | null;
    opsi?: Opsi[];
    label?: string;
    /** Diteruskan ke <select> — dipakai layar yang mengunci pilihan (mis. cabang piutang). */
    disabled?: boolean;
}>(), {
    modelValue: 'semua',
    label: 'Posisi Hutang',
    disabled: false,
});

const emit = defineEmits<{ (e: 'update:modelValue', v: string): void; (e: 'ubah', v: string): void }>();

// Cadangan bila server belum mengirim daftarnya (mis. bundel lama bertemu respons baru):
// lebih baik memperlihatkan tiga pilihan yang benar daripada dropdown kosong yang tak bisa
// dipakai sama sekali. Nilainya sama persis dengan konstanta di PosisiHutang.
const BAWAAN: Opsi[] = [
    { nilai: 'semua', label: 'Tampilkan Semua' },
    { nilai: 'hutang', label: 'Hutang Usaha (AP)' },
    { nilai: 'beban_ymhd', label: 'Biaya yang Masih Harus Dibayar (BYMHD)' },
];

function pilih(e: Event) {
    const v = (e.target as HTMLSelectElement).value;
    emit('update:modelValue', v);
    emit('ubah', v);
}
</script>

<template>
    <div>
        <!-- label="" dipakai layar yang kontrolnya duduk di dalam bilah rapat (mis. Proyeksi
             Hutang, Rekap PPh): di sana judul kolom akan merusak perataan barisnya. Judulnya
             tetap terbaca lewat atribut title pada <select>. -->
        <label v-if="props.label" class="mb-1 block text-xs font-medium text-neutral-500">{{ props.label }}</label>
        <select
            :value="props.modelValue ?? 'semua'"
            :disabled="props.disabled"
            data-uji="posisi-hutang"
            title="Saring laporan menurut posisi kredit kategori invoice: Hutang Usaha, Biaya yang Masih Harus Dibayar, atau keduanya"
            class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900"
            @change="pilih"
        >
            <option v-for="o in (props.opsi && props.opsi.length ? props.opsi : BAWAAN)" :key="o.nilai" :value="o.nilai">
                {{ o.label }}
            </option>
        </select>
    </div>
</template>
