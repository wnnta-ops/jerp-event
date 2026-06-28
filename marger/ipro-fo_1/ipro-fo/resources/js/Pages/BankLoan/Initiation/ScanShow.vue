<script setup lang="ts">
import { Link, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, ScanLine, Download, Upload, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ init: any }>();
const a = computed(() => props.init);
const docs = computed<any>(() => a.value?.docs ?? null);

// Upload (TANPA OCR) — file disimpan; OCR baru saat tombol Verifikasi & Baca Data diklik.
const upForm = useForm({ ktp: null as any, npwp: null as any });
function onFile(field: 'ktp' | 'npwp', e: Event) {
    const files = (e.target as HTMLInputElement).files;
    upForm[field] = files && files.length ? files[0] : null;
}
function uploadDocs() {
    if (upForm.processing) return;
    upForm.post(route('kpr.scan.upload', a.value.id), { forceFormData: true, preserveScroll: true });
}

const verifying = ref(false);
function verifyDocs() {
    if (verifying.value) return;
    verifying.value = true;
    router.post(route('kpr.scan.verify', a.value.id), {}, { preserveScroll: true, onFinish: () => (verifying.value = false) });
}
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('kpr.scan.index')" class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-primary-600"><ArrowLeft class="h-4 w-4" /> Kembali ke daftar scan</Link>

        <div class="card p-5">
            <h1 class="text-xl font-bold flex items-center gap-2"><ScanLine class="h-5 w-5 text-primary-600" /> Cek Scan KTP &amp; NPWP</h1>
            <p class="text-sm text-neutral-500 mt-0.5">{{ a.customer?.full_name }} · <span class="font-mono">{{ a.number }}</span> · TTS {{ a.tts?.tts_number ?? '—' }}</p>
            <p class="mt-1 text-xs text-neutral-400">Upload KTP &amp; NPWP terlebih dahulu. OCR <b>tidak</b> berjalan saat upload — klik <b>Verifikasi &amp; Baca Data</b> untuk membaca.</p>
        </div>

        <!-- Upload -->
        <div class="card p-5 space-y-3">
            <p class="text-sm font-semibold flex items-center gap-2"><Upload class="h-4 w-4 text-primary-600" /> Upload Dokumen</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="label-base">Upload KTP <span class="text-danger-500">*</span></label>
                    <input type="file" accept="image/*,application/pdf" @change="(e) => onFile('ktp', e)" class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-900/30 dark:file:text-primary-300" />
                </div>
                <div>
                    <label class="label-base">Upload NPWP <span class="text-danger-500">*</span></label>
                    <input type="file" accept="image/*,application/pdf" @change="(e) => onFile('npwp', e)" class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-900/30 dark:file:text-primary-300" />
                </div>
            </div>
            <p v-if="upForm.errors.ktp" class="text-xs text-danger-600">{{ upForm.errors.ktp }}</p>
            <p v-if="upForm.errors.npwp" class="text-xs text-danger-600">{{ upForm.errors.npwp }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="secondary" :loading="upForm.processing" :disabled="!upForm.ktp || !upForm.npwp" @click="uploadDocs">
                    <template #icon-left><Upload class="h-4 w-4" /></template>Upload KTP &amp; NPWP
                </Button>
                <span v-if="docs?.ktp_path" class="inline-flex items-center gap-1 text-xs font-medium text-success-600"><Check class="h-3.5 w-3.5" /> File tersimpan</span>
            </div>
        </div>

        <!-- Verifikasi & Baca Data (OCR on-demand) -->
        <div class="card p-5 space-y-3">
            <p class="text-sm font-semibold flex items-center gap-2"><ScanLine class="h-4 w-4 text-primary-600" /> Verifikasi &amp; Baca Data (OCR)</p>
            <div class="flex flex-wrap items-center gap-3">
                <Button :loading="verifying" :disabled="!docs?.ktp_path" @click="verifyDocs">
                    <template #icon-left><ScanLine class="h-4 w-4" /></template>Verifikasi &amp; Baca Data
                </Button>
                <span v-if="!docs?.ktp_path" class="text-xs text-warning-600">Upload KTP &amp; NPWP dulu.</span>
                <span v-else-if="docs?.ktp_path && !docs?.verified" class="text-xs text-warning-600">Belum diverifikasi — klik untuk membaca via OCR.</span>
                <span v-if="verifying" class="text-xs font-medium text-primary-600">⏳ Membaca foto…</span>
            </div>

            <!-- Hasil baca + verdict -->
            <div v-if="docs?.verified" class="space-y-3 pt-1">
                <div v-if="docs.verdict === 'match'" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                    ✓ KTP &amp; NPWP terbaca &amp; <strong>sesuai</strong> dengan Nama Customer
                </div>
                <div v-else class="rounded-lg bg-rose-600 px-4 py-3 text-sm font-semibold text-white shadow-sm">
                    ✗ KTP &amp; NPWP terbaca, tetapi nama <strong>tidak sesuai</strong> dengan Nama Customer
                </div>
                <p v-if="docs.ktp_dim || docs.npwp_dim" class="text-[11px] text-neutral-400">Resolusi — KTP: {{ docs.ktp_dim ?? '?' }} px · NPWP: {{ docs.npwp_dim ?? '?' }} px <span v-if="docs.generated_at">· dibaca {{ new Date(docs.generated_at).toLocaleTimeString('id-ID') }}</span></p>
                <p v-if="docs.ocr === false" class="text-xs text-amber-600">⚠ Mesin OCR tidak terdeteksi — file tidak dapat dibaca.</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <p class="mb-2 font-semibold flex items-center justify-between">KTP <Check v-if="docs.extracted?.ktp?.match" class="h-4 w-4 text-emerald-500" /></p>
                        <dl class="space-y-1">
                            <div class="flex justify-between gap-3"><dt class="text-neutral-500">NIK</dt><dd class="font-mono">{{ docs.extracted?.ktp?.nik ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-neutral-500">Nama</dt><dd class="font-medium text-right">{{ docs.extracted?.ktp?.nama ?? '—' }}</dd></div>
                        </dl>
                    </div>
                    <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <p class="mb-2 font-semibold flex items-center justify-between">NPWP <Check v-if="docs.extracted?.npwp?.match" class="h-4 w-4 text-emerald-500" /></p>
                        <dl class="space-y-1">
                            <div class="flex justify-between gap-3"><dt class="text-neutral-500">No. NPWP</dt><dd class="font-mono">{{ docs.extracted?.npwp?.no ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-neutral-500">Nama NPWP</dt><dd class="font-medium text-right">{{ docs.extracted?.npwp?.nama ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-neutral-500">NIK</dt><dd class="font-mono">{{ docs.extracted?.npwp?.nik ?? '—' }}</dd></div>
                        </dl>
                    </div>
                </div>

                <details v-if="docs.ktp_text || docs.npwp_text" class="rounded-lg border border-neutral-200 p-3 text-xs dark:border-neutral-700">
                    <summary class="cursor-pointer font-medium text-neutral-600 dark:text-neutral-300">Lihat teks mentah hasil baca (OCR)</summary>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div v-if="docs.ktp_text"><p class="mb-1 font-semibold">KTP</p><pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-neutral-50 p-2 font-mono text-[11px] dark:bg-neutral-800">{{ docs.ktp_text }}</pre></div>
                        <div v-if="docs.npwp_text"><p class="mb-1 font-semibold">NPWP</p><pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-neutral-50 p-2 font-mono text-[11px] dark:bg-neutral-800">{{ docs.npwp_text }}</pre></div>
                    </div>
                </details>
            </div>

            <!-- Download (bila file ada) -->
            <div v-if="docs?.ktp_path" class="flex flex-wrap gap-2 pt-1">
                <a :href="route('kpr.initiation.doc', [a.id, 'ktp'])" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Download class="h-4 w-4" /> Download KTP</a>
                <a :href="route('kpr.initiation.doc', [a.id, 'npwp'])" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Download class="h-4 w-4" /> Download NPWP</a>
            </div>
        </div>
    </div>
</template>
