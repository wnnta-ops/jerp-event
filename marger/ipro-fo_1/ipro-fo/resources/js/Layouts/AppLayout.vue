<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { debounce } from 'lodash-es';
import Sidebar from '@/Components/Layout/Sidebar.vue';
import Topbar from '@/Components/Layout/Topbar.vue';
import Toaster from '@/Components/UI/Toaster.vue';
import { pageQuery, applyPageFilter, resetPageFilter } from '@/Composables/usePageSearch';

defineProps<{ title?: string }>();

const sidebarOpen = ref(true);
const mainEl = ref<HTMLElement | null>(null);

const page = usePage();
const user = computed(() => page.props.auth.user);

/* ── Filter isi halaman ── */
const apply = debounce(() => applyPageFilter(), 80);
let observer: MutationObserver | null = null;
let offNavigate: (() => void) | null = null;

watch(pageQuery, apply);

onMounted(() => {
    // Re-apply filter setiap Vue render ulang isi halaman (mis. data berubah).
    if (mainEl.value) {
        observer = new MutationObserver(debounce(() => applyPageFilter(), 80));
        observer.observe(mainEl.value, { childList: true, subtree: true });
    }
    // Reset filter setiap pindah halaman.
    offNavigate = router.on('navigate', () => resetPageFilter());
});

onUnmounted(() => {
    observer?.disconnect();
    offNavigate?.();
});
</script>

<template>
    <Head :title="title" />

    <div class="flex h-screen overflow-hidden bg-neutral-50 dark:bg-neutral-950">
        <Sidebar :open="sidebarOpen" @toggle="sidebarOpen = !sidebarOpen" />

        <div class="flex flex-1 flex-col overflow-hidden">
            <Topbar :user="user" />

            <main ref="mainEl" class="flex-1 overflow-y-auto">
                <div class="container mx-auto py-6">
                    <slot />
                </div>
            </main>
        </div>

        <Toaster />
    </div>
</template>
