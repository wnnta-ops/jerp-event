<!--
    Setup → Pasangan Unit MUK: menyandingkan nama rumah versi MUK dengan kode unit kita.

    USULAN BUKAN KEPUTUSAN. Kolom pasangan yang terisi warna kuning adalah usulan dari
    blok+nomor yang terurai dari nama MUK; ia BELUM tersimpan sampai Simpan ditekan. Alasannya
    ada di MukPasanganController: salah pasang tidak memunculkan galat apa pun — angkanya tetap
    rapi, totalnya tetap sama dengan nilai SPK, hanya saja rumah A dibayari pekerjaan rumah B.
-->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Link2, Search, Sparkles, Unlink } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Lokasi = {
    lokasi_kode: string;
    nama: string | null;
    tipe_rumah: string | null;
    blok: string | null;
    no_rumah: string | null;
    jml_kontrak: number;
    pasangan_id: number | null;
    unit_id: number | null;
    unit_code: string | null;
    usul_unit_id: number | null;
};

type UnitOpsi = {
    id: number;
    unit_code: string | null;
    tipe: string | null;
    lt: number | null;
    lb: number | null;
    dipakai_oleh: string | null;
};

const props = defineProps<{
    projects: Array<{ id: number; code: string; name: string; cluster: string | null; tahap: string | null }>;
    project: { id: number; code: string; name: string; cluster: string | null; tahap: string | null } | null;
    lokasi: Array<Lokasi>;
    units: Array<UnitOpsi>;
    summary: { lokasi: number; terpasang: number; usul: number; unit: number; unit_bebas: number };
}>();

const PER_HALAMAN = 50;

const projectId = ref<number | null>(props.project?.id ?? null);
const cari = ref('');
const halaman = ref(1);
const menyimpan = ref(false);

/**
 * Pilihan yang BELUM tersimpan, lokasi_kode → unit_id (null = sengaja dikosongkan).
 *
 * Terpisah dari props supaya yang sudah tersimpan dan yang baru dipilih tidak pernah
 * tertukar di layar — operator harus bisa melihat apa yang akan berubah sebelum menekan
 * Simpan. Dikosongkan tiap kali project berganti.
 */
const pilihan = ref<Record<string, number | null>>({});

watch(projectId, (v) => {
    if (v === props.project?.id) return;
    router.get(route('bs.muk.pasangan.index'), v ? { project: v } : {}, { preserveState: false });
});

watch(() => props.project?.id, () => { pilihan.value = {}; halaman.value = 1; });
watch(cari, () => { halaman.value = 1; });

function nilai(l: Lokasi): number | null {
    return l.lokasi_kode in pilihan.value ? pilihan.value[l.lokasi_kode] : l.unit_id;
}

/** Baris yang nilainya berbeda dari yang tersimpan — hanya ini yang dikirim. */
const perubahan = computed(() => props.lokasi
    .filter((l) => l.lokasi_kode in pilihan.value && pilihan.value[l.lokasi_kode] !== l.unit_id)
    .map((l) => ({ lokasi_kode: l.lokasi_kode, unit_id: pilihan.value[l.lokasi_kode] })));

const akanDisimpan = computed(() => perubahan.value.filter((p) => p.unit_id !== null));

/**
 * Unit yang sudah dipegang baris LAIN — tersimpan maupun baru dipilih.
 *
 * Dipakai mengeluarkannya dari pilihan baris ini. Tanpa ini satu unit bisa dipilih dua kali
 * di satu halaman, dan Simpan berakhir dengan penolakan setelah operator bekerja puluhan
 * baris — penolakan yang seharusnya tidak pernah bisa terjadi.
 */
const unitTerpakai = computed(() => {
    const map = new Map<number, string>();
    for (const u of props.units) {
        if (u.dipakai_oleh) map.set(u.id, u.dipakai_oleh);
    }
    for (const l of props.lokasi) {
        const v = nilai(l);
        if (v !== null) map.set(v, l.lokasi_kode);
    }
    return map;
});

function opsiUntuk(l: Lokasi) {
    const dipilih = nilai(l);
    return props.units
        .filter((u) => u.id === dipilih || unitTerpakai.value.get(u.id) === undefined)
        .map((u) => ({
            id: u.id,
            code: u.unit_code ?? String(u.id),
            name: [u.tipe, u.lt ? 'LT ' + u.lt : null, u.lb ? 'LB ' + u.lb : null].filter(Boolean).join(' · '),
        }));
}

const tersaring = computed(() => {
    const q = cari.value.trim().toLowerCase();
    if (!q) return props.lokasi;
    return props.lokasi.filter((l) => [l.nama, l.lokasi_kode, l.tipe_rumah, l.unit_code]
        .some((v) => (v ?? '').toLowerCase().includes(q)));
});

const totalHalaman = computed(() => Math.max(1, Math.ceil(tersaring.value.length / PER_HALAMAN)));
const barisHalaman = computed(() => tersaring.value
    .slice((halaman.value - 1) * PER_HALAMAN, halaman.value * PER_HALAMAN));

/** Usulan di halaman ini yang masih bisa diterima — unit usulnya belum dipegang baris lain. */
const usulHalaman = computed(() => barisHalaman.value.filter((l) => l.usul_unit_id !== null
    && nilai(l) === null
    && unitTerpakai.value.get(l.usul_unit_id) === undefined));

function terimaUsulHalaman() {
    for (const l of usulHalaman.value) {
        pilihan.value = { ...pilihan.value, [l.lokasi_kode]: l.usul_unit_id };
    }
}

function simpan() {
    if (!akanDisimpan.value.length) return;
    menyimpan.value = true;
    router.post(route('bs.muk.pasangan.store'), { pasangan: akanDisimpan.value }, {
        preserveScroll: true,
        onSuccess: () => { pilihan.value = {}; },
        onFinish: () => { menyimpan.value = false; },
    });
}

function lepas(l: Lokasi) {
    if (!l.pasangan_id) return;
    if (!confirm('Lepas pasangan ' + (l.nama ?? l.lokasi_kode) + '? Alokasi SPK rumah ini ikut lepas.')) return;
    router.delete(route('bs.muk.pasangan.destroy', l.pasangan_id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Contractor Management
        </Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Link2 class="h-6 w-6 text-amber-600" /> Pasangan Unit MUK</h1>
            <p class="text-sm text-neutral-500">
                MUK menyebut rumah dengan namanya ("CENDANA JRKC2 VI No. 15"), kami dengan kode unit
                ("JRKC2-002-VI-15"). Sandingkan keduanya di sini — satu kali per rumah, berlaku untuk
                seluruh kontrak yang menyebutnya.
            </p>
        </div>

        <div class="card space-y-3 p-4">
            <label class="block text-xs font-semibold uppercase tracking-wide text-neutral-500">Project</label>
            <SearchSelect
                v-model="projectId"
                :options="projects.map((p) => ({ id: p.id, code: p.code, name: p.name + (p.cluster ? ' — ' + p.cluster + (p.tahap ? ' tahap ' + p.tahap : '') : '') }))"
                placeholder="— pilih project —"
                class="max-w-xl"
            />
            <p v-if="project && !units.length" class="text-sm text-amber-600">
                Project ini belum memulangkan satu unit pun. Buka Setup Project, sunting project ini,
                lalu isi Cluster &amp; Tahap-nya — unitnya disaring dari situ.
            </p>
            <p v-else-if="project && !lokasi.length" class="text-sm text-amber-600">
                Belum ada lokasi MUK pada project ini. Tempelkan paket MUK-nya di Setup Project, lalu
                tarik lokasinya dengan <span class="font-mono">muk:tarik-lokasi</span>.
            </p>
        </div>

        <template v-if="project">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Lokasi MUK</div><div class="mt-1 text-xl font-bold">{{ summary.lokasi }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sudah Dipasangkan</div><div class="mt-1 text-xl font-bold text-emerald-600">{{ summary.terpasang }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Ada Usulan</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.usul }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Unit Project</div><div class="mt-1 text-xl font-bold">{{ summary.unit }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Unit Belum Terpakai</div><div class="mt-1 text-xl font-bold">{{ summary.unit_bebas }}</div></div>
            </div>

            <div class="card flex flex-wrap items-center gap-3 p-3">
                <div class="relative min-w-[16rem] flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input
                        v-model="cari"
                        type="search"
                        placeholder="Cari nama lokasi, kode, tipe, atau kode unit…"
                        class="h-10 w-full rounded-lg border border-neutral-300 pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                    />
                </div>

                <Button variant="outline" size="md" :disabled="!usulHalaman.length" @click="terimaUsulHalaman">
                    <span class="inline-flex items-center gap-2"><Sparkles class="h-4 w-4" /> Terima {{ usulHalaman.length }} usulan di halaman ini</span>
                </Button>

                <Button variant="primary" size="md" :loading="menyimpan" :disabled="!akanDisimpan.length" @click="simpan">
                    Simpan {{ akanDisimpan.length }} pasangan
                </Button>
            </div>

            <!-- Yang belum disimpan DIKATAKAN, supaya halaman yang ditinggal setengah jalan tidak
                 tampak seperti pekerjaan yang sudah selesai. -->
            <p v-if="akanDisimpan.length" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">
                {{ akanDisimpan.length }} pasangan dipilih tetapi BELUM tersimpan. Tekan Simpan sebelum berpindah halaman.
            </p>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">Lokasi MUK</th>
                        <th class="px-3 py-3">Kode</th>
                        <th class="px-3 py-3">Tipe</th>
                        <th class="px-3 py-3 text-center">Blok / No</th>
                        <th class="px-3 py-3 text-center">Kontrak</th>
                        <th class="px-3 py-3 w-80">Unit Kita</th>
                        <th class="px-3 py-3 text-center">Status</th>
                        <th class="px-3 py-3"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="l in barisHalaman" :key="l.lokasi_kode" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-3 py-2.5">{{ l.nama ?? '—' }}</td>
                            <td class="px-3 py-2.5 font-mono text-xs text-neutral-500">{{ l.lokasi_kode }}</td>
                            <td class="px-3 py-2.5 text-neutral-500">{{ l.tipe_rumah ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-center">
                                <span v-if="l.blok" class="font-mono text-xs">{{ l.blok }} / {{ l.no_rumah ?? '?' }}</span>
                                <span v-else class="text-xs text-neutral-400">tak terurai</span>
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums text-neutral-500">{{ l.jml_kontrak }}</td>
                            <td class="px-3 py-2.5">
                                <SearchSelect
                                    :model-value="nilai(l)"
                                    :options="opsiUntuk(l)"
                                    placeholder="— belum dipasangkan —"
                                    @update:model-value="(v: any) => pilihan = { ...pilihan, [l.lokasi_kode]: v ?? null }"
                                />
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                <Badge v-if="l.lokasi_kode in pilihan && pilihan[l.lokasi_kode] !== l.unit_id" variant="warning" size="sm">Belum disimpan</Badge>
                                <Badge v-else-if="l.unit_id" variant="success" size="sm">Terpasang</Badge>
                                <Badge v-else-if="l.usul_unit_id" variant="warning" size="sm">Ada usulan</Badge>
                                <span v-else class="text-xs text-neutral-400">—</span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <button
                                    v-if="l.pasangan_id"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs text-danger-600 hover:underline"
                                    @click="lepas(l)"
                                >
                                    <Unlink class="h-3 w-3" /> Lepas
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <EmptyState v-if="!tersaring.length" title="Tidak ada lokasi" description="Tidak ada lokasi MUK yang cocok dengan pencarian pada project ini." />

                <div v-if="totalHalaman > 1" class="flex items-center justify-between border-t border-neutral-200 px-4 py-3 text-sm dark:border-neutral-800">
                    <span class="text-neutral-500">Halaman {{ halaman }} dari {{ totalHalaman }} · {{ tersaring.length }} lokasi</span>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="halaman <= 1" @click="halaman--">Sebelumnya</Button>
                        <Button variant="outline" size="sm" :disabled="halaman >= totalHalaman" @click="halaman++">Berikutnya</Button>
                    </div>
                </div>
            </div>
        </template>

        <EmptyState v-else title="Pilih project" description="Lokasi MUK dan unit kita disaring per project supaya tidak dibandingkan ribuan baris sekaligus." />
    </div>
</template>
