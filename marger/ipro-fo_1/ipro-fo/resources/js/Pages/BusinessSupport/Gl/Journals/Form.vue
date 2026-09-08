<script setup lang="ts">
import { computed } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save, Scale, BookText } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    // cost_center_optional = akun boleh dijurnal TANPA cost center (mis. bank, piutang,
    // akumulasi penyusutan, utang) — lihat CoaTanpaCostCenterSeeder.
    accounts: Array<{ id: number; code: string; name: string; type: string; cost_center_optional?: boolean }>;
    costCenters: Array<{ id: number; code: string; name: string }>;
    editing?: any;
}>();

/** Akun ini boleh tanpa cost center? */
function ccOptional(accountId: any) {
    return !!props.accounts.find((a) => a.id === Number(accountId))?.cost_center_optional;
}
function accLabel(accountId: any) {
    const a = props.accounts.find((x) => x.id === Number(accountId));
    return a ? `${a.code} - ${a.name}` : '';
}

const today = new Date().toISOString().slice(0, 10);
const e = props.editing;
const form = useForm<any>(e ? {
    journal_date: String(e.journal_date).slice(0, 10), reference: e.reference ?? '', description: e.description ?? '',
    cost_center_id: e.cost_center_id ?? '',
    lines: (e.lines ?? []).map((l: any) => ({ account_id: l.account_id, cost_center_id: l.cost_center_id ?? '', description: l.description ?? '', debit: Number(l.debit) || 0, credit: Number(l.credit) || 0 })),
} : {
    journal_date: today, reference: '', description: '', cost_center_id: '',
    lines: [{ account_id: '', cost_center_id: '', description: '', debit: 0, credit: 0 }, { account_id: '', cost_center_id: '', description: '', debit: 0, credit: 0 }],
});
// Baris baru mewarisi cost center header sebagai titik awal — tetap bisa diganti per baris.
function addLine() { form.lines.push({ account_id: '', cost_center_id: form.cost_center_id ?? '', description: '', debit: 0, credit: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }

/* Kolom Keterangan memanjang ke bawah mengikuti isinya, bukan terpotong di ujung kolomnya.
   Tingginya dinolkan dulu sebelum dibaca: tanpa itu scrollHeight hanya bisa BERTAMBAH, sehingga
   kotak yang sudah terlanjur tinggi tidak pernah menyusut lagi saat teksnya dihapus. */
function autoGrow(e: Event) {
    const el = e.target as HTMLTextAreaElement | null;
    if (! el) return;
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

const totalDebit = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.credit) || 0), 0));
const balanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.005 && totalDebit.value > 0);
const allAccountsSet = computed(() => form.lines.every((l: any) => !!l.account_id));
// Akun yang WAJIB cost center tetapi barisnya kosong DAN header juga kosong — cermin rambu server.
const kurangCc = computed<string[]>(() => {
    const out = new Set<string>();
    for (const l of form.lines as any[]) {
        if (!l.account_id) continue;
        if (l.cost_center_id || form.cost_center_id) continue;
        if (ccOptional(l.account_id)) continue;
        out.add(accLabel(l.account_id));
    }
    return [...out];
});
/**
 * Baris ini kurang cost center? Syaratnya sama dengan kurangCc di atas, per baris — cost
 * center header ikut dihitung karena baris yang dikosongkan mewarisinya.
 */
function ccKurang(l: any) {
    return !!l?.account_id && ! l?.cost_center_id && ! form.cost_center_id && ! ccOptional(l.account_id);
}
const userName = computed(() => (usePage().props as any).auth?.user?.name ?? '—');
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function submit() {
    if (!balanced.value || kurangCc.value.length) return;
    if (props.editing) form.put(route('bs.gl.journal.update', props.editing.id));
    else form.post(route('bs.gl.journal.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <!-- max-w-6xl, bukan 4xl: tabel jurnal butuh ~1050px untuk keenam kolomnya. Dengan
         896px, Keterangan/Debit/Kredit-lah yang diperas — "BELI KE", "PPN Ma", dan dua
         total yang saling menempel jadi "1.127.5001.127.500". -->
    <div class="max-w-6xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ editing ? 'Edit Jurnal ' + editing.journal_number : 'Registrasi Jurnal Memorial' }}</h1>
            <Link :href="route('bs.gl.journal.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <div v-if="form.errors.lines || $page.props.flash?.error" class="rounded-lg bg-danger-50 px-4 py-2.5 text-sm text-danger-700">{{ form.errors.lines || $page.props.flash?.error }}</div>
        <div v-if="editing && editing.source !== 'memorial'" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            ⚠️ Jurnal ini berasal dari modul <b>{{ editing.source?.toUpperCase() }}</b>. Mengedit di sini hanya memperbaiki <b>jurnal GL</b>, <b>tidak</b> mengubah dokumen sumbernya (transaksi kas / invoice). Gunakan untuk koreksi pencatatan bila perlu.
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-4">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.journal_date" type="date" required :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.reference" :class="inputCls" /></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label>
                    <SearchSelect v-model="form.cost_center_id" :options="costCenters" placeholder="— tanpa cost center —" />
                    <p v-if="form.errors.cost_center_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cost_center_id }}</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">Menjadi bawaan baris yang dikosongkan. Sebagian akun neraca boleh tanpa cost center.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Dibuat oleh</label>
                    <input :value="userName" type="text" readonly disabled tabindex="-1" title="Otomatis dari user yang login — tidak dapat diubah"
                        class="h-9 w-full cursor-not-allowed rounded-lg border border-neutral-200 bg-neutral-100 px-3 text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400" />
                </div>
            </div>

            <!-- Bentuknya SENGAJA menyalin kartu "Struktur Jurnal" pada Detail Invoice/Jurnal:
                 kepala kartu berlencana Balance, judul kolom Account · Dimensi · Keterangan ·
                 Debit · Kredit, dan baris Grand Total di kaki tabel. Orang memeriksa jurnal di
                 layar detail lalu membetulkannya di sini; dua tata letak berbeda untuk angka
                 yang sama memaksa mata mencari ulang letak tiap kolom setiap kali berpindah.

                 Yang TIDAK ikut disalin: lebar tabelnya. Baris di sini berisi isian sungguhan
                 (dua SearchSelect + tiga kotak), jadi lebar minimumnya dipertahankan dan
                 tabelnya digeser pada layar sempit — bukan diperas sampai Keterangan/Debit/
                 Kredit terpotong. -->
            <div class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <h2 class="flex items-center gap-2 font-semibold"><BookText class="h-5 w-5 text-primary-600" /> Struktur Jurnal</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                        <Badge :variant="balanced ? 'success' : 'warning'" size="sm">{{ balanced ? 'Balance' : 'Tidak balance' }}</Badge>
                    </div>
                </div>
                <!-- SATU BARIS MENDATAR per baris jurnal — Account · Dimensi · Keterangan · Debit ·
                     Kredit sejajar ke samping, persis seperti kartu Struktur Jurnal pada layar
                     detail. Orang memeriksa jurnal di sana lalu membetulkannya di sini; dua tata
                     letak berbeda untuk angka yang sama memaksa mata mencari ulang letak tiap
                     kolom setiap kali berpindah.

                     KETERANGAN YANG PANJANG MEMANJANG KE BAWAH, BUKAN TERPOTONG. Inilah keberatan
                     yang dulu membuat bentuk tabelnya dibongkar menjadi blok bertumpuk: sebagai
                     <input> satu baris, keterangan panjang terpenggal di tengah kata dan tidak
                     bisa dibaca utuh. Kolom itu kini <textarea> setinggi satu baris yang TUMBUH
                     sendiri mengikuti isinya (autoGrow), sehingga barisnya tetap mendatar dan
                     teksnya tetap utuh — dua-duanya, bukan salah satu.

                     Lebar minimum tabel dipertahankan dan kartunya digeser mendatar pada layar
                     sempit: baris di sini berisi isian sungguhan (dua SearchSelect + tiga kotak),
                     jadi memerasnya sampai muat akan mengulang persis masalah yang dulu. -->
                <div class="overflow-x-auto">
                <!-- Lebarnya dipilih agar JUMLAHNYA (1080px) masih muat di lebar halaman ini
                     (max-w-6xl = 1152px). Menuntut lebih lebar dari halamannya adalah persis
                     penyakit yang dulu: browser memeras kolom yang tidak punya lebar, dan
                     Keterangan/Debit/Kredit-lah yang terpotong. Debit & Kredit dipertahankan
                     10rem supaya nilai belasan juta tetap terbaca utuh. -->
                <table class="w-full min-w-[67.5rem] table-fixed text-sm">
                    <colgroup>
                        <col class="w-[17rem]" /><col class="w-[11rem]" /><col class="w-[17rem]" />
                        <col class="w-[10rem]" /><col class="w-[10rem]" /><col class="w-[2.5rem]" />
                    </colgroup>
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                            <th class="px-4 py-2.5">Account</th>
                            <th class="px-4 py-2.5">Dimensi <span class="text-danger-500">*</span></th>
                            <th class="px-4 py-2.5">Keterangan</th>
                            <th class="px-4 py-2.5 text-right">Debit</th>
                            <th class="px-4 py-2.5 text-right">Kredit</th>
                            <th class="px-2 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="(l, i) in form.lines" :key="i" class="align-top"
                            :class="ccKurang(l) ? 'bg-danger-50/40 dark:bg-danger-900/10' : ''">
                            <td class="px-4 py-2.5">
                                <SearchSelect v-model="l.account_id" :options="accounts" placeholder="— pilih / ketik akun —" no-truncate />
                            </td>
                            <!-- Cost center PER COA. Dikosongkan → ikut cost center header, KECUALI
                                 akun yang memang boleh tanpa cost center: dibiarkan kosong apa adanya. -->
                            <td class="px-4 py-2.5">
                                <div :class="ccKurang(l) ? 'rounded-lg ring-1 ring-danger-400' : ''">
                                    <SearchSelect v-model="l.cost_center_id" :options="costCenters" no-truncate
                                        :placeholder="ccOptional(l.account_id) ? '— tanpa cost center —' : '— ikut header —'" />
                                </div>
                                <p v-if="ccKurang(l)" class="mt-0.5 text-[10.5px] font-medium text-danger-500">Wajib diisi</p>
                                <p v-else-if="ccOptional(l.account_id)" class="mt-0.5 text-[10.5px] text-neutral-400">Boleh dikosongkan</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <!-- rows="1" + autoGrow: setinggi satu baris selama isinya pendek,
                                     memanjang ke bawah begitu keterangannya banyak. -->
                                <textarea v-model="l.description" rows="1" @input="autoGrow"
                                          :class="[inputCls,'h-auto min-h-9 resize-none overflow-hidden py-2 leading-snug']"></textarea>
                            </td>
                            <td class="px-4 py-2.5">
                                <input v-model.number="l.debit" type="number" step="1" min="0"
                                       :class="[inputCls,'text-right font-mono tabular-nums']"
                                       @input="l.debit && (l.credit = 0)" />
                            </td>
                            <td class="px-4 py-2.5">
                                <input v-model.number="l.credit" type="number" step="1" min="0"
                                       :class="[inputCls,'text-right font-mono tabular-nums']"
                                       @input="l.credit && (l.debit = 0)" />
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                <button type="button" @click="removeLine(i)" :disabled="form.lines.length<=2"
                                        class="text-danger-500 disabled:opacity-30" title="Hapus baris">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <!-- Grand Total kembali ke KAKI TABEL, sejajar di bawah kolom Debit &
                             Kredit yang ditotalnya — seperti pada kartu Struktur Jurnal. -->
                        <tr class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                            <td class="px-4 py-2.5" colspan="3">Grand Total</td>
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ fmtRp(totalDebit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ fmtRp(totalCredit) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <!-- Lencana di kepala kartu menyebut KEADAANNYA; baris ini menyebut SELISIHNYA
                     dan akibatnya. Layar detail tidak memerlukannya karena jurnalnya sudah
                     tersimpan — di sini justru itu yang menghalangi tombol Simpan. -->
                <div class="border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <div class="flex items-center gap-2 text-sm" :class="balanced ? 'text-success-600' : 'text-danger-600'">
                        <Scale class="h-4 w-4" />
                        <span v-if="balanced">Balance ✓ (debit = kredit)</span>
                        <span v-else>Belum balance — selisih {{ fmtRp(Math.abs(totalDebit - totalCredit)) }}. Jurnal hanya bisa disimpan bila balance.</span>
                    </div>
                    <p v-if="!allAccountsSet" class="mt-1 text-xs text-danger-500">Setiap baris wajib memilih akun.</p>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !balanced || !allAccountsSet || kurangCc.length > 0"><template #icon-left><Save class="h-4 w-4" /></template>{{ editing ? 'Simpan Perubahan' : 'Posting Jurnal' }}</Button>
                <span v-if="kurangCc.length" class="self-center text-xs text-danger-500">
                    Cost center wajib diisi untuk akun: {{ kurangCc.join(', ') }}.
                </span>
                <Link :href="route('bs.gl.journal.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
