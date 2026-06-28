<script setup lang="ts">
import { ref, watch, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Search, CornerDownLeft, FileText, Compass } from 'lucide-vue-next';
import axios from 'axios';
import { debounce } from 'lodash-es';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const page = usePage();
const perms = computed<string[]>(() => (page.props as any).auth?.user?.permissions ?? []);
function can(p?: string): boolean { return !p || perms.value.includes(p); }
function safeRoute(name: string): string | null {
    try { return (route as any)(name); } catch { return null; }
}

/* ── Daftar halaman untuk navigasi cepat (permission-aware) ── */
const NAV: { label: string; route: string; group: string; perm?: string }[] = [
    { label: 'Dashboard', route: 'dashboard', group: 'Umum' },
    { label: 'Kalkulator', route: 'calculator.index', group: 'Umum' },
    { label: 'Customer', route: 'customers.index', group: 'Master', perm: 'master.customer.view' },
    { label: 'Tambah Customer', route: 'customers.create', group: 'Master', perm: 'master.customer.create' },
    { label: 'Unit', route: 'units.index', group: 'Master', perm: 'master.unit.view' },
    { label: 'Hold Unit', route: 'holds.index', group: 'Sales', perm: 'sales.hold.view' },
    { label: 'NUP', route: 'nup.index', group: 'Sales', perm: 'sales.nup.view' },
    { label: 'Booking', route: 'bookings.index', group: 'Sales', perm: 'sales.booking.view' },
    { label: 'Buat Booking', route: 'bookings.create', group: 'Sales', perm: 'sales.booking.create' },
    { label: 'Kontrak', route: 'contracts.index', group: 'Sales', perm: 'sales.contract.view' },
    { label: 'Exceptions', route: 'exceptions.index', group: 'Sales', perm: 'sales.booking.view' },
    { label: 'TTS', route: 'tts.index', group: 'Finance', perm: 'finance.tts.view' },
    { label: 'Kuitansi', route: 'kuitansi.index', group: 'Finance', perm: 'finance.kuitansi.view' },
    { label: 'Memo', route: 'memos.index', group: 'Finance', perm: 'finance.memo.view' },
    { label: 'Cashback', route: 'cashbacks.index', group: 'Finance', perm: 'finance.cashback.view' },
    { label: 'Refund', route: 'refunds.index', group: 'Finance', perm: 'finance.refund.request' },
    { label: 'Faktur Pajak', route: 'faktur-pajak.index', group: 'Finance', perm: 'finance.fakturpajak.view' },
    { label: 'Virtual Account', route: 'va.index', group: 'Finance', perm: 'finance.va.view' },
    { label: 'Follow-up', route: 'followups.index', group: 'Collection', perm: 'collection.followup.view' },
    { label: 'Denda', route: 'dendas.index', group: 'Collection', perm: 'collection.denda.view' },
    { label: 'PJT', route: 'pjt.index', group: 'Collection', perm: 'collection.pjt.view' },
    { label: 'Tunggakan', route: 'tunggakan.index', group: 'Collection', perm: 'collection.tunggakan.view' },
    { label: 'Surat Peringatan', route: 'sp.index', group: 'Collection', perm: 'collection.sp.view' },
    { label: 'KPR', route: 'kpr.index', group: 'KPR', perm: 'bankloan.application.view' },
    { label: 'PPJB', route: 'ppjb.index', group: 'Legal', perm: 'legal.ppjb.view' },
    { label: 'AJB', route: 'ajb.index', group: 'Legal', perm: 'legal.ajb.view' },
    { label: 'BAST', route: 'bast.index', group: 'Legal', perm: 'legal.bast.view' },
    { label: 'IMB', route: 'imb.index', group: 'Legal', perm: 'legal.imb.view' },
    { label: 'Sertifikat', route: 'sertifikat.index', group: 'Legal', perm: 'legal.sertifikat.view' },
    { label: 'Komisi', route: 'commissions.index', group: 'Komisi', perm: 'commission.view' },
    { label: 'Approval Center', route: 'approvals.index', group: 'Approval', perm: 'approval.view' },
    { label: 'Laporan', route: 'reports.index', group: 'Reports', perm: 'reporting.report.export' },
    { label: 'Pendaftaran NUP', route: 'nup.create', group: 'Sales', perm: 'sales.nup.create' },
    { label: 'Settings', route: 'settings.index', group: 'System', perm: 'system.user.view' },
];

const navItems = computed(() =>
    NAV.filter((n) => can(n.perm))
        .map((n) => ({ label: n.label, group: n.group, url: safeRoute(n.route) }))
        .filter((n): n is { label: string; group: string; url: string } => !!n.url),
);

const query = ref('');
const records = ref<Array<{ type: string; label: string; url: string }>>([]);
const loading = ref(false);
const active = ref(0);
const inputEl = ref<HTMLInputElement | null>(null);

const filteredNav = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return navItems.value;
    return navItems.value.filter((n) => n.label.toLowerCase().includes(q) || n.group.toLowerCase().includes(q));
});

/* gabungan untuk navigasi keyboard: record dulu, lalu halaman */
const items = computed(() => [
    ...records.value.map((r) => ({ kind: 'record' as const, label: r.label, sub: r.type, url: r.url })),
    ...filteredNav.value.map((n) => ({ kind: 'nav' as const, label: n.label, sub: n.group, url: n.url })),
]);

const search = debounce(async (term: string) => {
    if (term.trim().length < 2) { records.value = []; loading.value = false; return; }
    loading.value = true;
    try {
        const r = await axios.get('/api/v1/search', { params: { q: term }, withCredentials: true });
        records.value = r.data.data ?? [];
    } catch { records.value = []; }
    loading.value = false;
}, 250);

watch(query, (v) => { active.value = 0; search(v); });
watch(() => props.open, (v) => {
    if (v) { query.value = ''; records.value = []; active.value = 0; nextTick(() => inputEl.value?.focus()); }
});
watch(items, () => { if (active.value >= items.value.length) active.value = Math.max(0, items.value.length - 1); });

function close() { emit('update:open', false); }
function go(url?: string) { if (!url || url === '#') return; close(); router.visit(url); }
function move(d: number) {
    const n = items.value.length;
    if (!n) return;
    active.value = (active.value + d + n) % n;
    nextTick(() => document.querySelector('[data-cmdk-active="true"]')?.scrollIntoView({ block: 'nearest' }));
}
function enter() { const it = items.value[active.value]; if (it) go(it.url); }

function onGlobalKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        emit('update:open', !props.open);
    }
}
onMounted(() => window.addEventListener('keydown', onGlobalKey));
onUnmounted(() => window.removeEventListener('keydown', onGlobalKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" @click.self="close" class="fixed inset-0 z-[60] bg-black/40 backdrop-blur-sm flex items-start justify-center pt-[12vh] px-4">
            <div class="w-full max-w-xl card overflow-hidden shadow-2xl"
                 @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="enter" @keydown.esc="close">
                <!-- Input -->
                <div class="flex items-center gap-2 px-4 h-14 border-b border-neutral-200 dark:border-neutral-800">
                    <Search class="h-5 w-5 text-neutral-400 shrink-0" />
                    <input ref="inputEl" v-model="query"
                           placeholder="Cari customer, booking, unit, kuitansi… atau ketik nama halaman"
                           class="flex-1 bg-transparent outline-none text-sm" />
                    <span class="text-[11px] text-neutral-400 border border-neutral-200 dark:border-neutral-700 rounded px-1.5 py-0.5">ESC</span>
                </div>

                <!-- Hasil -->
                <div class="max-h-[55vh] overflow-y-auto py-1">
                    <div v-if="loading" class="px-4 py-3 text-sm text-neutral-500">Mencari…</div>

                    <template v-else>
                        <div v-if="records.length" class="px-3 pt-2 pb-1 text-[10px] uppercase tracking-wider text-neutral-400 flex items-center gap-1.5">
                            <FileText class="h-3 w-3" /> Hasil pencarian
                        </div>

                        <button v-for="(it, i) in items" :key="i"
                                :data-cmdk-active="i === active ? 'true' : undefined"
                                @click="go(it.url)" @mousemove="active = i"
                                class="w-full px-4 py-2.5 text-left flex items-center gap-3 transition"
                                :class="i === active ? 'bg-primary-50 dark:bg-primary-900/25' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/60'">
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded uppercase shrink-0"
                                  :class="it.kind === 'record' ? 'bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'">
                                {{ it.sub }}
                            </span>
                            <span class="text-sm truncate">{{ it.label }}</span>
                            <CornerDownLeft v-if="i === active" class="ml-auto h-3.5 w-3.5 text-neutral-400 shrink-0" />
                        </button>

                        <div v-if="!items.length" class="px-4 py-8 text-center text-sm text-neutral-500">
                            Tidak ada hasil untuk "<strong>{{ query }}</strong>".
                        </div>
                    </template>
                </div>

                <!-- Footer hint -->
                <div class="px-4 h-9 border-t border-neutral-200 dark:border-neutral-800 flex items-center gap-4 text-[11px] text-neutral-400">
                    <span class="inline-flex items-center gap-1"><Compass class="h-3 w-3" /> {{ navItems.length }} halaman</span>
                    <span class="ml-auto">↑↓ pilih · ↵ buka · esc tutup</span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
