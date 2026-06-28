<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import AddressFields from '@/Components/UI/AddressFields.vue';

interface Option { value: string; label: string }
interface RefItem { id: number; code?: string; name: string }

const props = defineProps<{
    customer: Record<string, any>;
    options: {
        projects: RefItem[];
        data_sources: RefItem[];
        citizenships: Option[];
        religions: Option[];
        marital_statuses: Option[];
        emergency_relationships: Option[];
    };
}>();

const c = props.customer;
const s = (v: any) => (v === null || v === undefined ? '' : String(v));

const form = useForm({
    // 1. Identitas
    project_id: c.project_id ?? null,
    data_source_id: c.data_source_id ?? null,
    full_name: s(c.full_name),
    nationality: c.nationality || 'WNI',

    // 2. Kartu Identitas
    id_type: c.id_type || 'KTP',
    id_number: s(c.id_number),
    id_expires_at: s(c.id_expires_at),
    id_lifetime: !!c.id_lifetime,
    address_line: s(c.address_line),
    address_rt_rw: s(c.address_rt_rw),
    kelurahan: s(c.kelurahan),
    kecamatan: s(c.kecamatan),
    city: s(c.city),
    province: s(c.province),
    postal_code: s(c.postal_code),

    // 3. Data Pribadi
    dob: s(c.dob),
    birth_place: s(c.birth_place),
    gender: c.gender || 'M',
    religion: s(c.religion),
    marital_status: s(c.marital_status),

    // 4. NPWP
    npwp: s(c.npwp),
    npwp_name: s(c.npwp_name),
    npwp_same_as_ktp: !!c.npwp_same_as_ktp,
    npwp_address: s(c.npwp_address),
    npwp_rt_rw: s(c.npwp_rt_rw),
    npwp_kelurahan: s(c.npwp_kelurahan),
    npwp_kecamatan: s(c.npwp_kecamatan),
    npwp_kota: s(c.npwp_kota),
    npwp_postal_code: s(c.npwp_postal_code),

    // 5. Kontak
    phone: s(c.phone),
    phone_alt: s(c.phone_alt),
    home_phone: s(c.home_phone),
    fax: s(c.fax),
    email: s(c.email),

    // 6. Surat Menyurat
    mailing_same_as_ktp: !!c.mailing_same_as_ktp,
    mailing_address: s(c.mailing_address),
    mailing_rt_rw: s(c.mailing_rt_rw),
    mailing_kelurahan: s(c.mailing_kelurahan),
    mailing_kecamatan: s(c.mailing_kecamatan),
    mailing_kota: s(c.mailing_kota),
    mailing_postal_code: s(c.mailing_postal_code),

    // 7. Pekerjaan
    occupation: s(c.occupation),
    employer_name: s(c.employer_name),
    job_title: s(c.job_title),
    monthly_income: s(c.monthly_income),
    office_phone: s(c.office_phone),
    office_address: s(c.office_address),
    office_rt_rw: s(c.office_rt_rw),
    office_kelurahan: s(c.office_kelurahan),
    office_kecamatan: s(c.office_kecamatan),
    office_kota: s(c.office_kota),
    office_postal_code: s(c.office_postal_code),

    // 8. Emergency Contact
    emergency_name: s(c.emergency_name),
    emergency_relationship: s(c.emergency_relationship),
    emergency_phone: s(c.emergency_phone),
    emergency_email: s(c.emergency_email),
});

const ktpAddress = computed(() => ({
    address: form.address_line,
    rt_rw: form.address_rt_rw,
    kelurahan: form.kelurahan,
    kecamatan: form.kecamatan,
    kota: form.city,
    postal_code: form.postal_code,
}));

function updateKtpAddress(v: any) {
    form.address_line = v.address ?? '';
    form.address_rt_rw = v.rt_rw ?? '';
    form.kelurahan = v.kelurahan ?? '';
    form.kecamatan = v.kecamatan ?? '';
    form.city = v.kota ?? '';
    form.postal_code = v.postal_code ?? '';
}

function npwpAddress() {
    return {
        address: form.npwp_address,
        rt_rw: form.npwp_rt_rw,
        kelurahan: form.npwp_kelurahan,
        kecamatan: form.npwp_kecamatan,
        kota: form.npwp_kota,
        postal_code: form.npwp_postal_code,
    };
}
function updateNpwpAddress(v: any) {
    form.npwp_address = v.address ?? '';
    form.npwp_rt_rw = v.rt_rw ?? '';
    form.npwp_kelurahan = v.kelurahan ?? '';
    form.npwp_kecamatan = v.kecamatan ?? '';
    form.npwp_kota = v.kota ?? '';
    form.npwp_postal_code = v.postal_code ?? '';
}

// "Sama dengan KTP" pada NPWP → Nama NPWP ikut Nama Lengkap (KTP) & No. NPWP ikut No Identitas (KTP).
watch(
    () => [form.npwp_same_as_ktp, form.full_name, form.id_number],
    ([same]) => {
        if (same) {
            form.npwp_name = form.full_name;
            form.npwp = form.id_number;
        }
    },
);

function mailingAddress() {
    return {
        address: form.mailing_address,
        rt_rw: form.mailing_rt_rw,
        kelurahan: form.mailing_kelurahan,
        kecamatan: form.mailing_kecamatan,
        kota: form.mailing_kota,
        postal_code: form.mailing_postal_code,
    };
}
function updateMailingAddress(v: any) {
    form.mailing_address = v.address ?? '';
    form.mailing_rt_rw = v.rt_rw ?? '';
    form.mailing_kelurahan = v.kelurahan ?? '';
    form.mailing_kecamatan = v.kecamatan ?? '';
    form.mailing_kota = v.kota ?? '';
    form.mailing_postal_code = v.postal_code ?? '';
}

function officeAddress() {
    return {
        address: form.office_address,
        rt_rw: form.office_rt_rw,
        kelurahan: form.office_kelurahan,
        kecamatan: form.office_kecamatan,
        kota: form.office_kota,
        postal_code: form.office_postal_code,
    };
}
function updateOfficeAddress(v: any) {
    form.office_address = v.address ?? '';
    form.office_rt_rw = v.rt_rw ?? '';
    form.office_kelurahan = v.kelurahan ?? '';
    form.office_kecamatan = v.kecamatan ?? '';
    form.office_kota = v.kota ?? '';
    form.office_postal_code = v.postal_code ?? '';
}

function submit() {
    form.put(route('customers.update', c.id), { preserveScroll: true });
}
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-4">
        <div>
            <Link :href="route('customers.show', c.id)" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali ke detail</Link>
            <h1 class="text-2xl font-bold mt-2">Edit Customer</h1>
            <p class="text-sm text-neutral-500 mt-1 font-mono">{{ c.code }} · {{ c.full_name }}</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- ── SECTION 1: Identitas ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">1. Identitas</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">Project</label>
                        <select v-model="form.project_id" class="input-base">
                            <option :value="null">— pilih project —</option>
                            <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">Sumber Data (Marketing Channel)</label>
                        <select v-model="form.data_source_id" class="input-base">
                            <option :value="null">— pilih sumber —</option>
                            <option v-for="ds in options.data_sources" :key="ds.id" :value="ds.id">{{ ds.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">Kewarganegaraan</label>
                        <div class="flex gap-4 mt-2">
                            <label v-for="ct in options.citizenships" :key="ct.value" class="flex items-center gap-2 text-sm">
                                <input type="radio" v-model="form.nationality" :value="ct.value" />
                                <span>{{ ct.value }}</span>
                            </label>
                        </div>
                    </div>
                    <Input v-model="form.full_name" label="Nama Lengkap (sesuai KTP)" required :error="form.errors.full_name" />
                </div>
            </div>

            <!-- ── SECTION 2: Kartu Identitas ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">2. Kartu Identitas</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                    <div>
                        <label class="label-base">Jenis Identitas <span class="text-danger-500">*</span></label>
                        <select v-model="form.id_type" class="input-base">
                            <option value="KTP">KTP</option>
                            <option value="Passport">Paspor</option>
                            <option value="SIM">SIM</option>
                        </select>
                    </div>
                    <Input v-model="form.id_number" label="No Identitas" required :error="form.errors.id_number" />
                    <div class="space-y-1">
                        <label class="label-base">Tanggal KTP Berakhir</label>
                        <Input v-model="form.id_expires_at" type="date" :disabled="form.id_lifetime" />
                        <label class="flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-300">
                            <input v-model="form.id_lifetime" type="checkbox" class="rounded" />
                            <span>Seumur Hidup</span>
                        </label>
                    </div>
                </div>

                <AddressFields
                    label="Alamat KTP"
                    :model-value="ktpAddress"
                    @update:model-value="updateKtpAddress"
                />
                <div class="grid grid-cols-2 gap-3 mt-3">
                    <Input v-model="form.province" label="Provinsi" />
                </div>
            </div>

            <!-- ── SECTION 3: Data Pribadi ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">3. Data Pribadi</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.birth_place" label="Tempat Lahir" />
                    <Input v-model="form.dob" label="Tanggal Lahir" type="date" :error="form.errors.dob" />
                    <div>
                        <label class="label-base">Jenis Kelamin</label>
                        <div class="flex gap-4 mt-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" v-model="form.gender" value="M" />
                                <span>Laki-laki</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" v-model="form.gender" value="F" />
                                <span>Perempuan</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="label-base">Agama</label>
                        <select v-model="form.religion" class="input-base">
                            <option value="">— pilih —</option>
                            <option v-for="r in options.religions" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">Status</label>
                        <select v-model="form.marital_status" class="input-base">
                            <option value="">— pilih —</option>
                            <option v-for="m in options.marital_statuses" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── SECTION 4: Data NPWP ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">4. Data NPWP</h2>
                <AddressFields
                    label="Alamat NPWP"
                    cloneable
                    clone-label="Sama dengan KTP"
                    :model-value="npwpAddress()"
                    :same-as-source="form.npwp_same_as_ktp"
                    :source-address="ktpAddress"
                    @update:model-value="updateNpwpAddress"
                    @update:same-as-source="(v) => form.npwp_same_as_ktp = v"
                />
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                    <Input v-model="form.npwp_name" label="Nama NPWP" :disabled="form.npwp_same_as_ktp" />
                    <Input v-model="form.npwp" label="No. NPWP" placeholder="00.000.000.0-000.000" :disabled="form.npwp_same_as_ktp" />
                </div>
                <p v-if="form.npwp_same_as_ktp" class="mt-1.5 text-[11px] text-neutral-400">Nama &amp; No. NPWP otomatis mengikuti Nama Lengkap &amp; No Identitas (KTP). Hapus centang "Sama dengan KTP" untuk mengisi manual.</p>
            </div>

            <!-- ── SECTION 5: Kontak ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">5. Kontak</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.phone" label="No HP 1 (utama, untuk SMS)" placeholder="+62..." :error="form.errors.phone" />
                    <Input v-model="form.phone_alt" label="No HP 2 (alt.)" placeholder="+62..." />
                    <Input v-model="form.home_phone" label="No Telepon Rumah" />
                    <Input v-model="form.fax" label="No Fax" />
                    <Input v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                </div>
            </div>

            <!-- ── SECTION 6: Alamat Surat Menyurat ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">6. Alamat Surat Menyurat</h2>
                <AddressFields
                    cloneable
                    clone-label="Sama dengan KTP"
                    :model-value="mailingAddress()"
                    :same-as-source="form.mailing_same_as_ktp"
                    :source-address="ktpAddress"
                    @update:model-value="updateMailingAddress"
                    @update:same-as-source="(v) => form.mailing_same_as_ktp = v"
                />
            </div>

            <!-- ── SECTION 7: Pekerjaan ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">7. Data Pekerjaan</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                    <Input v-model="form.occupation" label="Pekerjaan" />
                    <Input v-model="form.employer_name" label="Perusahaan" />
                    <Input v-model="form.job_title" label="Jabatan" />
                    <Input v-model="form.monthly_income" label="Pendapatan Bulanan (Rp)" type="number" />
                    <Input v-model="form.office_phone" label="No Telepon Kantor" />
                </div>
                <AddressFields
                    label="Alamat Kantor"
                    :model-value="officeAddress()"
                    @update:model-value="updateOfficeAddress"
                />
            </div>

            <!-- ── SECTION 8: Emergency Contact ── -->
            <div class="card p-5">
                <h2 class="font-semibold mb-3 text-neutral-700 dark:text-neutral-200">8. Orang Yang Dapat Dihubungi</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <Input v-model="form.emergency_name" label="Nama" />
                    <div>
                        <label class="label-base">Hubungan</label>
                        <select v-model="form.emergency_relationship" class="input-base">
                            <option value="">— pilih —</option>
                            <option v-for="r in options.emergency_relationships" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                    <Input v-model="form.emergency_phone" label="No HP" />
                    <Input v-model="form.emergency_email" label="Email" type="email" />
                </div>
            </div>

            <!-- ── Footer Actions ── -->
            <div class="card p-4 flex gap-3 justify-end sticky bottom-0">
                <Link :href="route('customers.show', c.id)">
                    <Button variant="secondary" type="button">Batal</Button>
                </Link>
                <Button type="submit" :loading="form.processing">Simpan Perubahan</Button>
            </div>
        </form>
    </div>
</template>
