<!-- Employee registration form: NIK, HR department, cost center, payroll & bank details. -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, UserPlus, Save } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{
  costCenters: Array<any>;
  departments: Array<any>;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  nik: '',
  name: '',
  position: '',
  department_id: '',
  cost_center_id: '',
  join_date: today,
  base_salary: 0,
  allowance: 0,
  bank_name: '',
  bank_account: '',
  is_active: true,
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.employees.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.employees.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Data Center
    </Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold">
      <UserPlus class="h-6 w-6 text-primary-600" /> Registrasi Karyawan
    </h1>

    <form @submit.prevent="submit" class="space-y-4">
      <div class="card">
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label class="mb-1 block text-sm font-medium">NIK</label>
            <input v-model="form.nik" type="text" :class="inputCls" />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" :class="inputCls" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Jabatan</label>
            <input v-model="form.position" type="text" :class="inputCls" />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">HR Department</label>
            <select v-model="form.department_id" :class="inputCls">
              <option value="">— Pilih —</option>
              <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code }} - {{ d.name }}</option>
            </select>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Cost Center / Divisi</label>
            <select v-model="form.cost_center_id" :class="inputCls">
              <option value="">— Pilih —</option>
              <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
            </select>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Tgl Masuk</label>
            <input v-model="form.join_date" type="date" :class="inputCls" />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Gaji Pokok</label>
            <input v-model.number="form.base_salary" type="number" min="0" :class="inputCls" />
            <p class="mt-1 text-xs text-neutral-500">{{ fmtRp(form.base_salary) }}</p>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Tunjangan</label>
            <input v-model.number="form.allowance" type="number" min="0" :class="inputCls" />
            <p class="mt-1 text-xs text-neutral-500">{{ fmtRp(form.allowance) }}</p>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">Bank</label>
            <input v-model="form.bank_name" type="text" :class="inputCls" />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium">No. Rekening</label>
            <input v-model="form.bank_account" type="text" :class="inputCls" />
          </div>

          <div class="flex items-center gap-2 md:col-span-2">
            <input id="is_active" v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-neutral-300 dark:border-neutral-700" />
            <label for="is_active" class="text-sm font-medium">Aktif</label>
          </div>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <Link :href="route('bs.employees.index')">
          <Button type="button" variant="secondary">Batal</Button>
        </Link>
        <Button type="submit" :disabled="form.processing">
          <Save class="h-4 w-4" /> Simpan Karyawan
        </Button>
      </div>
    </form>
  </div>
</template>
