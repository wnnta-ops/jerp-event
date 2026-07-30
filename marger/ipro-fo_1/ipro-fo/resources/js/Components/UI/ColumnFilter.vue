<script setup lang="ts">
/**
 * Filter kolom ala Excel AutoFilter — dropdown berisi daftar nilai unik yang ADA pada kolom,
 * tiap nilai punya checkbox. modelValue = array nilai yang dicentang (kosong = tampilkan semua).
 * Panel di-teleport ke <body> & diposisikan fixed agar tidak terpotong kontainer overflow.
 */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { ChevronDown, Search, Check } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    modelValue: string[];
    options: string[];          // nilai unik yang ada (sudah dalam bentuk tampilan)
    placeholder?: string;
    align?: 'left' | 'right';
    /**
     * Filter KETIK (berisi/contains) untuk kolom ini. OPSIONAL & opt-in: bila prop `text`
     * di-bind (v-model:text), pemicu berubah jadi kotak ketik + tombol panah. Bila tidak
     * di-bind, komponen berperilaku persis seperti semula (tombol dropdown checklist saja),
     * sehingga halaman lain yang memakai ColumnFilter tidak berubah.
     */
    text?: string;
}>(), { placeholder: 'Semua', align: 'left' });

const emit = defineEmits<{
    (e: 'update:modelValue', v: string[]): void;
    (e: 'update:text', v: string): void;
}>();

const typeable = computed(() => props.text !== undefined);

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLElement | null>(null);   // <button> (mode lama) atau <div> (mode ketik)
const panel = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const pos = ref({ left: 0, top: 0, width: 0 });

const selectedSet = computed(() => new Set(props.modelValue));
const active = computed(() => props.modelValue.length > 0 || Boolean(props.text));
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => (o ?? '').toLowerCase().includes(q)) : props.options;
});
const allVisibleChecked = computed(() => filtered.value.length > 0 && filtered.value.every((o) => selectedSet.value.has(o)));
const btnLabel = computed(() => {
    if (!active.value) return props.placeholder;
    if (props.modelValue.length === 1) return props.modelValue[0] === '' ? '(kosong)' : props.modelValue[0];
    return `${props.modelValue.length} dipilih`;
});

function toggleValue(v: string) {
    const set = new Set(props.modelValue);
    set.has(v) ? set.delete(v) : set.add(v);
    emit('update:modelValue', [...set]);
}
function toggleAllVisible() {
    const set = new Set(props.modelValue);
    if (allVisibleChecked.value) filtered.value.forEach((o) => set.delete(o));
    else filtered.value.forEach((o) => set.add(o));
    emit('update:modelValue', [...set]);
}
function clearAll() { emit('update:modelValue', []); if (typeable.value) emit('update:text', ''); }

function updatePos() {
    const btn = trigger.value;
    if (!btn) return;
    const r = btn.getBoundingClientRect();
    const width = Math.max(224, r.width);
    const ph = panel.value?.offsetHeight ?? 320;
    const spaceBelow = window.innerHeight - r.bottom;
    const openUp = spaceBelow < ph + 8 && r.top > spaceBelow;
    let left = props.align === 'right' ? r.right - width : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
    pos.value = { left, top: openUp ? Math.max(4, r.top - ph - 4) : r.bottom + 4, width };
}
function onViewportChange() { if (open.value) updatePos(); }
function openMenu() {
    open.value = true; query.value = '';
    nextTick(() => { updatePos(); nextTick(updatePos); searchInput.value?.focus(); });
    window.addEventListener('scroll', onViewportChange, true);
    window.addEventListener('resize', onViewportChange);
}
function closeMenu() {
    open.value = false;
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
}
function toggle() { open.value ? closeMenu() : openMenu(); }
function onClickOutside(e: MouseEvent) {
    const t = e.target as Node;
    if (root.value?.contains(t) || panel.value?.contains(t)) return;
    closeMenu();
}
onMounted(() => document.addEventListener('mousedown', onClickOutside));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onClickOutside);
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
});
</script>

<template>
    <div ref="root" class="relative">
        <!-- Mode KETIK (prop `text` di-bind): ketik untuk menyaring "berisi", panah untuk checklist. -->
        <div v-if="typeable" ref="trigger"
            class="flex h-7 w-full items-center gap-1 rounded border bg-white pl-2 pr-1 text-xs dark:bg-neutral-900"
            :class="[active ? 'border-primary-400' : 'border-neutral-300 dark:border-neutral-700', open ? 'ring-2 ring-primary-300/50' : '']">
            <input :value="text" @input="emit('update:text', ($event.target as HTMLInputElement).value)"
                :placeholder="modelValue.length ? btnLabel : placeholder" type="text"
                class="h-full w-full min-w-0 border-0 bg-transparent p-0 text-xs outline-none placeholder:text-neutral-400 focus:ring-0"
                :class="active ? 'text-primary-700 dark:text-primary-300' : 'text-neutral-600 dark:text-neutral-300'" />
            <button type="button" @click="toggle" :title="'Pilih dari daftar'" class="shrink-0 rounded p-0.5 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                <ChevronDown class="h-3.5 w-3.5" :class="active ? 'text-primary-500' : 'text-neutral-400'" />
            </button>
        </div>

        <button v-else ref="trigger" type="button" @click="toggle"
            class="flex h-7 w-full items-center justify-between gap-1 rounded border bg-white px-2 text-xs font-normal dark:bg-neutral-900"
            :class="[active ? 'border-primary-400 text-primary-700 dark:text-primary-300' : 'border-neutral-300 text-neutral-500 dark:border-neutral-700', open ? 'ring-2 ring-primary-300/50' : '']">
            <span class="truncate">{{ btnLabel }}</span>
            <ChevronDown class="h-3.5 w-3.5 shrink-0" :class="active ? 'text-primary-500' : 'text-neutral-400'" />
        </button>

        <Teleport to="body">
            <div v-if="open" ref="panel"
                class="fixed z-[100] overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="{ left: pos.left + 'px', top: pos.top + 'px', width: pos.width + 'px' }">
                <div class="relative border-b border-neutral-100 p-2 dark:border-neutral-800">
                    <Search class="pointer-events-none absolute left-4 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                    <input ref="searchInput" v-model="query" type="text" placeholder="Cari nilai…"
                        class="h-8 w-full rounded-md border border-neutral-200 bg-neutral-50 pl-8 pr-2 text-sm outline-none focus:border-primary-400 dark:border-neutral-700 dark:bg-neutral-800" />
                </div>
                <div class="flex items-center justify-between border-b border-neutral-100 px-3 py-1.5 text-xs dark:border-neutral-800">
                    <button type="button" @click="toggleAllVisible" class="font-medium text-primary-600 hover:underline">{{ allVisibleChecked ? 'Hapus semua' : 'Pilih semua' }}</button>
                    <button v-if="active" type="button" @click="clearAll" class="text-neutral-400 hover:text-danger-500">Reset</button>
                </div>
                <ul class="max-h-60 overflow-y-auto py-1 text-sm">
                    <li v-if="!filtered.length" class="px-3 py-2 text-center text-neutral-400">Tidak ada nilai</li>
                    <li v-for="o in filtered" :key="o" @click="toggleValue(o)"
                        class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-primary-50 dark:hover:bg-primary-900/30">
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                            :class="selectedSet.has(o) ? 'border-primary-500 bg-primary-500 text-white' : 'border-neutral-300 dark:border-neutral-600'">
                            <Check v-if="selectedSet.has(o)" class="h-3 w-3" />
                        </span>
                        <span class="truncate" :title="o">{{ o === '' ? '(kosong)' : o }}</span>
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
