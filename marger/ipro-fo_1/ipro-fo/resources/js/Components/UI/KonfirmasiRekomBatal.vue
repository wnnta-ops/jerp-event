<script setup lang="ts">
/**
 * Konfirmasi DUA TAHAP sebelum Memo Rekom Batal dibuat.
 *
 * Dipakai dua layar sekaligus (Reject BI Checking & Reject Proses KPR) justru supaya
 * kalimatnya tidak pernah berbeda di antara keduanya: rekom batal memberi instruksi ke admin
 * sales untuk membatalkan SPR, jadi peringatannya adalah bagian dari fiturnya — bukan hiasan
 * yang boleh berbeda satu huruf pun antar layar.
 *
 * TIGA KEPUTUSAN YANG MENENTUKAN:
 *
 * 1. TOMBOL "Ya" TAHAP KEDUA ADALAH type="submit" MILIK <form> SUNGGUHAN, bukan pemanggil
 *    window.open. Peramban memblokir tab baru yang dibuka dari callback asinkron karena
 *    aktivasi penggunanya sudah habis — dan diblokirnya DIAM-DIAM: operator menekan "Ya",
 *    tidak terjadi apa-apa, dan tidak ada satu pun galat. Pengiriman <form target="_blank">
 *    dari tombol yang benar-benar ditekan tidak pernah diblokir.
 *
 *    JANGAN GANTI v-show DI BAWAH MENJADI v-if. Keduanya terlihat setara, tetapi @submit
 *    memanggil tutup(), dan dengan v-if itu MENCABUT <form>-nya dari dokumen pada microtask
 *    yang sama — sebelum peramban sempat menjalankan navigasinya. POST-nya lalu dibatalkan
 *    diam-diam: tidak ada tab yang terbuka, tidak ada permintaan di log server, tidak ada
 *    galat. Fitur ini pernah sebulan penuh tampak "sudah jalan" karena persis itu, sementara
 *    tidak satu pun penanda rekom batal pernah tertulis. v-show hanya mematikan display,
 *    elemennya tetap tersambung, dan pengirimannya berjalan.
 *
 * 2. SATU MODAL YANG ISINYA BERGANTI, bukan dua modal bertumpuk. Menumpuk berarti mengurus
 *    z-index, fokus, dan tombol Esc yang menutup lapisan yang salah.
 *
 * 3. "Tidak" HANYA MENUTUP MODAL — tidak berpindah halaman. Daftar reject menyimpan filter
 *    kolom dan centang barisnya di state lokal; berpindah halaman untuk "kembali" justru
 *    membuang pekerjaan operator yang baru saja memilih baris-barisnya.
 */
import { ref, onMounted } from 'vue';
import { ShieldAlert, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    /** URL POST pencatat rekom batal (rute …rekom-batal milik sub-modulnya). */
    action: string;
}>();

const tahap = ref(0);          // 0 = tertutup, 1 = tahap pertama, 2 = tahap kedua
const ids = ref('');
const csrf = ref('');

// Dibaca saat terpasang, BUKAN saat setup: komponen ini ikut dirender di sisi server
// (uji layar memakai SSR) dan di sana `document` tidak ada sama sekali.
onMounted(() => {
    csrf.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
});

function buka(daftarIds: string) {
    ids.value = daftarIds;
    tahap.value = 1;
}
function tutup() { tahap.value = 0; }

defineExpose({ buka });
</script>

<template>
    <!-- v-show, BUKAN v-if — alasannya di keputusan 1 pada docblock di atas. -->
    <form v-show="tahap > 0" :action="props.action" method="post" target="_blank" @submit="tutup()"
        class="fixed inset-0 z-[300] flex items-center justify-center bg-black/50 p-4">
        <input type="hidden" name="_token" :value="csrf" />
        <input type="hidden" name="ids" :value="ids" />

        <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900" @click.stop>
            <div class="mb-3 flex items-start justify-between gap-3">
                <div class="flex items-start gap-2.5">
                    <span class="mt-0.5 flex h-9 w-9 flex-none items-center justify-center rounded-full bg-danger-100 text-danger-600 dark:bg-danger-900/40 dark:text-danger-300">
                        <ShieldAlert class="h-5 w-5" />
                    </span>
                    <h3 class="text-base font-semibold leading-snug">
                        {{ tahap === 1 ? 'Konfirmasi Rekom Batal' : 'Instruksi ke Admin Sales' }}
                    </h3>
                </div>
                <button type="button" @click="tutup" aria-label="Tutup" class="text-neutral-400 hover:text-neutral-700"><X class="h-4 w-4" /></button>
            </div>

            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                <template v-if="tahap === 1">Apakah anda yakin akan membuat rekom batal?</template>
                <template v-else>Rekom batal ini akan memberikan instruksi ke admin sales untuk pembatalan SPR, apakah anda yakin akan hal tersebut?</template>
            </p>

            <p v-if="tahap === 2" class="mt-2 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-900/20 dark:text-danger-300">
                Sesudah ini tombol <b>Ajukan Pembatalan</b> pada Pembatalan Kontrak (Marketing &amp; Sales) terbuka
                untuk SPR yang bersangkutan.
            </p>

            <div class="mt-5 flex justify-end gap-2">
                <Button size="sm" variant="secondary" type="button" @click="tutup">Tidak</Button>

                <!-- Tahap 1 → hanya berpindah isi. Tahap 2 → INI tombol kirim <form>-nya,
                     sehingga tab memo terbuka oleh penekanan tombol yang sesungguhnya. -->
                <Button v-if="tahap === 1" size="sm" variant="danger" type="button" @click="tahap = 2">Ya</Button>
                <Button v-else size="sm" variant="danger" type="submit">Ya</Button>
            </div>
        </div>
    </form>
</template>
