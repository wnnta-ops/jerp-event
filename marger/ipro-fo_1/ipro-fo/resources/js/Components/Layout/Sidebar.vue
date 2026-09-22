<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ref, watch, nextTick } from 'vue';
import {
    LayoutDashboard, Users, Building2, ClipboardList, Wallet, FileText, Receipt, FileSignature,
    BarChart3, Settings, ChevronLeft, ChevronDown, Phone, AlertTriangle, FileWarning, FileCheck,
    Vote, Hand, Star, Banknote, ScrollText, Award, RefreshCcw, CircleDollarSign, Calculator,
    FileBarChart, ShieldCheck, Percent, Landmark, Briefcase, BookOpen, Truck,
    Boxes, ShoppingCart, PackagePlus, PackageMinus, LayoutList, BookText,
    HandCoins, Coins, Building, PieChart, HardHat, Target,
    FolderGit2, CalendarClock, TriangleAlert, Lock,
    Settings2, Layers, Handshake, Tag, BellRing, FileCog,
    GitMerge, FileSpreadsheet, IdCard, Gift, Eraser, SlidersHorizontal, DatabaseZap, Database, UserPlus,
} from 'lucide-vue-next';
import { navGroups } from './navGroups';
import { useIzin } from '@/Composables/useIzin';

defineProps<{ open: boolean }>();
defineEmits<{ (e: 'close'): void }>();

const page = usePage();

// Grup submodul bisa di-collapse (dropdown) — disimpan di localStorage.
const collapsed = ref<Record<string, boolean>>(JSON.parse(localStorage.getItem('sidebarCollapsed') || '{}'));
// Grup yang dibuka otomatis oleh revealActive (runtime saja, TIDAK dipersist). Dipisah dari
// preferensi collapse user agar toggleGroup tak pernah menuliskan reveal sementara ke localStorage.
const revealed = ref<Record<string, boolean>>({});
// Grup dianggap tertutup hanya bila user meng-collapse-nya DAN tidak sedang di-reveal otomatis.
function isCollapsed(title: string): boolean { return !!collapsed.value[title] && !revealed.value[title]; }
function toggleGroup(title: string) {
    collapsed.value[title] = !collapsed.value[title];
    delete revealed.value[title]; // niat eksplisit user menang atas reveal otomatis
    localStorage.setItem('sidebarCollapsed', JSON.stringify(collapsed.value));
}

// Sub-modul (level 2) yang punya children bisa dibuka untuk menampilkan detail modul (level 3).
const openSub = ref<Record<string, boolean>>({});
function toggleSub(key: string) { openSub.value[key] = !openSub.value[key]; }

// Elemen <aside> untuk menggulir item aktif ke tampilan saat lokasi berubah.
const asideEl = ref<HTMLElement | null>(null);

const foUrl: string = ((page.props as any).config?.fo_url ?? "");
const groups = navGroups(foUrl);


// Wildcard modul + lolosnya SuperAdmin tinggal di useIzin() — dipakai bersama Command Palette.
// Disalin ke sini dulu, dan kedua salinan itu lambat laun menyaring hal yang berbeda:
// sidebar menyembunyikan menu yang masih muncul di pencarian cepat, atau sebaliknya.
const { punyaIzin: hasPermission } = useIzin();

function isActive(href?: string, params?: Record<string, any>): boolean {
    if (! href) return false;
    try { return (route() as unknown as { current: (n: string, p?: any) => boolean }).current(href, params); }
    catch { return false; }
}

// #128: resolve href dengan aman — bila nama route tidak dikenal (mis. bundle lama), kembalikan null
// agar Link tidak melempar saat render & link tidak "mati senyap".
function safeRoute(href?: string, params?: Record<string, any>): string | null {
    if (! href) return null;
    try { return route(href, params); }
    catch { return null; }
}

// Auto-reveal lokasi aktif: buka grup + sub-modul yang memuat route halaman saat ini,
// lalu gulir item aktif ke tampilan — sidebar kiri mengikuti lokasi konten di kanan.
// Hanya MEMBUKA (tak pernah menutup) agar preferensi grup lain yang dibuka user tetap.
function revealActive(): void {
    for (const g of groups) {
        for (const item of g.items) {
            const active = item.children?.length
                ? item.children.some(c => hasPermission(c.permission) && isActive(c.href, c.params))
                : (hasPermission(item.permission) && isActive(item.href, item.params));
            if (! active) continue;
            if (g.title) revealed.value[g.title] = true;                         // buka grup (runtime, tak dipersist)
            if (item.children?.length) openSub.value[g.title + '|' + item.label] = true; // buka sub-modul
        }
    }
    nextTick(() => {
        asideEl.value?.querySelector<HTMLElement>('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    });
}
// Jalankan saat mount (immediate) & tiap kali URL berpindah (navigasi Inertia).
watch(() => page.url, () => revealActive(), { immediate: true });
</script>

<template>
    <aside
        ref="asideEl"
        class="bg-primary-100 text-primary-900 border-r border-primary-200 flex-shrink-0 transition-all duration-300 overflow-y-auto"
        :class="open ? 'w-64' : 'w-16'"
    >
        <div class="flex items-center justify-between px-4 h-16 border-b border-primary-200 sticky top-0 bg-primary-100 z-10">
            <span v-if="open" class="text-base font-bold text-primary-900">Java Core System</span>
            <button @click="$emit('close')" class="text-primary-700 hover:text-primary-900">
                <ChevronLeft class="h-5 w-5" :class="{ 'rotate-180': ! open }" />
            </button>
        </div>

        <nav class="py-3">
            <template v-for="g in groups" :key="g.title">
                <button v-if="g.title && open && g.items.some(i => hasPermission(i.permission))"
                    type="button" @click="toggleGroup(g.title)"
                    class="flex w-full items-center justify-between px-4 mt-4 mb-1 text-[12px] uppercase tracking-wider text-primary-700 font-extrabold hover:text-primary-800">
                    <span>{{ g.title }}</span>
                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ '-rotate-90': isCollapsed(g.title) }" />
                </button>
                <template v-for="item in g.items" :key="(item.href ?? item.label) + JSON.stringify(item.params ?? {})">
                    <template v-if="hasPermission(item.permission) && (!g.title || !isCollapsed(g.title) || !open)">
                        <!-- Sub-modul dengan children (level 2) → klik untuk buka detail modul (level 3) -->
                        <template v-if="item.children">
                            <button
                                type="button" @click="toggleSub(g.title + '|' + item.label)"
                                class="flex w-full items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] font-semibold text-primary-900 transition hover:bg-primary-300 hover:text-primary-950"
                                :style="{ width: 'calc(100% - 1rem)' }"
                            >
                                <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                                <span v-if="open" class="flex-1 text-left">{{ item.label }}</span>
                                <ChevronDown v-if="open" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': openSub[g.title + '|' + item.label] }" />
                            </button>
                            <template v-if="open && openSub[g.title + '|' + item.label]">
                                <template v-for="c in item.children" :key="c.label + JSON.stringify(c.params ?? {})">
                                    <Link
                                        v-if="hasPermission(c.permission) && safeRoute(c.href, c.params)"
                                        :href="safeRoute(c.href, c.params) ?? '#'"
                                        :data-active="isActive(c.href, c.params) ? 'true' : undefined"
                                        class="flex items-center gap-2 py-1.5 pl-11 pr-4 mx-2 rounded-lg text-sm transition"
                                        :class="isActive(c.href, c.params) ? 'bg-primary-500 text-primary-950 font-bold' : 'text-primary-800 font-medium hover:bg-primary-300 hover:text-primary-950'"
                                    >
                                        <span v-if="open">{{ c.label }}</span>
                                    </Link>
                                </template>
                            </template>
                        </template>
                        <!-- Link eksternal (mis. kembali ke FO J-ERP) -->
                        <a
                            v-else-if="item.external"
                            :href="item.external"
                            class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] font-semibold text-primary-900 transition hover:bg-primary-300 hover:text-primary-950"
                        >
                            <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                            <span v-if="open">{{ item.label }}</span>
                        </a>
                        <!-- Item biasa (leaf) -->
                        <Link
                            v-else-if="safeRoute(item.href, item.params)"
                            :href="safeRoute(item.href, item.params) ?? '#'"
                            :data-active="isActive(item.href, item.params) ? 'true' : undefined"
                            class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg text-[15px] transition"
                            :class="isActive(item.href, item.params)
                                ? 'bg-primary-500 text-primary-950 font-bold'
                                : 'text-primary-900 font-semibold hover:bg-primary-300 hover:text-primary-950'"
                        >
                            <component :is="item.icon" class="h-[18px] w-[18px] flex-shrink-0" />
                            <span v-if="open">{{ item.label }}</span>
                        </Link>
                    </template>
                </template>
            </template>
        </nav>
    </aside>
</template>
