import { computed, reactive, ref, type ComputedRef, type Ref } from 'vue';

/**
 * Perkakas tabel per-kolom: FILTER (ketik "berisi" + checklist nilai) sekaligus URUT.
 *
 * Dipakai bersama komponen ColumnFilter. Dibuat sebagai composable karena tiga layar
 * Piutang Usaha (Booked) — Invoice Piutang, UM Penjualan, Rekonsiliasi Piutang — butuh perilaku
 * yang sama persis — kalau disalin bertiga, perbaikan di satu tempat gampang terlewat
 * di dua tempat lain.
 *
 * Nilai kolom SELALU diambil dari satu fungsi `value()` yang sama untuk render, opsi
 * filter, dan pencocokan. Jadi apa yang terlihat di layar itulah yang dicari dan diurut —
 * tidak mungkin filter mencari angka mentah sementara layar menampilkan angka terformat.
 */
export type ColDef<T> = {
    /** Kunci unik kolom. */
    key: string;
    /** Nilai TAMPILAN kolom (string) — dasar filter & opsi checklist. */
    value: (row: T) => string;
    /**
     * Kunci pengurutan. Wajib diisi untuk kolom angka/tanggal, karena urutan teks
     * "Rp 1.000.000" vs "Rp 900.000" akan salah bila dibandingkan sebagai huruf.
     */
    sort?: (row: T) => number | string;
};

export function useTableColumns<T>(rows: Ref<T[]> | ComputedRef<T[]>, cols: Array<ColDef<T>>) {
    const byKey = new Map(cols.map((c) => [c.key, c]));

    // Filter global (kotak cari di atas tabel) + per-kolom: ketik bebas (txt) & checklist (col).
    const search = ref('');
    const col = reactive<Record<string, string[]>>({});
    const txt = reactive<Record<string, string>>({});
    cols.forEach((c) => { col[c.key] = []; txt[c.key] = ''; });

    // Urut: klik 1 = naik, klik 2 = turun, klik 3 = kembali ke urutan asli dari server.
    const sortKey = ref('');
    const sortDir = ref<'asc' | 'desc'>('asc');
    function toggleSort(key: string) {
        if (sortKey.value !== key) { sortKey.value = key; sortDir.value = 'asc'; return; }
        if (sortDir.value === 'asc') { sortDir.value = 'desc'; return; }
        sortKey.value = ''; sortDir.value = 'asc';
    }
    const sortState = (key: string) => (sortKey.value === key ? sortDir.value : '');

    /** Pembanding yang sama dipakai untuk mengurutkan baris maupun isi dropdown. */
    function bandingkan(x: number | string, y: number | string): number {
        if (typeof x === 'number' && typeof y === 'number') return x - y;
        return String(x).localeCompare(String(y), 'id', { numeric: true, sensitivity: 'base' });
    }

    /**
     * Nilai unik yang ADA pada kolom — isi dropdown checklist.
     *
     * Diurutkan memakai `sort()` kolomnya bila ada, BUKAN teks tampilannya. Tanpa ini
     * daftar nilai kolom rupiah tampil kacau ("Rp 5.000.000" mendahului "Rp 900.000")
     * karena yang dibandingkan adalah hurufnya, bukan nilainya.
     */
    const options = computed<Record<string, string[]>>(() => {
        const out: Record<string, string[]> = {};
        for (const c of cols) {
            const kunci = new Map<string, number | string>();
            for (const r of rows.value) {
                const v = c.value(r);
                if (!kunci.has(v)) kunci.set(v, c.sort ? c.sort(r) : v);
            }
            out[c.key] = [...kunci.keys()].sort((a, b) => bandingkan(kunci.get(a)!, kunci.get(b)!));
        }
        return out;
    });

    const hasFilter = computed(() =>
        Boolean(search.value.trim())
        || Object.values(col).some((a) => a.length > 0)
        || Object.values(txt).some((v) => v.trim() !== '')
        || Boolean(sortKey.value));

    function reset() {
        search.value = '';
        for (const c of cols) { col[c.key] = []; txt[c.key] = ''; }
        sortKey.value = ''; sortDir.value = 'asc';
    }

    const filtered = computed<T[]>(() => rows.value.filter((r) => {
        if (search.value.trim()) {
            const q = search.value.trim().toLowerCase();
            const hay = cols.map((c) => c.value(r)).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        for (const c of cols) {
            const v = c.value(r);
            const q = txt[c.key]?.trim().toLowerCase();
            if (q && !v.toLowerCase().includes(q)) return false;
            if (col[c.key].length && !col[c.key].includes(v)) return false;
        }
        return true;
    }));

    const result = computed<T[]>(() => {
        const list = filtered.value;
        const c = sortKey.value ? byKey.get(sortKey.value) : undefined;
        if (!c) return list;
        const arah = sortDir.value === 'asc' ? 1 : -1;
        const kunci = c.sort ?? c.value;
        // slice() dulu: sort() mengubah array di tempat, dan array itu berasal dari computed
        // filtered — mengurutkannya langsung akan merusak sumbernya.
        return list.slice().sort((a, b) => bandingkan(kunci(a), kunci(b)) * arah);
    });

    return { search, col, txt, options, filtered, result, hasFilter, reset, sortKey, sortDir, toggleSort, sortState };
}
