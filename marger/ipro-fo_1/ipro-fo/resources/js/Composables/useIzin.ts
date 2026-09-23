import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Pemeriksaan izin di sisi layar — SATU sumber untuk sidebar dan Command Palette.
 *
 * ── Kenapa SuperAdmin diloloskan di sini ──
 * Di sisi server, SuperAdmin melewati SELURUH gate lewat satu baris di AuthServiceProvider:
 *
 *     Gate::before(fn ($user, $ability) => $user->hasRole('SuperAdmin') ? true : null);
 *
 * Tetapi daftar izin yang dikirim ke layar (HandleInertiaRequests: getAllPermissions()) dibaca
 * dari tabel role_has_permissions dan TIDAK terpengaruh Gate::before. Akibatnya kedua sisi bisa
 * berbeda pendapat: halaman yang boleh dibuka SuperAdmin dengan mengetik alamatnya tidak muncul
 * di menunya.
 *
 * Itu bukan kemungkinan teoretis. Setiap kali sebuah fitur baru menambah izin, izin itu hanya
 * DIBUAT oleh GranularPermissionSeeder — tidak diberikan ke peran mana pun — sehingga menunya
 * tak terlihat oleh siapa pun sampai seseorang menjalankan ulang RoleSeeder. Persis itu yang
 * terjadi pada "Komponen COGS": kedua izinnya ('cogs.component.view', 'cogs.component.manage')
 * satu-satunya izin yatim dari 539 izin, dan menunya hilang dari sidebar selama dua hari tanpa
 * satu pun galat.
 *
 * Meloloskan SuperAdmin di sini membuat menu mencerminkan apa yang MEMANG bisa ia buka, dan
 * menutup seluruh kelas kegagalan itu untuk fitur berikutnya — bukan hanya untuk yang satu ini.
 * Peran lain tetap disaring apa adanya: perbaikan ini tidak memberi hak akses kepada siapa pun.
 */
export const PERAN_SUPER = 'SuperAdmin';

/**
 * Aturannya, tanpa Vue dan tanpa Inertia.
 *
 * Dipisah dari composable-nya supaya bisa diuji apa adanya (tests/Manual/uji-izin-sidebar.cjs):
 * aturan izin yang hanya bisa diuji dengan merakit halaman Inertia lengkap pada praktiknya tidak
 * pernah diuji sama sekali.
 *
 * Mendukung wildcard modul, mis. 'ap.*' = punya izin granular apa pun pada modul 'ap'.
 * Tanpa izin yang disyaratkan (undefined) selalu boleh — itu menu umum.
 */
export function cocokIzin(daftarIzin: string[], adalahSuper: boolean, perm?: string | string[]): boolean {
    if (! perm) return true;
    if (adalahSuper) return true;

    const list = Array.isArray(perm) ? perm : [perm];

    return list.some(p => p.endsWith('.*')
        ? daftarIzin.some(x => x.startsWith(p.slice(0, -1)))
        : daftarIzin.includes(p));
}

export function useIzin() {
    const page = usePage();

    const daftarIzin = computed<string[]>(() => (page.props as any).auth?.user?.permissions ?? []);
    const adalahSuper = computed<boolean>(() => ((page.props as any).auth?.user?.roles ?? []).includes(PERAN_SUPER));

    function punyaIzin(perm?: string | string[]): boolean {
        return cocokIzin(daftarIzin.value, adalahSuper.value, perm);
    }

    return { daftarIzin, adalahSuper, punyaIzin };
}
