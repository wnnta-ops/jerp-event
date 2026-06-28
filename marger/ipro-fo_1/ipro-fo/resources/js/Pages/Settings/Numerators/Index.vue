<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ numerators: any[] }>();

const editing = ref<number | null>(null);
const previews = ref<Record<number, string>>({});

async function preview(n: any) {
    const r = await axios.get(`/settings/numerators/${n.code}/preview`, { withCredentials: true });
    previews.value[n.id] = r.data.preview;
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Numerator</h1>
        <p class="text-sm text-neutral-500">Format penomoran dokumen (CUST, BOOK, TTS, dst.)</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Prefix</th>
                        <th class="px-4 py-3">Format</th>
                        <th class="px-4 py-3">Reset</th>
                        <th class="px-4 py-3 text-right">Current Seq</th>
                        <th class="px-4 py-3">Preview Next</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="n in numerators" :key="n.id">
                        <td class="px-4 py-3 font-mono">{{ n.code }}</td>
                        <td class="px-4 py-3">{{ n.prefix }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ n.format }}</td>
                        <td class="px-4 py-3 capitalize">{{ n.reset_strategy }}</td>
                        <td class="px-4 py-3 text-right">{{ n.current_seq }}</td>
                        <td class="px-4 py-3">
                            <button @click="preview(n)" class="text-primary-600 text-sm">Preview →</button>
                            <span v-if="previews[n.id]" class="ml-2 font-mono text-xs">{{ previews[n.id] }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
