<!-- Setup Project — contractor project master CRUD with modal -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, FolderGit2, Plus, Pencil, Trash2, ChevronRight, Sparkles } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    projects: Array<any>;
    coaAccounts: Array<any>;
    salesProjects: Array<any>;
    clusters: Array<any>;
    /** Daftar "proyek" MUK yang punya kontrak — dari salinan bs_muk_contracts. */
    mukProjects: Array<any>;
    /** Pemetaan tersimpan, dikelompokkan: { [project_id]: [{ muk_proyek_kode, muk_proyek_nama }] } */
    mukMap: Record<string, Array<any>>;
    /** Usulan baris Project dari paket MUK yang belum dipetakan — hasil penguraian nama. */
    usulMuk: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// Label COA (kode - nama) untuk kolom tabel.
const coaLabel = computed(() => {
    const m: Record<number, string> = {};
    (props.coaAccounts || []).forEach((a: any) => { m[a.id] = `${a.code} - ${a.name}`; });
    return m;
});

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', dept: '', cluster: '', tahap: '', coa_account_id: '', is_active: true, notes: '', muk_proyek: [] as string[] });

// Berapa proyek MUK yang menempel pada tiap baris — ditampilkan di kolom tabel.
const mukCount = computed<Record<string, number>>(() => {
    const m: Record<string, number> = {};
    Object.entries(props.mukMap || {}).forEach(([pid, list]) => { m[pid] = (list || []).length; });
    return m;
});

// Kode yang sudah dipakai project LAIN — ditandai mati di daftar, supaya operator tidak
// menekan sesuatu yang pasti ditolak server lalu bingung kenapa.
const dipakaiProjectLain = computed<Record<string, string>>(() => {
    const m: Record<string, string> = {};
    Object.entries(props.mukMap || {}).forEach(([pid, list]) => {
        if (String(pid) === String(editingId.value)) return;
        const p = (props.projects || []).find((x: any) => String(x.id) === String(pid));
        (list || []).forEach((r: any) => { m[r.muk_proyek_kode] = p?.code || '?'; });
    });
    return m;
});

const cariMuk = ref('');

// ── Buat dari MUK ────────────────────────────────────────────────────────────
// Usulannya DISALIN ke state lokal supaya bisa disunting: operator mengganti cluster,
// mengosongkan tahap, atau membuang paket, lalu yang dikirim adalah hasil suntingannya —
// bukan perintah "urai ulang", yang akan membuang keputusannya.
const bukaUsul = ref(false);
const usul = ref<any[]>([]);
const formUsul = useForm<any>({ usul: [] });

function bukaDariMuk() {
    usul.value = (props.usulMuk || []).map((g: any) => ({
        kunci: g.kunci,
        pakai: !!g.cluster_id,              // yang tanpa cluster tidak dicentang: belum ada keputusannya
        code: g.kode_usul || '',
        name: g.nama_usul || '',
        cluster: g.cluster_nama || '',
        tahap: g.tahap_no != null ? String(g.tahap_no) : '',
        cluster_no: g.cluster_no,
        paket: (g.paket || []).map((p: any) => ({ ...p, pakai: true })),
    }));
    formUsul.clearErrors();
    bukaUsul.value = true;
}

const usulTerpilih = computed(() => usul.value.filter((g) => g.pakai && g.code && g.name
    && g.paket.some((p: any) => p.pakai)));

function simpanUsul() {
    formUsul.usul = usulTerpilih.value.map((g) => ({
        code: g.code,
        name: g.name,
        cluster: g.cluster || null,
        tahap: g.tahap || null,
        muk_proyek: g.paket.filter((p: any) => p.pakai).map((p: any) => p.kode),
    }));
    formUsul.post(route('bs.spk.project.dari-muk'), {
        preserveScroll: true,
        onSuccess: () => { bukaUsul.value = false; },
    });
}
const mukTersaring = computed(() => {
    const q = cariMuk.value.trim().toLowerCase();
    const list = props.mukProjects || [];
    if (!q) return list;
    return list.filter((p: any) => `${p.proyek_kode} ${p.proyek_nama}`.toLowerCase().includes(q));
});

function toggleMuk(kode: string) {
    if (dipakaiProjectLain.value[kode]) return;
    // Daftarnya DIGANTI, bukan di-splice/push: proxy useForm milik Inertia tidak selalu
    // menyadari perubahan isi array, sehingga centangnya bisa tidak ikut bergerak.
    const ada = form.muk_proyek.includes(kode);
    form.muk_proyek = ada
        ? form.muk_proyek.filter((k: string) => k !== kode)
        : [...form.muk_proyek, kode];
}

// Cluster dropdown di-scope ke sales project yang kodenya cocok dgn kode project;
// bila tak ada yg cocok → tampilkan semua (fallback). Nilai disimpan sbg LABEL (string).
const matchedProjectIds = computed<number[]>(() => {
    const code = String(form.code || '').trim().toLowerCase();
    if (!code) return [];
    return (props.salesProjects || []).filter((p: any) => String(p.code || '').trim().toLowerCase() === code).map((p: any) => p.id);
});
const clusterOptions = computed(() => {
    const ids = matchedProjectIds.value;
    const list = props.clusters || [];
    return ids.length ? list.filter((c: any) => ids.includes(c.project_id)) : list;
});
// Nilai tersimpan mungkin di luar daftar ter-scope (mis. master berubah) → tetap tampilkan.
const clusterInOptions = computed(() => (clusterOptions.value || []).some((c: any) => c.name === form.cluster));

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    form.muk_proyek = [];
    cariMuk.value = '';
    open.value = true;
}
function openEdit(r: any) {
    editingId.value = r.id;
    form.clearErrors();
    form.code = r.code;
    form.name = r.name;
    form.dept = r.dept ?? '';
    form.cluster = r.cluster ?? '';
    form.tahap = r.tahap ?? '';
    form.coa_account_id = r.coa_account_id ?? '';
    form.is_active = !!r.is_active;
    form.notes = r.notes ?? '';
    form.muk_proyek = (props.mukMap?.[r.id] || []).map((m: any) => m.muk_proyek_kode);
    cariMuk.value = '';
    open.value = true;
}
function close() { open.value = false; }
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => close() };
    if (editingId.value) form.put(route('bs.spk.project.update', editingId.value), opts);
    else form.post(route('bs.spk.project.store'), opts);
}
function destroy(id: number) {
    if (!confirm('Hapus project ini?')) return;
    router.delete(route('bs.spk.project.destroy', id), { preserveScroll: true });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FolderGit2 class="h-6 w-6 text-amber-600" /> Setup Project</h1>
            <div class="flex items-center gap-2">
                <Button v-if="usulMuk?.length" variant="secondary" @click="bukaDariMuk">
                    <template #icon-left><Sparkles class="h-4 w-4" /></template>
                    Buat dari MUK ({{ usulMuk.length }})
                </Button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Project</Button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Dept</th><th class="px-4 py-3">Cluster / Tahap</th><th class="px-4 py-3">COA</th><th class="px-4 py-3 text-center">Proyek MUK</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in projects" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.spk.project.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold"><span class="inline-flex items-center gap-1.5 text-primary-600"><ChevronRight class="h-3.5 w-3.5" />{{ r.code }}</span></td>
                        <td class="px-4 py-2.5">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.dept || '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ [r.cluster, r.tahap].filter(Boolean).join(' / ') || '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500 font-mono text-xs">{{ r.coa_account_id ? (coaLabel[r.coa_account_id] || '—') : '—' }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <Badge v-if="mukCount[r.id]" variant="info" size="sm">{{ mukCount[r.id] }} paket</Badge>
                            <span v-else class="text-neutral-400">belum dipetakan</span>
                        </td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.is_active ? 'success' : 'danger'" size="sm">{{ r.is_active ? 'active' : 'inactive' }}</Badge></td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end gap-2">
                                <button @click.stop="openEdit(r)" class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-primary-600 dark:hover:bg-neutral-800"><Pencil class="h-4 w-4" /></button>
                                <button @click.stop="destroy(r.id)" class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!projects?.length" title="Belum ada project" description="Klik Tambah Project untuk membuat master project kontraktor." />
        </div>

        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="close">
                <div class="card max-h-[90vh] w-full max-w-2xl overflow-y-auto p-5">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit Project' : 'Tambah Project' }}</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kode <span class="text-red-500">*</span></label>
                            <input v-model="form.code" :class="inputCls" required />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama <span class="text-red-500">*</span></label>
                            <input v-model="form.name" :class="inputCls" required />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Dept</label>
                            <input v-model="form.dept" :class="inputCls" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Cluster</label>
                                <select v-model="form.cluster" :class="inputCls">
                                    <option value="">— pilih —</option>
                                    <option v-if="form.cluster && !clusterInOptions" :value="form.cluster">{{ form.cluster }} (tersimpan)</option>
                                    <option v-for="c in clusterOptions" :key="c.id" :value="c.name">{{ c.name }}</option>
                                </select>
                                <p v-if="form.errors.cluster" class="mt-1 text-xs text-red-500">{{ form.errors.cluster }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Tahap</label>
                                <input v-model="form.tahap" :class="inputCls" placeholder="mis. 5" />
                                <p v-if="form.errors.tahap" class="mt-1 text-xs text-red-500">{{ form.errors.tahap }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">COA (Akun Konstruksi/CIP default)</label>
                            <select v-model="form.coa_account_id" :class="inputCls">
                                <option value="">— pilih COA —</option>
                                <option v-for="a in coaAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
                            </select>
                            <p v-if="form.errors.coa_account_id" class="mt-1 text-xs text-red-500">{{ form.errors.coa_account_id }}</p>
                            <p v-else class="mt-1 text-[11px] text-neutral-400">Di-prefill sebagai Akun Konstruksi/CIP saat Register SPK.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">
                                Proyek MUK yang termasuk project ini
                                <span v-if="form.muk_proyek.length" class="ml-1 text-primary-600">({{ form.muk_proyek.length }} dipilih)</span>
                            </label>
                            <p class="mb-2 text-[11px] text-neutral-400">
                                "Proyek" di MUK adalah paket pekerjaan, bukan proyek properti — satu project kita
                                biasanya mencakup beberapa paket (housing, infra, kanopi, SI). Centang semua yang termasuk.
                                Satu paket hanya boleh menempel pada satu project.
                            </p>
                            <p v-if="form.errors.muk_proyek" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600 dark:bg-red-900/20">{{ form.errors.muk_proyek }}</p>
                            <input v-model="cariMuk" :class="inputCls" placeholder="Cari kode atau nama paket…" class="mb-2" />
                            <div class="max-h-56 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                                <label v-for="p in mukTersaring" :key="p.proyek_kode"
                                    class="flex items-start gap-2 border-b border-neutral-100 px-3 py-2 text-sm last:border-0 dark:border-neutral-800"
                                    :class="dipakaiProjectLain[p.proyek_kode] ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50'">
                                    <input type="checkbox" class="mt-0.5 h-4 w-4 flex-none rounded border-neutral-300 dark:border-neutral-700"
                                        :checked="form.muk_proyek.includes(p.proyek_kode)"
                                        :disabled="!!dipakaiProjectLain[p.proyek_kode]"
                                        @change="toggleMuk(p.proyek_kode)" />
                                    <span class="min-w-0">
                                        <span class="font-mono text-xs text-neutral-500">{{ p.proyek_kode }}</span>
                                        <span class="ml-1.5">{{ p.proyek_nama }}</span>
                                        <span class="ml-1.5 text-[11px] text-neutral-400">({{ p.jml }} kontrak)</span>
                                        <span v-if="dipakaiProjectLain[p.proyek_kode]" class="ml-1.5 text-[11px] text-amber-600">
                                            sudah dipakai {{ dipakaiProjectLain[p.proyek_kode] }}
                                        </span>
                                    </span>
                                </label>
                                <p v-if="!mukTersaring.length" class="px-3 py-4 text-center text-sm text-neutral-400">
                                    {{ (mukProjects || []).length ? 'Tidak ada yang cocok.' : 'Salinan kontrak MUK masih kosong — jalankan penarikan kontrak dulu.' }}
                                </p>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-neutral-300 dark:border-neutral-700" />
                            Aktif
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label>
                            <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="secondary" @click="close">Batal</Button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Buat dari MUK: usulan yang DISUNTING, bukan dijalankan buta. Cluster bisa diganti,
             tahap boleh dikosongkan, paket bisa dibuang satu-satu. -->
        <Teleport to="body">
            <div v-if="bukaUsul" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="bukaUsul = false">
                <div class="card max-h-[90vh] w-full max-w-4xl overflow-y-auto p-5">
                    <h2 class="mb-1 text-lg font-bold">Buat Project dari paket MUK</h2>
                    <p class="mb-4 text-xs text-neutral-500">
                        Cluster &amp; tahap di bawah DIBACA DARI NAMA paket MUK — usulan, bukan keputusan.
                        Periksa dan ubah seperlunya. <b>Tahap boleh dikosongkan</b>: paket infrastruktur dan
                        site instruction memang mencakup satu cluster penuh, dan kosong berarti seluruh unit
                        di cluster itu.
                    </p>

                    <p v-if="formUsul.errors.usul" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-600 dark:bg-red-900/20">{{ formUsul.errors.usul }}</p>

                    <div class="space-y-3">
                        <div v-for="g in usul" :key="g.kunci"
                             class="rounded-xl border p-3"
                             :class="g.pakai ? 'border-primary-300 dark:border-primary-700/50' : 'border-neutral-200 opacity-60 dark:border-neutral-800'">
                            <label class="mb-2 flex items-center gap-2 text-sm font-semibold">
                                <input type="checkbox" v-model="g.pakai" class="h-4 w-4 rounded border-neutral-300 dark:border-neutral-700" />
                                <span v-if="g.cluster_no">Cluster {{ g.cluster_no }}<span v-if="g.tahap"> · Tahap {{ g.tahap }}</span></span>
                                <span v-else class="text-amber-600">Tanpa cluster — tentukan sendiri</span>
                                <span class="ml-auto text-xs font-normal text-neutral-400">{{ g.paket.length }} paket</span>
                            </label>

                            <div class="grid gap-2 sm:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-[11px] text-neutral-500">Kode</label>
                                    <input v-model="g.code" :class="inputCls" placeholder="mis. C3T4" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="mb-1 block text-[11px] text-neutral-500">Nama</label>
                                    <input v-model="g.name" :class="inputCls" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] text-neutral-500">Tahap <span class="text-neutral-400">(boleh kosong)</span></label>
                                    <input v-model="g.tahap" :class="inputCls" placeholder="—" />
                                </div>
                                <div class="sm:col-span-4">
                                    <label class="mb-1 block text-[11px] text-neutral-500">Cluster</label>
                                    <select v-model="g.cluster" :class="inputCls">
                                        <option value="">— pilih cluster —</option>
                                        <option v-for="c in clusters" :key="c.id" :value="c.name">{{ c.code }} — {{ c.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-2 max-h-28 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                                <label v-for="p in g.paket" :key="p.kode"
                                       class="flex cursor-pointer items-start gap-2 border-b border-neutral-100 px-2.5 py-1.5 text-xs last:border-0 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50">
                                    <input type="checkbox" v-model="p.pakai" class="mt-0.5 h-3.5 w-3.5 flex-none rounded border-neutral-300 dark:border-neutral-700" />
                                    <span><span class="font-mono text-neutral-500">{{ p.kode }}</span> {{ p.nama }}
                                        <span class="text-neutral-400">({{ p.jml_kontrak }} kontrak)</span></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between gap-2">
                        <p class="text-xs text-neutral-500">{{ usulTerpilih.length }} project akan dibuat.</p>
                        <div class="flex gap-2">
                            <Button variant="secondary" type="button" @click="bukaUsul = false">Batal</Button>
                            <Button type="button" :disabled="!usulTerpilih.length || formUsul.processing" @click="simpanUsul">Buat</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
