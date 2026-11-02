<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Wallet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import { computed, onMounted } from 'vue';

type Employee = { id: number; name: string; position: string | null };
const props = defineProps<{
    /** Karyawan pemilik akun yang login — satu-satunya pengaju yang mungkin. */
    karyawan: { id: number; name: string; position: string | null; punya_cost_center: boolean } | null;
    /** Rekening tujuan transfer: milik sendiri & milik atasan (Data Karyawan → Atasan). */
    rekening?: Record<string, { employee_id: number; nama: string; jabatan: string | null; bank: string | null; nomor: string | null; ada: boolean }>;
    /** Departemen milik karyawan, Utama lebih dulu. Lebih dari satu → bisa dipilih. */
    departemen?: Array<{ id: number; code: string | null; name: string | null }>;
    /** Terisi = mode Edit Pengajuan (bon berstatus Diajukan); pemiliknya = karyawan di atas. */
    advance?: { id: number; advance_number: string; advance_date: string; holder: string; purpose: string; amount: number | string;
        approval_mode: string | null; rek_sumber: string | null; cost_center_id: number | null; approvals: Record<string, any> | null } | null;
}>();
const modeEdit = computed(() => !!props.advance);
const opsiRek = computed(() => (['sendiri', 'atasan'] as const)
    .filter((k) => props.rekening?.[k])
    .map((k) => ({ kode: k, judul: k === 'sendiri' ? 'Rekening saya' : 'Rekening atasan', ...props.rekening![k] })));
const today = new Date().toISOString().slice(0, 10);
/* Departemen & Cost Center DIHAPUS dari layar ini — bukan datanya.
   Keduanya tetap tercatat, diturunkan server dari karyawan pemilik akun yang login (lihat
   CashAdvanceController::store). Penyaring Departemen pada Laporan Bon Sementara tetap
   berisi seperti sebelumnya. */
/* Cara persetujuan dipilih PER PENGAJUAN, dan bawaannya OFFLINE — di lapangan tanda tangannya
   memang dikumpulkan pada formulir cetak, jadi itulah keadaan yang lazim. Online tetap tersedia
   dan tinggal dipilih pada kartu di sebelahnya.

   Yang perlu disadari saat mengubah ini: offline MEMATIKAN jenjang persetujuan di aplikasi,
   sehingga pembayaran & jurnal boleh dicatat tanpa menunggu tanda tangan digital. Karena itu
   kalimat akibatnya ditulis di kartu pilihannya, bukan disembunyikan. */
// Karyawan DITURUNKAN server dari akun yang login. Cost center = departemen milik karyawan itu:
// dikirim dari sini hanya bila ia punya lebih dari satu (bawaan Utama), dan server menolak
// departemen di luar daftarnya. 'holder' diisi hanya sebagai nilai tampilan yang ikut tersimpan.
const form = useForm<any>(props.advance
    ? { advance_date: String(props.advance.advance_date ?? '').slice(0, 10), holder: props.advance.holder, purpose: props.advance.purpose,
        amount: Number(props.advance.amount), approval_mode: props.advance.approval_mode ?? 'online', rek_sumber: props.advance.rek_sumber,
        cost_center_id: props.advance.cost_center_id ?? props.departemen?.[0]?.id ?? null }
    : { advance_date: today, holder: '', purpose: '', amount: 0, approval_mode: 'offline', rek_sumber: null as string | null,
        cost_center_id: props.departemen?.[0]?.id ?? null });

// Nama pengaju tidak bisa dipilih: ia pemilik akun yang login. Dipakai juga sebagai nilai
// 'holder' yang tersimpan di bonnya. NAMA SAJA, tanpa jabatan (sejak 20 Okt 2026) — jabatan
// tetap ada di Data Karyawan; bon lama yang tersimpan "Nama — Jabatan" tidak diubah.
const namaPengaju = computed(() => props.karyawan?.name?.trim() ?? '');

// Diisikan sejak layar dibuka — isian wajib yang kosong padahal namanya sudah tampil akan
// terbaca seperti bug, bukan seperti data yang belum lengkap.
onMounted(() => {
    if (modeEdit.value) return; // Edit: isian sudah dari bonnya.
    form.holder = namaPengaju.value;
    // Bawaan: rekening sendiri bila terisi, kalau tidak rekening atasan.
    form.rek_sumber = opsiRek.value.find((o) => o.ada)?.kode ?? null;
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() {
    if (props.advance) { form.post(route('bs.advance.update', props.advance.id)); return; }
    form.post(route('bs.advance.store'));
}
const kembaliHref = computed(() => props.advance ? route('bs.advance.show', props.advance.id) : route('bs.advance.index'));
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-amber-600" /> {{ advance ? 'Edit Pengajuan ' + advance.advance_number : 'Pengajuan Bon Sementara' }}</h1>
            <Link :href="kembaliHref" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <div v-if="advance?.approvals && Object.keys(advance.approvals).length" class="rounded-lg bg-amber-50 px-4 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            Bon ini sudah mendapat persetujuan online. Bila isinya diubah, <b>persetujuan dikosongkan</b> dan harus diulang.
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Pengajuan *</label><input v-model="form.advance_date" type="date" required :class="inputCls" /></div>
                <!-- TIDAK BISA DIPILIH. Bon sementara adalah uang yang dipegang orangnya
                     sendiri; mengajukan atas nama orang lain memindahkan beban ke unit kerja
                     yang tidak menanggungnya. Server juga mengabaikan kiriman apa pun dari
                     sini — penguncian di layar saja tidak menahan kiriman langsung. -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Nama Karyawan</label>
                    <div class="flex h-9 items-center rounded-lg border border-neutral-300 bg-neutral-50 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <span v-if="advance">{{ advance.holder }}</span>
                        <span v-else-if="karyawan">{{ namaPengaju }}</span>
                        <span v-else class="text-danger-500">Akun Anda belum tertaut ke data karyawan.</span>
                    </div>
                    <p v-if="!karyawan" class="mt-1 text-xs text-danger-500">Minta Admin melengkapi NIP karyawan pada akun ini.</p>
                    <p v-else-if="!karyawan.punya_cost_center" class="mt-1 text-xs text-danger-500">Karyawan ini belum punya Cost Center di master Karyawan — lengkapi dulu di HR.</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">{{ advance ? 'Pemilik bon — tidak bisa diubah.' : 'Mengikuti akun yang sedang login.' }}</p>
                </div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keperluan *</label><input v-model="form.purpose" required placeholder="mis. Operasional proyek Cluster 3" :class="inputCls" /><p v-if="form.errors.purpose" class="mt-1 text-xs text-danger-500">{{ form.errors.purpose }}</p></div>

                <!-- COST CENTER — inilah tautan bon ke Budget Control. Ditandai bintang merah dan
                     menahan tombol Simpan, sebab akibat mengosongkannya tidak terlihat saat itu
                     juga: bonnya tetap tersimpan, jurnalnya tetap terbentuk, hanya angkanya yang
                     tidak pernah muncul sebagai realisasi anggaran. -->
                <!-- Hanya tampil bila karyawan punya LEBIH DARI SATU departemen (Data Karyawan).
                     Pilihannya terbatas pada departemen miliknya; server menolak yang lain. -->
                <div v-if="(departemen ?? []).length > 1" class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Departemen *</label>
                    <select v-model="form.cost_center_id" required :class="inputCls">
                        <option v-for="(d, i) in departemen" :key="d.id" :value="d.id">{{ d.name }}{{ i === 0 ? ' (Utama)' : '' }}</option>
                    </select>
                    <p class="mt-1 text-[11px] text-neutral-400">Departemen yang dibebani bon ini — tercetak di SIP &amp; terbaca di Budget Control.</p>
                </div>

                <!-- Cara persetujuan — ditentukan di sini karena melekat pada bonnya, bukan
                     pada perusahaannya: satu bon bisa ditandatangani di aplikasi sementara bon
                     lain diedarkan berkasnya. Akibatnya disebutkan langsung di bawah tiap
                     pilihan, sebab yang dipilih di sini menentukan apakah pembayaran nanti
                     tertahan menunggu tanda tangan. -->
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Cara Persetujuan *</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm transition-colors"
                               :class="form.approval_mode === 'online'
                                   ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-900/20'
                                   : 'border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'">
                            <input v-model="form.approval_mode" type="radio" value="online" class="mt-0.5 accent-primary-600" />
                            <span>
                                <span class="block font-medium">Persetujuan Online</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">Persetujuan berjenjang di aplikasi. Pembayaran &amp; jurnal terbuka setelah seluruh tanda tangan terpenuhi.</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm transition-colors"
                               :class="form.approval_mode === 'offline'
                                   ? 'border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-900/20'
                                   : 'border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'">
                            <input v-model="form.approval_mode" type="radio" value="offline" class="mt-0.5 accent-primary-600" />
                            <span>
                                <span class="block font-medium">Persetujuan Offline</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">Tanda tangan di formulir cetak. Jenjang di aplikasi dimatikan — pembayaran &amp; jurnal boleh dicatat tanpa menunggunya.</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.approval_mode" class="mt-1 text-xs text-danger-500">{{ form.errors.approval_mode }}</p>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label><MoneyInput v-model="form.amount" placeholder="0" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                <!-- REKENING TUJUAN TRANSFER — disalin ke bon saat disimpan; tercetak di Memo & BPP. -->
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Rekening Tujuan Transfer *</label>
                    <div v-if="opsiRek.length" class="grid gap-2 sm:grid-cols-2">
                        <label v-for="o in opsiRek" :key="o.kode" class="flex items-start gap-2 rounded-lg border p-3 text-sm transition-colors"
                               :class="! o.ada ? 'cursor-not-allowed border-neutral-200 opacity-60 dark:border-neutral-800'
                                   : form.rek_sumber === o.kode ? 'cursor-pointer border-primary-400 bg-primary-50 dark:border-primary-700 dark:bg-primary-900/20'
                                   : 'cursor-pointer border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'">
                            <input v-model="form.rek_sumber" type="radio" :value="o.kode" :disabled="! o.ada" class="mt-0.5 accent-primary-600" />
                            <span>
                                <span class="block font-medium">{{ o.judul }}</span>
                                <span v-if="o.ada" class="mt-0.5 block text-xs text-neutral-500">{{ o.bank || '—' }} · {{ o.nomor }} · a.n. {{ o.nama }}</span>
                                <span v-else class="mt-0.5 block text-xs text-danger-500">{{ o.nama }} — rekening belum diisi di Data Karyawan</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="! props.rekening?.atasan" class="mt-1 text-[11px] text-neutral-400">Atasan belum diisi di Data Karyawan — hanya rekening sendiri yang bisa dipilih.</p>
                </div>
                <div class="md:col-span-2 rounded-lg bg-amber-50 px-4 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Pengajuan ini <b>belum membentuk jurnal</b>. Jurnal Uang Muka Operasional baru terbentuk saat dibayar oleh dept Finance (memilih tanggal transfer &amp; sumber bank/kas).</div>
            </div>
            <div class="flex gap-2">
                <!-- Gerbangnya dipasang di tombol DAN di server. Menekan Enter pada kolom teks
                     memicu pengiriman implisit yang tidak melewati tombol ini. -->
                <Button type="submit" :disabled="form.processing || ! karyawan || ! karyawan.punya_cost_center"><template #icon-left><Save class="h-4 w-4" /></template>{{ advance ? 'Simpan Perubahan' : 'Simpan Pengajuan' }}</Button>
                <span v-if="! karyawan || ! karyawan.punya_cost_center" class="self-center text-xs text-danger-500">Lengkapi data karyawan dulu.</span>
                <Link :href="kembaliHref" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
