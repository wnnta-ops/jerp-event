<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Layers, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    departments: Array<any>;
    ledgers: Array<any>;
    locations: Array<any>;
}>();

const sections = [
    { type: 'department', title: 'Departemen', hint: 'Divisi / entitas (mis. JR — Java Residence)' },
    { type: 'ledger', title: 'Ledger', hint: 'Buku besar pembantu (mis. Umum / Proyek / Pajak)' },
    { type: 'location', title: 'Lokasi', hint: 'Lokasi / site (mis. Krian, Sidoarjo)' },
];
function rowsOf(type: string): Array<any> {
    return type === 'department' ? props.departments : type === 'ledger' ? props.ledgers : props.locations;
}

const showModal = ref(false);
const currentType = ref('department');
const editingId = ref<number | null>(null);
const form = useForm({ code: '', name: '', is_active: true });
const currentTitle = computed(() => sections.find(s => s.type === currentType.value)?.title ?? '');

function openCreate(type: string) { currentType.value = type; editingId.value = null; form.reset(); form.is_active = true; showModal.value = true; }
function openEdit(type: string, r: any) { currentType.value = type; editingId.value = r.id; form.code = r.code; form.name = r.name; form.is_active = !!r.is_active; showModal.value = true; }
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; } };
    editingId.value
        ? form.put(route('bs.journal-dimensions.update', [currentType.value, editingId.value]), opts)
        : form.post(route('bs.journal-dimensions.store', currentType.value), opts);
}
function destroy(type: string, r: any) {
    if (!confirm(`Hapus ${r.code} — ${r.name}?`)) return;
    router.delete(route('bs.journal-dimensions.destroy', [type, r.id]), { preserveScroll: true });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Dimensi Jurnal</h1>
        <p class="text-sm text-neutral-500">Master dimensi analitik baris jurnal (Struktur) — dipakai pada Registrasi Invoice Hutang &amp; jurnal lainnya.</p>

        <div class="grid gap-4 lg:grid-cols-3">
            <div v-for="s in sections" :key="s.type" class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <div><h2 class="font-semibold">{{ s.title }}</h2><p class="text-[11px] text-neutral-400">{{ s.hint }}</p></div>
                    <Button size="sm" @click="openCreate(s.type)"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah</Button>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2">Kode</th><th class="px-4 py-2">Nama</th><th class="px-4 py-2 text-center">Status</th><th class="px-4 py-2 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in rowsOf(s.type)" :key="r.id">
                            <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                            <td class="px-4 py-2">{{ r.name }}</td>
                            <td class="px-4 py-2 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                            <td class="px-4 py-2"><div class="flex justify-end gap-2">
                                <button @click="openEdit(s.type, r)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                                <button @click="destroy(s.type, r)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                            </div></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!rowsOf(s.type).length" :title="`Belum ada ${s.title.toLowerCase()}`" />
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} {{ currentTitle }}</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
