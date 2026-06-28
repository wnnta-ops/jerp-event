<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ title?: string }>();

const page = usePage();
const flash = computed(() => (page.props as any).flash ?? {});
</script>

<template>
    <Head :title="title ?? 'Pendaftaran NUP — Java Residence'" />
    <div class="min-h-screen bg-neutral-50 dark:bg-neutral-950 flex flex-col">
        <!-- Header brand -->
        <header class="bg-gradient-to-r from-primary-600 to-primary-700 text-white">
            <div class="max-w-4xl mx-auto px-4 py-4 flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white/15">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                        <path d="M3 11.5 12 4l9 7.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M5 10.5V20h14v-9.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10 20v-5h4v5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div>
                    <div class="text-lg font-extrabold leading-tight">Java Residence</div>
                    <div class="text-xs text-white/80">PT. Mitra Usaha Propertindo</div>
                </div>
            </div>
        </header>

        <!-- Flash -->
        <div v-if="flash.success || flash.error" class="max-w-4xl w-full mx-auto px-4 pt-4">
            <div v-if="flash.success" class="rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 px-4 py-2 text-sm">{{ flash.success }}</div>
            <div v-if="flash.error" class="rounded-lg bg-rose-50 text-rose-700 border border-rose-200 px-4 py-2 text-sm">{{ flash.error }}</div>
        </div>

        <main class="flex-1 max-w-4xl w-full mx-auto px-4 py-6">
            <slot />
        </main>

        <footer class="text-center text-xs text-neutral-400 py-6 border-t border-neutral-100 dark:border-neutral-800">
            © 2026 PT. Mitra Usaha Propertindo · Java Residence · Pendaftaran NUP Online
        </footer>
    </div>
</template>
