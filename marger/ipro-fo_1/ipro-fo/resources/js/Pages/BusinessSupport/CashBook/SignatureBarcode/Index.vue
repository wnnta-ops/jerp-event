<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ScanBarcode, Plus, Pencil, Trash2, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Row = {
    id: number; user_id: number; user_name: string; user_email: string | null;
    code: string; label: string | null; is_active: boolean; svg: string;
};
const props = defineProps<{ rows: Row[]; users: Array<{ id: number; label: string }> }>();

const form = useForm<{ user_id: any; code: string; label: string; is_active: boolean }>({
    user_id: '', code: '', label: '', is_active: true,
});
const editing = ref<Row | null>(null);
const open = ref(false);

function bukaTambah() { editing.value = null; form.reset(); form.clearErrors(); open.value = true; }
function bukaEdit(r: Row) {
    editing.value = r;
    form.clearErrors();
    form.user_id = r.user_id; form.code = r.code; form.label = r.label ?? ''; form.is_active = r.is_active;
    open.value = true;
}
function tutup() { open.value = false; editing.value = null; }

function simpan() {
    const opts = { preserveScroll: true, onSuccess: () => tutup() };
    if (editing.value) form.put(route('bs.cashbook.signature-barcode.update', editing.value.id), opts);
    else form.post(route('bs.cashbook.signature-barcode.store'), opts);
}
function hapus(r: Row) {
    if (!confirm(`Hapus barcode tanda tangan ${r.user_name}? SIP yang sudah disetujui akan kembali menampilkan nama.`)) return;
    router.delete(route('bs.cashbook.signature-barcode.destroy', r.id), { preserveScroll: true });
}

// Pratinjau barcode saat mengetik — dibentuk server memakai encoder yang SAMA dengan
// yang dipakai SIP, jadi yang terlihat di sini persis yang akan tercetak nanti.
const preview = ref('');
const previewOk = ref(true);
let timer: any = null;
watch(() => form.code, (v) => {
    clearTimeout(timer);
    if (!v) { preview.value = ''; previewOk.value = true; return; }
    timer = setTimeout(async () => {
        try {
            const r = await fetch(route('bs.cashbook.signature-barcode.preview') + '?code=' + encodeURIComponent(v));
            const j = await r.json();
            preview.value = j.svg ?? ''; previewOk.value = !!j.ok;
        } catch { preview.value = ''; }
    }, 250);
});

const terpakai = computed(() => new Set(props.rows.filter(r => r.id !== editing.value?.id).map(r => r.user_id)));
const pilihanUser = computed(() => props.users.filter(u => !terpakai.value.has(u.id)));

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ScanBarcode class="h-6 w-6 text-primary-600" /> Set Up Barcode</h1>
                <p class="mt-0.5 max-w-3xl text-sm text-neutral-500">
                    Barcode tanda tangan per pengguna. Begitu seorang pengguna menyetujui salah satu slot
                    <b>Persetujuan (TTD)</b> pada <b>SIP</b>, yang tampil &amp; tercetak adalah barcode ini —
                    bukan namanya. Pengguna yang belum punya barcode tetap tampil sebagai nama.
                </p>
            </div>
            <Button @click="bukaTambah"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Barcode</Button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Pengguna</th>
                    <th class="px-4 py-3">Kode</th>
                    <th class="px-4 py-3">Barcode Tanda Tangan</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ r.user_name }}</div>
                            <div class="text-[11px] text-neutral-400">{{ r.user_email ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-2.5 font-mono">{{ r.code }}</td>
                        <td class="px-4 py-2.5"><div class="text-neutral-900 dark:text-neutral-100" v-html="r.svg"></div></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.label ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge size="sm" :variant="r.is_active ? 'success' : 'neutral'">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" @click="bukaEdit(r)" title="Ubah" class="rounded-lg border border-neutral-300 p-1.5 text-neutral-500 hover:text-primary-600 dark:border-neutral-700"><Pencil class="h-3.5 w-3.5" /></button>
                                <button type="button" @click="hapus(r)" title="Hapus" class="rounded-lg border border-neutral-300 p-1.5 text-neutral-500 hover:text-danger-600 dark:border-neutral-700"><Trash2 class="h-3.5 w-3.5" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada barcode tanda tangan"
                description="Klik Tambah Barcode untuk mendaftarkan barcode TTD seorang pengguna. Selama belum didaftarkan, persetujuan SIP tetap menampilkan nama." />
        </div>

        <!-- Form tambah / ubah -->
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4" @click.self="tutup">
                <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-base font-semibold">{{ editing ? 'Ubah' : 'Tambah' }} Barcode Tanda Tangan</h3>
                        <button type="button" @click="tutup" class="text-neutral-400 hover:text-neutral-700"><X class="h-4 w-4" /></button>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Pengguna</label>
                            <SearchSelect v-model="form.user_id" :options="pilihanUser" placeholder="— pilih pengguna —" />
                            <p v-if="form.errors.user_id" class="mt-1 text-xs text-danger-600">{{ form.errors.user_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kode Barcode</label>
                            <input v-model="form.code" type="text" maxlength="64" :class="inputCls" placeholder="mis. TTD-SPVFIN-001" />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-danger-600">{{ form.errors.code }}</p>
                            <p v-else class="mt-1 text-[11px] text-neutral-400">Huruf, angka, dan tanda baca biasa. Kode ini yang dibaca pemindai.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan (opsional)</label>
                            <input v-model="form.label" type="text" maxlength="120" :class="inputCls" placeholder="mis. SPV Finance" />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4 accent-primary-600" />
                            Aktif — dipakai sebagai TTD pada SIP
                        </label>

                        <!-- Pratinjau: bentuk yang persis akan tampil di SIP. -->
                        <div class="rounded-lg border border-dashed border-neutral-300 p-3 text-center dark:border-neutral-700">
                            <div class="mb-1 text-[11px] font-medium text-neutral-500">Pratinjau</div>
                            <div v-if="preview" class="flex justify-center text-neutral-900 dark:text-neutral-100" v-html="preview"></div>
                            <div v-else-if="form.code && !previewOk" class="text-xs text-danger-600">Kode ini tidak bisa dibentuk menjadi barcode.</div>
                            <div v-else class="text-xs text-neutral-400">Ketik kode untuk melihat barcode-nya.</div>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <Button size="sm" variant="secondary" :disabled="form.processing" @click="tutup">Batal</Button>
                        <Button size="sm" variant="primary" :loading="form.processing" @click="simpan">Simpan</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
