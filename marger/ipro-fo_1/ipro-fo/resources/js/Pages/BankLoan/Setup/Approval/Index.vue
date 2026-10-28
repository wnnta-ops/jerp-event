<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MultiSearchSelect from '@/Components/UI/MultiSearchSelect.vue';

type Stage = { stage: string; label: string; actions: string[]; is_required: boolean; approver_user_ids: number[]; bypass_user_ids: number[] };
const props = defineProps<{
    stages: Stage[];
    users: Array<{ id: number; name: string; label: string }>;
    canManage: boolean;
}>();

const form = useForm({
    stages: props.stages.map((s) => ({ stage: s.stage, is_required: s.is_required, approver_user_ids: [...s.approver_user_ids], bypass_user_ids: [...s.bypass_user_ids] })),
});

function submit() {
    form.put(route('kpr.setup.approval.update'), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="flex items-center gap-2 text-2xl font-bold">
            <ShieldCheck class="h-6 w-6 text-primary-600" /> Setup Approval KPR
        </h1>
        <p class="text-sm text-neutral-500">
            Atur per tahap Proses KPR: apakah perubahan data (edit / hapus / mundur tahap / batal) <b>wajib approval</b>,
            siapa <b>approver</b>-nya (cukup satu yang menyetujui), dan siapa yang <b>boleh mengubah langsung</b> tanpa approval.
            Tahap yang tidak wajib approval berjalan seperti biasa. Semua perubahan tetap tercatat di riwayat halaman KPR.
        </p>

        <form @submit.prevent="submit" class="space-y-3">
            <div v-for="(s, i) in stages" :key="s.stage" class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ s.label }}</h2>
                        <p class="mt-0.5 text-xs text-neutral-500">{{ s.actions.join(' · ') }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input v-model="form.stages[i].is_required" type="checkbox" class="rounded" :disabled="!canManage" /> Wajib Approval
                    </label>
                </div>
                <div class="mt-3 grid gap-3 md:grid-cols-2" :class="{ 'opacity-50': !form.stages[i].is_required }">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Approver (boleh Setujui / Tolak)<span v-if="form.stages[i].is_required" class="text-danger-500"> *</span></label>
                        <MultiSearchSelect v-model="form.stages[i].approver_user_ids" :options="users" placeholder="— pilih user —" :disabled="!canManage" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Boleh Ubah Langsung (tanpa approval)</label>
                        <MultiSearchSelect v-model="form.stages[i].bypass_user_ids" :options="users" placeholder="— pilih user —" :disabled="!canManage" />
                    </div>
                </div>
            </div>

            <div v-if="canManage" class="flex justify-end">
                <Button type="submit" :loading="form.processing">Simpan</Button>
            </div>
            <p v-else class="text-xs text-neutral-400">Anda hanya dapat melihat pengaturan ini (perlu izin Set Up KPR — kelola untuk mengubah).</p>
        </form>
    </div>
</template>
