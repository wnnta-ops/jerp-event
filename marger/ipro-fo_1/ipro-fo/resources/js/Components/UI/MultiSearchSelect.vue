<script setup lang="ts">
/**
 * Searchable MULTI-select — pilihan tampil sebagai chip (seperti Grup Akses di J-ERP).
 * Pasangan SearchSelect: opsi { id, code?, name?, label? }, nilai = array option[optionValue].
 *
 * `utama`: chip PERTAMA ditandai ★ sebagai pilihan utama. Klik chip lain → jadi yang pertama.
 * Urutan array itulah yang dikirim, jadi pemanggil cukup membaca elemen [0].
 *
 * Panel di-teleport ke <body> & diposisikan fixed — sama dengan SearchSelect, agar tidak
 * terpotong kontainer ber-overflow (mis. modal).
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { ChevronsUpDown, Check, Search, X, Star } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    modelValue: Array<any>;
    options: Array<any>;
    placeholder?: string;
    disabled?: boolean;
    optionValue?: string;
    utama?: boolean;
}>(), {
    placeholder: '— pilih —',
    disabled: false,
    optionValue: 'id',
    utama: false,
});

const emit = defineEmits<{ (e: 'update:modelValue', v: Array<any>): void }>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const activeIndex = ref(-1);
const pos = ref({ left: 0, top: 0, width: 0 });

function labelOf(o: any): string {
    if (o == null) return '';
    if (o.label) return String(o.label);
    const code = o.code != null ? String(o.code) : '';
    const name = o.name != null ? String(o.name) : '';
    return name || code;
}
function valueOf(o: any) { return o?.[props.optionValue]; }
const nilai = computed<Array<any>>(() => props.modelValue ?? []);
const terpilih = (v: any) => nilai.value.some((x) => String(x) === String(v));

const chips = computed(() => nilai.value
    .map((v) => props.options.find((o) => String(valueOf(o)) === String(v)))
    .filter((o) => o != null));
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => (labelOf(o) + ' ' + (o.code ?? '')).toLowerCase().includes(q)) : props.options;
});

function updatePos() {
    const el = trigger.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const ph = panel.value?.offsetHeight ?? 300;
    const spaceBelow = window.innerHeight - r.bottom;
    const openUp = spaceBelow < ph + 8 && r.top > spaceBelow;
    const width = Math.min(Math.max(r.width, 260), window.innerWidth - 16);
    const left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8));
    pos.value = { left, top: openUp ? Math.max(4, r.top - ph - 4) : r.bottom + 4, width };
}
function onViewportChange() { if (open.value) updatePos(); }

function openMenu() {
    if (props.disabled || open.value) return;
    open.value = true;
    query.value = '';
    activeIndex.value = 0;
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

function toggleOpsi(o: any) {
    const v = valueOf(o);
    emit('update:modelValue', terpilih(v) ? nilai.value.filter((x) => String(x) !== String(v)) : [...nilai.value, v]);
    nextTick(updatePos);
}
function hapus(v: any) { emit('update:modelValue', nilai.value.filter((x) => String(x) !== String(v))); }
function jadikanUtama(v: any) {
    if (!props.utama || props.disabled) return;
    emit('update:modelValue', [v, ...nilai.value.filter((x) => String(x) !== String(v))]);
}

function onKeydown(e: KeyboardEvent) {
    if (!open.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') { openMenu(); e.preventDefault(); }
        return;
    }
    if (e.key === 'ArrowDown') { activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { activeIndex.value = Math.max(activeIndex.value - 1, 0); e.preventDefault(); }
    else if (e.key === 'Enter') { if (filtered.value[activeIndex.value]) toggleOpsi(filtered.value[activeIndex.value]); e.preventDefault(); }
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
        <div
            ref="trigger" tabindex="0" @click="openMenu" @keydown="onKeydown"
            class="flex min-h-9 w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-neutral-300 bg-white px-2 py-1 text-left text-sm dark:border-neutral-700 dark:bg-neutral-900"
            :class="[open ? 'border-primary-400 ring-2 ring-primary-300/50' : '', disabled ? 'cursor-not-allowed opacity-50' : '']"
        >
            <div class="flex min-w-0 flex-wrap items-center gap-1">
                <span
                    v-for="(o, i) in chips" :key="valueOf(o)"
                    class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium"
                    :class="utama && i === 0
                        ? 'border-primary-300 bg-primary-50 text-primary-700 dark:border-primary-700 dark:bg-primary-900/30 dark:text-primary-300'
                        : 'border-neutral-300 bg-neutral-50 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                >
                    <Star v-if="utama && i === 0" class="h-3 w-3 fill-current" />
                    <span :class="utama && i > 0 && !disabled ? 'cursor-pointer hover:underline' : ''"
                          :title="utama && i > 0 ? 'Jadikan Utama' : (utama ? 'Utama' : '')"
                          @click.stop="jadikanUtama(valueOf(o))">{{ labelOf(o) }}</span>
                    <X v-if="!disabled" class="h-3 w-3 cursor-pointer text-neutral-400 hover:text-danger-500" @click.stop="hapus(valueOf(o))" />
                </span>
                <span v-if="!chips.length" class="px-1 text-neutral-400">{{ placeholder }}</span>
            </div>
            <ChevronsUpDown class="h-4 w-4 shrink-0 text-neutral-400" />
        </div>

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
                <ul class="max-h-60 overflow-y-auto py-1 text-sm">
                    <li v-if="!filtered.length" class="px-3 py-2 text-center text-neutral-400">Tidak ada hasil</li>
                    <li
                        v-for="(o, i) in filtered" :key="valueOf(o)"
                        @mouseenter="activeIndex = i" @click="toggleOpsi(o)"
                        class="flex cursor-pointer items-center justify-between px-3 py-1.5"
                        :class="[i === activeIndex ? 'bg-primary-50 dark:bg-primary-900/30' : '', terpilih(valueOf(o)) ? 'font-medium text-primary-700 dark:text-primary-300' : '']"
                    >
                        <span class="truncate">{{ labelOf(o) }}</span>
                        <Check v-if="terpilih(valueOf(o))" class="h-3.5 w-3.5 shrink-0 text-primary-600" />
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
