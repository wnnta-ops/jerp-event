<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import RegionSelect from '@/Components/UI/RegionSelect.vue';

defineOptions({ layout: PublicLayout });

interface Opt { value: string; label: string }
interface Ref { id: number; code?: string; name: string; nup_amount?: number }

const props = defineProps<{
    options: {
        schemes: Ref[];
        projects: Ref[];
        data_sources: Ref[];
        provinces: string[];
        unit_preferences: string[];
        marital_statuses: Opt[];
        cara_bayar: string[];
    };
    xendit_enabled: boolean;
}>();

const form = useForm({
    nup_scheme_id: props.options.schemes[0]?.id ?? null,
    project_id: props.options.projects[0]?.id ?? null,
    data_source_id: null as number | null,
    full_name: '', id_number: '', birth_place: '', dob: '', gender: 'M',
    marital_status: '', email: '', phone: '', phone_alt: '', occupation: '', npwp: '',
    address: '', rt_rw: '', province: '', city: '', kecamatan: '', kelurahan: '',
    preferensi_unit: [] as string[],
    pilihan_unit_1: '', pilihan_unit_2: '', pilihan_unit_3: '',
    cara_bayar: '', jenis_nup: '',
    sales_name: '', sales_phone: '',
    spouse: { full_name: '', id_number: '', phone: '', email: '', birth_place: '', dob: '', occupation: '', address: '' },
    mailing: { same_as_ktp: true, address: '', rt_rw: '', province: '', city: '', kecamatan: '', kelurahan: '' },
    ktp_file: null as File | null,
    npwp_file: null as File | null,
    disclaimer: false,
});

const selectedScheme = computed(() => props.options.schemes.find((s) => s.id === form.nup_scheme_id));
const isMarried = computed(() => form.marital_status === 'menikah');

function rupiah(v: number | undefined) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0));
}
function onFile(e: Event, key: 'ktp_file' | 'npwp_file') {
    form[key] = (e.target as HTMLInputElement).files?.[0] ?? null;
}
function submit() {
    form.post(route('nup.public.store'), { forceFormData: true });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-bold">Pendaftaran NUP (Nomor Urut Pemilihan)</h1>
            <p class="text-sm text-neutral-500 mt-1">Lengkapi data diri pemesan. Data ini dipakai pada tahapan selanjutnya.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Skema & Project -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Skema &amp; Project</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">Skema NUP <span class="text-danger-500">*</span></label>
                        <select v-model="form.nup_scheme_id" class="input-base" required>
                            <option v-for="s in options.schemes" :key="s.id" :value="s.id">{{ s.name }} — {{ rupiah(s.nup_amount) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">Project</label>
                        <select v-model="form.project_id" class="input-base">
                            <option :value="null">— pilih project —</option>
                            <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">Sumber Data</label>
                        <select v-model="form.data_source_id" class="input-base">
                            <option :value="null">— pilih sumber —</option>
                            <option v-for="d in options.data_sources" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Data Pemesan -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Data Pemesan</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.full_name" label="Nama Lengkap (sesuai KTP)" required :error="form.errors.full_name" />
                    <Input v-model="form.id_number" label="Nomor KTP" required :error="form.errors.id_number" />
                    <Input v-model="form.birth_place" label="Tempat Lahir" required :error="form.errors.birth_place" />
                    <Input v-model="form.dob" label="Tanggal Lahir" type="date" required :error="form.errors.dob" />
                    <div>
                        <label class="label-base">Jenis Kelamin</label>
                        <div class="flex gap-4 mt-2 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" v-model="form.gender" value="M" /> Laki-laki</label>
                            <label class="flex items-center gap-2"><input type="radio" v-model="form.gender" value="F" /> Perempuan</label>
                        </div>
                    </div>
                    <div>
                        <label class="label-base">Marital Status <span class="text-danger-500">*</span></label>
                        <select v-model="form.marital_status" class="input-base" required>
                            <option value="">— pilih —</option>
                            <option v-for="m in options.marital_statuses" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                        <p v-if="form.errors.marital_status" class="text-xs text-danger-500 mt-1">{{ form.errors.marital_status }}</p>
                    </div>
                    <Input v-model="form.email" label="Email Aktif" type="email" required :error="form.errors.email" />
                    <Input v-model="form.phone" label="Nomor HP" placeholder="+62..." required :error="form.errors.phone" />
                    <Input v-model="form.phone_alt" label="Nomor Tambahan" placeholder="+62..." />
                    <Input v-model="form.occupation" label="Pekerjaan" required :error="form.errors.occupation" />
                    <Input v-model="form.npwp" label="NPWP" placeholder="00.000.000.0-000.000" />
                </div>

                <h3 class="font-medium text-sm mt-5 mb-2 text-neutral-600 dark:text-neutral-300">Alamat KTP</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                    <div class="md:col-span-2"><Input v-model="form.address" label="Alamat" required :error="form.errors.address" /></div>
                    <Input v-model="form.rt_rw" label="RT/RW" placeholder="001/002" />
                </div>
                <RegionSelect
                    required
                    @update:province="form.province = $event"
                    @update:city="form.city = $event"
                    @update:kecamatan="form.kecamatan = $event"
                    @update:kelurahan="form.kelurahan = $event"
                />
                <div class="text-xs text-danger-500 mt-1 space-x-3">
                    <span v-if="form.errors.province">{{ form.errors.province }}</span>
                    <span v-if="form.errors.city">{{ form.errors.city }}</span>
                    <span v-if="form.errors.kecamatan">{{ form.errors.kecamatan }}</span>
                    <span v-if="form.errors.kelurahan">{{ form.errors.kelurahan }}</span>
                </div>
            </div>

            <!-- Data Pasangan -->
            <div class="card p-5" v-if="isMarried">
                <h2 class="font-semibold mb-3">Data Pasangan</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.spouse.full_name" label="Nama Lengkap Pasangan" />
                    <Input v-model="form.spouse.id_number" label="Nomor KTP Pasangan" />
                    <Input v-model="form.spouse.phone" label="Nomor HP" placeholder="+62..." />
                    <Input v-model="form.spouse.email" label="Email Aktif" type="email" />
                    <Input v-model="form.spouse.birth_place" label="Tempat Lahir" />
                    <Input v-model="form.spouse.dob" label="Tanggal Lahir" type="date" />
                    <Input v-model="form.spouse.occupation" label="Pekerjaan" />
                    <div class="md:col-span-2"><Input v-model="form.spouse.address" label="Alamat KTP Pasangan" /></div>
                </div>
            </div>

            <!-- Surat Menyurat -->
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Alamat Surat Menyurat</h2>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.mailing.same_as_ktp" class="rounded" /> Sama dengan alamat KTP
                    </label>
                </div>
                <div v-if="!form.mailing.same_as_ktp" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="md:col-span-2"><Input v-model="form.mailing.address" label="Alamat" /></div>
                    <Input v-model="form.mailing.rt_rw" label="RT/RW" />
                    <div>
                        <label class="label-base">Provinsi</label>
                        <select v-model="form.mailing.province" class="input-base">
                            <option value="">— pilih —</option>
                            <option v-for="p in options.provinces" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <Input v-model="form.mailing.city" label="Kabupaten / Kota" />
                    <Input v-model="form.mailing.kecamatan" label="Kecamatan" />
                    <Input v-model="form.mailing.kelurahan" label="Kelurahan" />
                </div>
                <p v-else class="text-sm text-neutral-400">Memakai alamat KTP di atas.</p>
            </div>

            <!-- Preferensi Unit -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Preferensi Unit <span class="text-danger-500">*</span></h2>
                <div class="flex flex-wrap gap-3">
                    <label v-for="u in options.unit_preferences" :key="u"
                           class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer"
                           :class="form.preferensi_unit.includes(u) ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'">
                        <input type="checkbox" :value="u" v-model="form.preferensi_unit" class="rounded" /> {{ u }}
                    </label>
                </div>
                <p v-if="form.errors.preferensi_unit" class="text-xs text-danger-500 mt-1">{{ form.errors.preferensi_unit }}</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                    <Input v-model="form.pilihan_unit_1" label="Pilihan Unit 1" />
                    <Input v-model="form.pilihan_unit_2" label="Pilihan Unit 2" />
                    <Input v-model="form.pilihan_unit_3" label="Pilihan Unit 3" />
                </div>
                <p class="text-xs text-neutral-400 mt-2">*Preferensi unit hanya untuk kebutuhan database internal perusahaan.</p>
            </div>

            <!-- Sales & Cara Bayar -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Sales &amp; Cara Bayar</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.sales_name" label="Sales / Agen" placeholder="Nama Sales - Nama Agen Properti" />
                    <Input v-model="form.sales_phone" label="No. Telp Sales" placeholder="+62..." />
                    <Input v-model="form.jenis_nup" label="Jenis Tipe NUP" />
                    <div>
                        <label class="label-base">Cara Bayar <span class="text-danger-500">*</span></label>
                        <div class="flex gap-4 mt-2 text-sm">
                            <label v-for="c in options.cara_bayar" :key="c" class="flex items-center gap-2">
                                <input type="radio" v-model="form.cara_bayar" :value="c" /> {{ c }}
                            </label>
                        </div>
                        <p v-if="form.errors.cara_bayar" class="text-xs text-danger-500 mt-1">{{ form.errors.cara_bayar }}</p>
                    </div>
                </div>
            </div>

            <!-- Upload -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3">Dokumen (opsional)</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">Upload KTP (maks 5MB)</label>
                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="input-base" @change="onFile($event, 'ktp_file')" />
                        <p v-if="form.errors.ktp_file" class="text-xs text-danger-500 mt-1">{{ form.errors.ktp_file }}</p>
                    </div>
                    <div>
                        <label class="label-base">Upload NPWP (maks 5MB)</label>
                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="input-base" @change="onFile($event, 'npwp_file')" />
                        <p v-if="form.errors.npwp_file" class="text-xs text-danger-500 mt-1">{{ form.errors.npwp_file }}</p>
                    </div>
                </div>
            </div>

            <!-- Pembayaran + disclaimer -->
            <div class="card p-5">
                <div class="flex items-center justify-between rounded-lg bg-primary-50 dark:bg-primary-900/20 px-4 py-3 mb-4">
                    <div>
                        <p class="text-sm text-neutral-600 dark:text-neutral-300">Nominal NUP yang harus dibayar</p>
                        <p class="text-xl font-extrabold text-primary-700 dark:text-primary-300">{{ rupiah(selectedScheme?.nup_amount) }}</p>
                    </div>
                    <span class="text-xs px-2 py-1 rounded-full" :class="xendit_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                        {{ xendit_enabled ? 'Bayar via Xendit' : 'Mode Simulasi' }}
                    </span>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" v-model="form.disclaimer" class="rounded mt-0.5" />
                    <span>Saya menyatakan bahwa data yang saya berikan adalah data valid dan dapat dipertanggungjawabkan.</span>
                </label>
                <p v-if="form.errors.disclaimer" class="text-xs text-danger-500 mt-1">{{ form.errors.disclaimer }}</p>
            </div>

            <div class="flex justify-end pb-4">
                <Button type="submit" :loading="form.processing" size="lg">Daftar &amp; Lanjut Bayar →</Button>
            </div>
        </form>
    </div>
</template>
