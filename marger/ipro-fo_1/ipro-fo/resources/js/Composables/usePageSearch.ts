import { ref } from 'vue';

/**
 * Pencarian/filter ISI HALAMAN yang sedang dibuka (bukan lintas modul).
 *
 * `pageQuery` di-bind ke kotak cari di Topbar. Saat berubah, `applyPageFilter()`
 * menyembunyikan elemen yang teksnya tidak mengandung keyword.
 *
 * Target filter (generik, tanpa edit tiap halaman):
 *   - Baris tabel:  <tbody> > <tr>
 *   - Item opt-in:  elemen ber-atribut [data-search-item]
 */
export const pageQuery = ref('');
export const pageMatches = ref<number | null>(null);
export const pageTotal = ref<number | null>(null);

export function applyPageFilter(): void {
    const main = document.querySelector('main');
    if (!main) return;

    const q = pageQuery.value.trim().toLowerCase();
    const items = main.querySelectorAll<HTMLElement>('tbody > tr, [data-search-item]');

    if (items.length === 0) {
        pageMatches.value = null;
        pageTotal.value = null;
        return;
    }

    let matches = 0;
    items.forEach((el) => {
        if (!q) {
            el.style.removeProperty('display');
            return;
        }
        const text = (el.textContent ?? '').toLowerCase();
        const show = text.includes(q);
        el.style.display = show ? '' : 'none';
        if (show) matches++;
    });

    pageMatches.value = q ? matches : null;
    pageTotal.value = q ? items.length : null;
}

/** Reset filter (mis. saat pindah halaman). */
export function resetPageFilter(): void {
    pageQuery.value = '';
    pageMatches.value = null;
    pageTotal.value = null;
}
