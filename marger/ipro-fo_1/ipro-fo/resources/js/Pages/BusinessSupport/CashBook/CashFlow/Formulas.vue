<script setup lang="ts">
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Sigma, Pencil, Info } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

type Rumus = { id: number; code: string; name: string; expression: string; description: string | null; sort_order: number };
defineProps<{ formulas: Rumus[] }>();

const editingId = ref<number | null>(null);
const form = useForm<any>({ name: '', description: '' });

function openEdit(f: Rumus) {
    editingId.value = f.id;
    Object.assign(form, { name: f.name, description: f.description ?? '' });
    form.clearErrors();
}
function submit() {
    form.put(route('bs.cashflow.formulas.update', editingId.value), { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>

        <h1 class="flex items-center gap-2 text-2xl font-bold"><Sigma class="h-6 w-6 text-primary-600" /> Set Up Rumus Cash Flow</h1>
        <p class="text-sm text-neutral-500">Rumus yang dipakai <b>Report Cashflow</b> saat menghitung tiap barisnya. Nama &amp; keterangannya bisa disesuaikan; rumusnya sendiri melekat pada mesin laporan agar angka di layar selalu sama dengan yang dihitung.</p>

        <div class="grid gap-3 lg:grid-cols-2">
            <div v-for="(f, i) in formulas" :key="f.id"
                class="rounded-xl bg-white p-4 ring-1 ring-neutral-200 transition-shadow duration-200 hover:shadow-sm dark:bg-neutral-900 dark:ring-neutral-800">
                <div class="flex items-start gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-xs font-bold text-primary-600 dark:bg-primary-950/50 dark:text-primary-300">{{ i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ f.name }}</h3>
                            <span class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[10px] text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ f.code }}</span>
                        </div>
                        <div class="mt-1.5 rounded-lg bg-primary-50/70 px-2.5 py-1.5 font-mono text-[11.5px] leading-snug text-primary-800 ring-1 ring-primary-100 dark:bg-primary-950/30 dark:text-primary-200 dark:ring-primary-900/50">
                            {{ f.expression }}
                        </div>
                        <p v-if="f.description" class="mt-1.5 flex gap-1.5 text-[11.5px] leading-relaxed text-neutral-500">
                            <Info class="mt-0.5 h-3 w-3 shrink-0" /> <span>{{ f.description }}</span>
                        </p>
                    </div>
                    <button type="button" @click="openEdit(f)" class="shrink-0 rounded-lg p-1.5 text-neutral-400 transition-colors duration-200 hover:bg-neutral-100 hover:text-primary-600 dark:hover:bg-neutral-800" title="Ubah nama & keterangan"><Pencil class="h-4 w-4" /></button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="editingId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editingId = null">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Ubah Keterangan Rumus</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label>
                            <input v-model="form.name" required :class="inputCls" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label>
                            <textarea v-model="form.description" rows="4" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        </div>
                        <p class="rounded-lg bg-neutral-50 px-3 py-2 text-[11px] text-neutral-500 dark:bg-neutral-800/60">Rumus perhitungannya tidak bisa diubah dari sini — mengubah rumus berarti mengubah cara laporan menghitung, dan itu harus lewat perubahan sistem agar angkanya tetap bisa dipertanggungjawabkan.</p>
                        <div class="flex justify-end gap-2 pt-1">
                            <button type="button" @click="editingId = null" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
