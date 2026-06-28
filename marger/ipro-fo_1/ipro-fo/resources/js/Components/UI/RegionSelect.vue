<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';

withDefaults(defineProps<{ required?: boolean }>(), { required: false });

const emit = defineEmits<{
    (e: 'update:province', v: string): void;
    (e: 'update:city', v: string): void;
    (e: 'update:kecamatan', v: string): void;
    (e: 'update:kelurahan', v: string): void;
}>();

interface R { code: string; name: string }

const provinces = ref<R[]>([]);
const cities = ref<R[]>([]);
const districts = ref<R[]>([]);
const villages = ref<R[]>([]);

const pCode = ref('');
const cCode = ref('');
const dCode = ref('');
const vCode = ref('');

async function fetchChildren(parent: string): Promise<R[]> {
    try {
        const r = await axios.get('/regions', { params: { parent }, withCredentials: true });
        return r.data ?? [];
    } catch {
        return [];
    }
}
function nameOf(list: R[], code: string) { return list.find((x) => x.code === code)?.name ?? ''; }

onMounted(async () => { provinces.value = await fetchChildren(''); });

async function onProvince() {
    cities.value = []; districts.value = []; villages.value = [];
    cCode.value = dCode.value = vCode.value = '';
    emit('update:province', nameOf(provinces.value, pCode.value));
    emit('update:city', ''); emit('update:kecamatan', ''); emit('update:kelurahan', '');
    if (pCode.value) cities.value = await fetchChildren(pCode.value);
}
async function onCity() {
    districts.value = []; villages.value = [];
    dCode.value = vCode.value = '';
    emit('update:city', nameOf(cities.value, cCode.value));
    emit('update:kecamatan', ''); emit('update:kelurahan', '');
    if (cCode.value) districts.value = await fetchChildren(cCode.value);
}
async function onDistrict() {
    villages.value = [];
    vCode.value = '';
    emit('update:kecamatan', nameOf(districts.value, dCode.value));
    emit('update:kelurahan', '');
    if (dCode.value) villages.value = await fetchChildren(dCode.value);
}
function onVillage() {
    emit('update:kelurahan', nameOf(villages.value, vCode.value));
}
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
            <label class="label-base">Provinsi <span v-if="required" class="text-danger-500">*</span></label>
            <select v-model="pCode" @change="onProvince" class="input-base">
                <option value="">— pilih provinsi —</option>
                <option v-for="p in provinces" :key="p.code" :value="p.code">{{ p.name }}</option>
            </select>
        </div>
        <div>
            <label class="label-base">Kabupaten / Kota <span v-if="required" class="text-danger-500">*</span></label>
            <select v-model="cCode" @change="onCity" class="input-base" :disabled="!cities.length">
                <option value="">{{ cities.length ? '— pilih kab/kota —' : 'pilih provinsi dulu' }}</option>
                <option v-for="c in cities" :key="c.code" :value="c.code">{{ c.name }}</option>
            </select>
        </div>
        <div>
            <label class="label-base">Kecamatan <span v-if="required" class="text-danger-500">*</span></label>
            <select v-model="dCode" @change="onDistrict" class="input-base" :disabled="!districts.length">
                <option value="">{{ districts.length ? '— pilih kecamatan —' : 'pilih kab/kota dulu' }}</option>
                <option v-for="d in districts" :key="d.code" :value="d.code">{{ d.name }}</option>
            </select>
        </div>
        <div>
            <label class="label-base">Kelurahan / Desa <span v-if="required" class="text-danger-500">*</span></label>
            <select v-model="vCode" @change="onVillage" class="input-base" :disabled="!villages.length">
                <option value="">{{ villages.length ? '— pilih kelurahan —' : 'pilih kecamatan dulu' }}</option>
                <option v-for="v in villages" :key="v.code" :value="v.code">{{ v.name }}</option>
            </select>
        </div>
    </div>
</template>
