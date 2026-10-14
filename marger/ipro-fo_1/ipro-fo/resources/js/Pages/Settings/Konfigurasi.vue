<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, SlidersHorizontal, Save, Receipt, CalendarClock, Hand, Star, Banknote, FileCheck, Bell, Smile } from 'lucide-vue-next';

const props = defineProps<{
    config: Record<string, Record<string, any>>;
    schema: Record<string, Record<string, { label: string; type: string }>>;
}>();

const form = useForm({ config: JSON.parse(JSON.stringify(props.config)) });
function submit() { form.put(route('settings.config.update'), { preserveScroll: true }); }

const sections: Record<string, { title: string; icon: any }> = {
    tax:       { title: 'Pajak (PPN)', icon: Receipt },
    booking:   { title: 'Batas Booking', icon: CalendarClock },
    hold:      { title: 'Hold Unit', icon: Hand },
    nup:       { title: 'NUP', icon: Star },
    va:        { title: 'Penomoran Virtual Account', icon: Banknote },
    mandatory: { title: 'Dokumen Wajib (Mandatory)', icon: FileCheck },
    notif:     { title: 'Notifikasi (SMS Blast & Daily Report)', icon: Bell },
    beranda:   { title: 'Halaman Selamat Datang', icon: Smile },
};
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('settings.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Settings</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><SlidersHorizontal class="h-6 w-6 text-primary-600" /> Konfigurasi Sistem</h1>
            <button @click="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Save class="h-4 w-4" /> Simpan Semua</button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div v-for="(meta, group) in sections" :key="group" class="card p-4">
                <div class="mb-3 flex items-center gap-2 text-sm font-semibold"><component :is="meta.icon" class="h-4 w-4 text-primary-600" /> {{ meta.title }}</div>
                <div class="space-y-3">
                    <template v-for="(field, key) in schema[group]" :key="key">
                        <label v-if="field.type === 'bool'" class="flex items-center justify-between gap-3 text-sm">
                            <span>{{ field.label }}</span>
                            <input type="checkbox" v-model="form.config[group][key]" class="rounded border-neutral-300" />
                        </label>
                        <div v-else-if="field.type === 'text'">
                            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ field.label }}</label>
                            <textarea v-model="form.config[group][key]" rows="6"
                                class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                            <p v-if="form.errors[`config.${group}.${key}`]" class="mt-1 text-xs text-danger-500">{{ form.errors[`config.${group}.${key}`] }}</p>
                        </div>
                        <div v-else>
                            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ field.label }}</label>
                            <input
                                :type="field.type === 'string' ? 'text' : 'number'"
                                :step="field.type === 'number' ? '0.01' : undefined"
                                v-model="form.config[group][key]"
                                :class="inputCls" />
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
