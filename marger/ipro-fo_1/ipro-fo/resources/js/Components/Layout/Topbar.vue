<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search, Bell, Sun, Moon, LogOut, X } from 'lucide-vue-next';
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { pageQuery, pageMatches, pageTotal } from '@/Composables/usePageSearch';

defineProps<{ user: any }>();

const dark = ref<boolean>(localStorage.getItem('theme') === 'dark');
const searchInput = ref<HTMLInputElement | null>(null);

watch(dark, (v) => {
    document.documentElement.classList.toggle('dark', v);
    localStorage.setItem('theme', v ? 'dark' : 'light');
});

function onKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        searchInput.value?.focus();
        searchInput.value?.select();
    }
}

onMounted(() => {
    document.documentElement.classList.toggle('dark', dark.value);
    window.addEventListener('keydown', onKey);
});
onUnmounted(() => window.removeEventListener('keydown', onKey));

function clearSearch() {
    pageQuery.value = '';
    searchInput.value?.focus();
}
function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <header class="h-16 border-b border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 flex items-center px-6 gap-4">
        <!-- Pencarian / filter ISI HALAMAN ini -->
        <div class="flex-1 flex items-center gap-2 max-w-md px-3 h-9 rounded-lg border border-neutral-300 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800 text-sm focus-within:ring-2 focus-within:ring-primary-500 focus-within:border-primary-500 transition">
            <Search class="h-4 w-4 text-neutral-400 shrink-0" />
            <input
                ref="searchInput"
                v-model="pageQuery"
                type="text"
                placeholder="Cari di halaman ini... (Ctrl+K)"
                class="flex-1 bg-transparent outline-none text-neutral-700 dark:text-neutral-200 placeholder:text-neutral-400"
                spellcheck="false"
                autocomplete="off"
            />
            <span v-if="pageMatches !== null" class="text-xs text-neutral-400 shrink-0 tabular-nums">
                {{ pageMatches }}/{{ pageTotal }}
            </span>
            <button v-if="pageQuery" @click="clearSearch" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 shrink-0" title="Bersihkan">
                <X class="h-4 w-4" />
            </button>
            <span v-else class="text-xs text-neutral-400 shrink-0">⌘ K</span>
        </div>

        <div class="flex items-center gap-3 ml-auto">
            <button class="p-2 text-neutral-500 hover:text-primary-600">
                <Bell class="h-5 w-5" />
            </button>
            <button @click="dark = ! dark" class="p-2 text-neutral-500 hover:text-primary-600">
                <Sun v-if="dark" class="h-5 w-5" />
                <Moon v-else class="h-5 w-5" />
            </button>
            <div class="flex items-center gap-2 ml-2">
                <div class="w-8 h-8 rounded-full bg-accent-500 text-white flex items-center justify-center text-xs font-semibold">
                    {{ user?.name?.charAt(0) ?? '?' }}
                </div>
                <div class="hidden md:block text-sm leading-tight">
                    <div class="font-semibold">{{ user?.name }}</div>
                    <div class="text-xs text-neutral-500">{{ user?.roles?.[0] }}</div>
                </div>
                <button @click="logout" class="ml-2 p-2 text-neutral-500 hover:text-danger-500" title="Logout">
                    <LogOut class="h-4 w-4" />
                </button>
            </div>
        </div>
    </header>
</template>
