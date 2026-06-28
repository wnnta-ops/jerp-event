<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ approval: any }>();
const a = props.approval;

const form = useForm({ action: '' as 'approve' | 'reject', notes: '' });

function act(action: 'approve' | 'reject') {
    if (action === 'reject' && !form.notes) {
        alert('Alasan penolakan wajib diisi.');
        return;
    }
    form.action = action;
    form.post(route('approvals.act', a.id));
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-4">
        <Link :href="route('approvals.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>

        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold">Approval #{{ a.id }}</h1>
                <p class="text-sm text-neutral-500 mt-1 capitalize">{{ a.document_type?.replace(/_/g, ' ') }}</p>
            </div>
            <Badge :variant="a.status === 'approved' ? 'success' : a.status === 'rejected' ? 'danger' : 'warning'" dot>{{ a.status }}</Badge>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">Detail Permohonan</h2>
            <pre class="text-xs bg-neutral-50 dark:bg-neutral-800 p-4 rounded overflow-x-auto">{{ JSON.stringify(a.payload, null, 2) }}</pre>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">Alur Approval</h2>
            <div class="space-y-3">
                <div v-for="s in a.steps" :key="s.id" class="flex items-center gap-3 text-sm">
                    <div :class="['w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold',
                        s.status === 'approved' ? 'bg-success-500 text-white' :
                        s.status === 'rejected' ? 'bg-danger-500 text-white' : 'bg-neutral-200 dark:bg-neutral-700 text-neutral-500']">
                        {{ s.level }}
                    </div>
                    <div class="flex-1">
                        <p class="font-medium">Level {{ s.level }}</p>
                        <p class="text-xs text-neutral-500">{{ s.status }} {{ s.acted_at ? '· ' + new Date(s.acted_at).toLocaleString('id-ID') : '' }}</p>
                        <p v-if="s.notes" class="text-xs italic">"{{ s.notes }}"</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="a.status === 'pending' || a.status === 'in_review'" class="card p-6 space-y-3">
            <h2 class="font-semibold">Aksi Anda</h2>
            <textarea v-model="form.notes" class="input-base h-20" placeholder="Catatan / alasan (wajib jika reject)..."></textarea>
            <div class="flex gap-2 justify-end">
                <Button variant="danger" :loading="form.processing" @click="act('reject')">Reject</Button>
                <Button variant="success" :loading="form.processing" @click="act('approve')">Approve</Button>
            </div>
        </div>
    </div>
</template>
