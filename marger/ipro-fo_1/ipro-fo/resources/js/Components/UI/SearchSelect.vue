<script setup lang="ts">
/**
 * Searchable single-select combobox.
 * Ketik untuk memfilter opsi berdasarkan kode / nama (mis. nama bank atau nama CoA).
 * Opsi: { id, code?, name?, label? }. Nilai yang di-emit = option[optionValue] (default 'id').
 *
 * #59: panel dropdown di-teleport ke <body> & diposisikan fixed agar TIDAK terpotong
 * oleh kontainer ber-overflow (mis. tabel overflow-x-auto / card).
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { ChevronsUpDown, Check, Search, X } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    modelValue: any;
    options: Array<any>;
    placeholder?: string;
    disabled?: boolean;
    clearable?: boolean;
    optionValue?: string;
    noTruncate?: boolean;
}>(), {
    placeholder: '— pilih —',
    disabled: false,
    clearable: true,
    optionValue: 'id',
    noTruncate: false,
});

const emit = defineEmits<{ (e: 'update:modelValue', v: any): void }>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const activeIndex = ref(-1);
const pos = ref({ left: 0, top: 0, width: 0 });

function labelOf(o: any): string {
    if (o == null) return '';
    if (o.label) return String(o.label);
    const code = o.code != null ? String(o.code) : '';
    const name = o.name != null ? String(o.name) : '';
    return code && name ? `${code} - ${name}` : (code || name);
}
function valueOf(o: any) { return o?.[props.optionValue]; }
const sameVal = (a: any, b: any) => a != null && b != null && a !== '' && String(a) === String(b);

const selected = computed(() => props.options.find((o) => sameVal(valueOf(o), props.modelValue)) ?? null);
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => labelOf(o).toLowerCase().includes(q)) : props.options;
});

function updatePos() {
    const btn = trigger.value;
    if (!btn) return;
    const r = btn.getBoundingClientRect();
    const ph = panel.value?.offsetHeight ?? 300;
    const spaceBelow = window.innerHeight - r.bottom;
    const openUp = spaceBelow < ph + 8 && r.top > spaceBelow;
    // Panel minimal 260px agar opsi (mis. "CC-FIN - Finance", "20101010101 - Utang Usaha Supplier")
    // tak terpotong walau kolom triggernya sempit; lalu geser kiri bila melewati tepi kanan viewport.
    const width = Math.min(Math.max(r.width, 260), window.innerWidth - 16);
    const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
    pos.value = { left, top: openUp ? Math.max(4, r.top - ph - 4) : r.bottom + 4, width };
}
function onViewportChange() { if (open.value) updatePos(); }

function openMenu() {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    activeIndex.value = Math.max(0, filtered.value.findIndex((o) => sameVal(valueOf(o), props.modelValue)));
    nextTick(() => { updatePos(); nextTick(updatePos); searchInput.value?.focus(); });
    window.addEventListener('scroll', onViewportChange, true);
    window.addEventListener('resize', onViewportChange);
}
function closeMenu() {
    open.value = false;
    activeIndex.value = -1;
    window.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
}
function toggle() { open.value ? closeMenu() : openMenu(); }
function choose(o: any) { emit('update:modelValue', valueOf(o)); closeMenu(); }
function clear() { emit('update:modelValue', ''); }

function onKeydown(e: KeyboardEvent) {
    if (!open.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { openMenu(); e.preventDefault(); }
        return;
    }
    if (e.key === 'ArrowDown') { activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { activeIndex.value = Math.max(activeIndex.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { if (filtered.value[activeIndex.value]) choose(filtered.value[activeIndex.value]); e.preventDefault(); }
    else if (e.key === 'Escape') { closeMenu(); }
}

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
watch(filtered, () => { if (activeIndex.value > filtered.value.length - 1) activeIndex.value = filtered.value.length - 1; });
</script>

<template>
    <div ref="root" class="relative">
        <button
            ref="trigger"
            type="button" :disabled="disabled" @click="toggle" @keydown="onKeydown"
            class="flex min-h-9 w-full items-center justify-between rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900"
            :class="open ? 'border-primary-400 ring-2 ring-primary-300/50' : ''"
        >
            <span :class="['min-w-0', noTruncate ? 'whitespace-normal break-words' : 'truncate', selected ? '' : 'text-neutral-400']">{{ selected ? labelOf(selected) : placeholder }}</span>
            <span class="ml-2 flex shrink-0 items-center gap-1">
                <X v-if="clearable && selected && !disabled" class="h-3.5 w-3.5 text-neutral-400 hover:text-danger-500" @click.stop="clear" />
                <ChevronsUpDown class="h-4 w-4 text-neutral-400" />
            </span>
        </button>

        <Teleport to="body">
            <div
                v-if="open" ref="panel"
                class="fixed z-[100] overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="{ left: pos.left + 'px', top: pos.top + 'px', width: pos.width + 'px' }"
            >
                <div class="relative border-b border-neutral-100 p-2 dark:border-neutral-800">
                    <Search class="pointer-events-none absolute left-4 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" />
                    <input
                        ref="searchInput" v-model="query" type="text" placeholder="Ketik nama / kode…"
                        class="h-8 w-full rounded-md border border-neutral-200 bg-neutral-50 pl-8 pr-2 text-sm outline-none focus:border-primary-400 dark:border-neutral-700 dark:bg-neutral-800"
                        @keydown="onKeydown"
                    />
                </div>
                <!-- Berapa pilihan yang ADA di balik panel ini.
                     Panelnya hanya setinggi enam baris dan terbuka pada urutan aslinya, jadi daftar
                     panjang terbaca seolah pendek: 265 akun CoA tampil sebagai lima akun kas & bank
                     yang kebetulan berkode paling kecil. Satu baris hitungan menjawab pertanyaan
                     "apakah akun saya ada di sini?" tanpa memaksa orang menggulir seluruh daftar. -->
                <div v-if="options.length > 8" class="border-b border-neutral-100 px-3 py-1 text-[11px] text-neutral-400 dark:border-neutral-800">
                    <template v-if="query.trim()">{{ filtered.length }} dari {{ options.length }} pilihan</template>
                    <template v-else>{{ options.length }} pilihan — ketik untuk menyaring, gulir untuk melihat semuanya</template>
                </div>
                <ul class="max-h-60 overflow-y-auto py-1 text-sm">
                    <li v-if="!filtered.length" class="px-3 py-2 text-center text-neutral-400">Tidak ada hasil</li>
                    <li
                        v-for="(o, i) in filtered" :key="valueOf(o)"
                        @mouseenter="activeIndex = i" @click="choose(o)"
                        class="flex cursor-pointer items-center justify-between px-3 py-1.5"
                        :class="[i === activeIndex ? 'bg-primary-50 dark:bg-primary-900/30' : '', sameVal(valueOf(o), modelValue) ? 'font-medium text-primary-700 dark:text-primary-300' : '']"
                    >
                        <span :class="noTruncate ? 'whitespace-normal break-words' : 'truncate'">{{ labelOf(o) }}</span>
                        <Check v-if="sameVal(valueOf(o), modelValue)" class="h-3.5 w-3.5 shrink-0 text-primary-600" />
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
