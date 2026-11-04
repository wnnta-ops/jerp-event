<script setup lang="ts">
/**
 * System › Approval Chain — siapa saja yang menyetujui tiap alur, per level = user tertentu.
 * Perubahan hanya berlaku untuk pengajuan berikutnya (approval yang sudah berjalan memakai snapshot-nya).
 */
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Workflow, Pencil, Trash2, Plus, X, ArrowRight, Info } from 'lucide-vue-next';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type UserOpt = { id: number; name: string; email: string | null; roles?: string };
type Step = { id: number; level: number; type: string; label: string | null; user: UserOpt | null; value: string };
type Chain = { id: number; code: string; name: string; dokumen: string; kondisi: string | null; is_active: boolean; steps: Step[] };

const props = defineProps<{ chains: Chain[]; users: UserOpt[] }>();

const aktif = computed(() => props.chains.filter((c) => c.is_active));
const nonaktif = computed(() => props.chains.filter((c) => !c.is_active));
const lihatNonaktif = ref(false);

const userOptions = computed(() => props.users.map((u) => ({ id: u.id, label: `${u.name} — ${u.email ?? ''}${u.roles ? ` (${u.roles})` : ''}` })));

// ── Tambah / Edit level ─────────────────────────────────────────────────────
const buka = ref(false);
const chainDipilih = ref<Chain | null>(null);
const stepDiedit = ref<Step | null>(null);
const form = useForm({ user_id: '' as number | '', label: '' });

function tambah(c: Chain) {
    chainDipilih.value = c;
    stepDiedit.value = null;
    form.reset();
    form.clearErrors();
    buka.value = true;
}
function edit(c: Chain, s: Step) {
    chainDipilih.value = c;
    stepDiedit.value = s;
    form.clearErrors();
    form.user_id = s.user?.id ?? '';
    form.label = s.label ?? '';
    buka.value = true;
}
function simpan() {
    const opt = { preserveScroll: true, onSuccess: () => { buka.value = false; } };
    if (stepDiedit.value) form.put(route('settings.approval-chains.steps.update', stepDiedit.value.id), opt);
    else form.post(route('settings.approval-chains.steps.store', chainDipilih.value!.id), opt);
}
function hapus(c: Chain, s: Step) {
    if (confirm(`Hapus Level ${s.level} (${s.label ?? ''}) dari ${c.name}? Level sesudahnya naik satu.`)) {
        router.delete(route('settings.approval-chains.steps.destroy', s.id), { preserveScroll: true });
    }
}

const tipeLama: Record<string, string> = { role: 'peran', assigned: 'ditunjuk saat pengajuan' };
</script>

<template>
    <Head title="Approval Chain" />
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Workflow class="h-6 w-6 text-primary-600" /> Approval Chain</h1>
            <p class="text-sm text-neutral-500">Atur siapa saja yang menyetujui tiap alur — setiap level ditunjuk ke satu user.</p>
        </div>

        <div class="card flex items-start gap-2 p-3 text-xs text-neutral-600 dark:text-neutral-300">
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-primary-600" />
            <p>
                Perubahan <b>hanya berlaku untuk pengajuan berikutnya</b>. Dokumen yang sudah diajukan tetap mengikuti alur saat ia diajukan.
                Nama tahap (mis. <i>Direktur</i>, <i>MW / BA</i>) tampil di tracker Approval Inbox &amp; cetakan — tahap bernama <b>Direktur</b> juga
                menentukan kapan Memo Urgent boleh dibayar di Kuitansi.
            </p>
        </div>

        <div v-for="c in aktif" :key="c.id" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div>
                    <div class="font-semibold">{{ c.name }}</div>
                    <div class="text-[11px] text-neutral-500">{{ c.dokumen }} · <span class="font-mono">{{ c.code }}</span><span v-if="c.kondisi"> · {{ c.kondisi }}</span></div>
                </div>
                <span class="text-sm text-neutral-500">{{ c.steps.length }} level</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 p-4">
                <template v-for="s in c.steps" :key="s.id">
                    <div class="flex w-full items-start justify-between gap-2 rounded-xl border border-neutral-300 p-3 sm:w-72 dark:border-neutral-700">
                        <div class="min-w-0">
                            <div class="text-xs text-neutral-500">Level {{ s.level }}</div>
                            <span class="mt-1 inline-block rounded-md bg-primary-600 px-2 py-0.5 text-xs font-semibold text-white">{{ s.label ?? '—' }}</span>
                            <div v-if="s.user" class="mt-1 truncate text-sm">{{ s.user.name }}</div>
                            <div v-if="s.user" class="truncate text-[11px] text-neutral-400">{{ s.user.email }}</div>
                            <div v-else class="mt-1 text-sm italic text-neutral-500">{{ s.type === 'role' ? s.value : '' }} <span class="text-neutral-400">({{ tipeLama[s.type] ?? s.type }})</span></div>
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button type="button" @click="edit(c, s)" class="rounded-full border border-neutral-400 p-1.5 hover:bg-neutral-100 dark:hover:bg-neutral-800" title="Edit"><Pencil class="h-3.5 w-3.5" /></button>
                            <button type="button" @click="hapus(c, s)" class="rounded-full border border-red-400 p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40" title="Hapus"><Trash2 class="h-3.5 w-3.5" /></button>
                        </div>
                    </div>
                    <ArrowRight class="hidden h-4 w-4 text-neutral-400 sm:block" />
                </template>
                <button type="button" @click="tambah(c)" class="inline-flex items-center gap-1 rounded-full border border-neutral-400 px-3 py-1.5 text-sm hover:bg-neutral-100 dark:hover:bg-neutral-800">
                    <Plus class="h-4 w-4" /> Level
                </button>
            </div>
        </div>

        <div v-if="nonaktif.length">
            <button type="button" @click="lihatNonaktif = !lihatNonaktif" class="text-xs text-neutral-500 hover:text-primary-600">
                {{ lihatNonaktif ? 'Sembunyikan' : 'Tampilkan' }} {{ nonaktif.length }} chain nonaktif
            </button>
            <div v-if="lihatNonaktif" class="mt-2 space-y-1">
                <div v-for="c in nonaktif" :key="c.id" class="card px-4 py-2 text-xs text-neutral-500">
                    <b>{{ c.name }}</b> · <span class="font-mono">{{ c.code }}</span> — {{ c.steps.map((s) => s.label ?? s.value).join(' → ') }}
                </div>
            </div>
        </div>

        <!-- Tambah / Edit level -->
        <div v-if="buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="buka = false">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ stepDiedit ? `Edit Level ${stepDiedit.level}` : 'Tambah Level' }} — {{ chainDipilih?.name }}</h2>
                    <button type="button" @click="buka = false" class="text-neutral-400 hover:text-neutral-700"><X class="h-5 w-5" /></button>
                </div>
                <form class="space-y-3" @submit.prevent="simpan">
                    <div>
                        <label class="label-base">Nama Tahap</label>
                        <input v-model="form.label" class="input-base" placeholder="mis. Accounting, Direktur, MW / BA" />
                        <p v-if="form.errors.label" class="mt-1 text-xs text-red-500">{{ form.errors.label }}</p>
                    </div>
                    <div>
                        <label class="label-base">User Approver</label>
                        <SearchSelect v-model="form.user_id" :options="userOptions" placeholder="Cari nama / email…" />
                        <p v-if="form.errors.user_id" class="mt-1 text-xs text-red-500">{{ form.errors.user_id }}</p>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="buka = false" class="h-9 rounded-lg border border-neutral-300 px-4 text-sm font-medium dark:border-neutral-700">Batal</button>
                        <button type="submit" :disabled="form.processing" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                            {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
