<!-- Cost of Good Sold — Setup: Budget (master CRUD + year filter) -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, PieChart, Plus, Pencil, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  year: number;
  rows: Array<any>;
  projects: Array<any>;
  locations: Array<any>;
  categories: Array<any>;
  accounts: Array<any>;
  total: number;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const year = ref<number>(props.year);
function applyFilter(){ router.get(route('bs.cogs.budget.index'),{year:year.value},{preserveState:true,replace:true}); }

function projName(id:number){ const p = props.projects.find(x=>x.id===id); return p ? p.name : '—'; }

const showModal = ref(false);
const editingId = ref<number|null>(null);
const form = useForm({
  year: props.year,
  project_id: '' as number|'',
  location_id: '' as number|'',
  category_id: '' as number|'',
  account_id: '' as number|'',
  amount: 0,
  notes: '',
});

function openCreate(){
  editingId.value=null;
  form.reset();
  form.year=props.year;
  showModal.value=true;
}
function openEdit(row:any){
  editingId.value=row.id;
  form.year=props.year;
  form.project_id=row.project_id;
  form.location_id=row.location_id ?? '';
  form.category_id=row.category_id ?? (row.category?.id ?? '');
  form.account_id=row.account_id ?? (row.account?.id ?? '');
  form.amount=row.amount;
  form.notes=row.notes ?? '';
  showModal.value=true;
}
function submit(){
  const o={onSuccess:()=>{showModal.value=false;}};
  editingId.value
    ? form.put(route('bs.cogs.budget.update',editingId.value),o)
    : form.post(route('bs.cogs.budget.store'),o);
}
function destroy(id:number){
  if(confirm('Hapus?')) router.delete(route('bs.cogs.budget.destroy',id),{preserveScroll:true});
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><PieChart class="h-6 w-6 text-primary-600" /> Budget</h1>
      <div class="flex items-end gap-2">
        <div>
          <label class="mb-1 block text-xs text-neutral-500">Tahun</label>
          <input v-model.number="year" type="number" :class="inputCls" class="!w-28" @keyup.enter="applyFilter" />
        </div>
        <Button variant="secondary" @click="applyFilter">Tampilkan</Button>
        <Button @click="openCreate"><Plus class="h-4 w-4" /> Tambah Budget</Button>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Cluster/Lokasi</th>
            <th class="px-3 py-2">Kategori</th>
            <th class="px-3 py-2">Komponen</th>
            <th class="px-3 py-2">COA</th>
            <th class="px-3 py-2 text-right">Alokasi</th>
            <th class="px-3 py-2 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="row in rows" :key="row.id">
            <td class="px-3 py-2">{{ projName(row.project_id) }}</td>
            <td class="px-3 py-2">{{ row.location?.name ?? 'Umum' }}</td>
            <td class="px-3 py-2">{{ row.category ? (row.category.code + ' ' + row.category.name) : '—' }}</td>
            <td class="px-3 py-2">{{ row.category?.component ?? '—' }}</td>
            <td class="px-3 py-2">{{ row.account?.code ?? '—' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(row.amount) }}</td>
            <td class="px-3 py-2">
              <div class="flex justify-end gap-2">
                <Button variant="secondary" size="sm" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
                <Button variant="danger" size="sm" @click="destroy(row.id)"><Trash2 class="h-4 w-4" /></Button>
              </div>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="rows.length">
          <tr class="border-t border-neutral-200 font-semibold dark:border-neutral-800">
            <td class="px-3 py-2" colspan="5">Total</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(total) }}</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      <EmptyState v-if="!rows.length" title="Belum ada budget" description="Tambahkan alokasi budget COGS untuk tahun ini." />
    </div>

    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal=false">
        <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
          <h2 class="mb-4 text-lg font-semibold">{{ editingId ? 'Edit Budget' : 'Tambah Budget' }}</h2>
          <form class="space-y-3" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="mb-1 block text-xs text-neutral-500">Tahun</label>
                <input v-model.number="form.year" type="number" :class="inputCls" />
              </div>
              <div>
                <label class="mb-1 block text-xs text-neutral-500">Project *</label>
                <select v-model="form.project_id" :class="inputCls">
                  <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </div>
            </div>
            <div>
              <label class="mb-1 block text-xs text-neutral-500">Cluster/Lokasi</label>
              <select v-model="form.location_id" :class="inputCls">
                <option value="">—</option>
                <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs text-neutral-500">Kategori *</label>
              <select v-model="form.category_id" :class="inputCls">
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs text-neutral-500">COA</label>
              <select v-model="form.account_id" :class="inputCls">
                <option value="">—</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs text-neutral-500">Alokasi *</label>
              <input v-model.number="form.amount" type="number" :class="inputCls" />
            </div>
            <div>
              <label class="mb-1 block text-xs text-neutral-500">Catatan</label>
              <input v-model="form.notes" type="text" :class="inputCls" />
            </div>
            <div class="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" @click="showModal=false">Batal</Button>
              <Button type="submit" :disabled="form.processing">Simpan</Button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>
