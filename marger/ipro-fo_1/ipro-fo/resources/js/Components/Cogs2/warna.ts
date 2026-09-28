/**
 * COGS Baru — palet & pemformat bersama.
 *
 * ── Mengapa paletnya dipatok di satu berkas ──
 * Warna di sini menandai IDENTITAS komponen (Tanah / Housing / Infrastruktur / License Permit),
 * bukan besarnya angka. Identitas harus melekat pada komponennya dan tidak boleh berpindah:
 * kalau tiap layar memilih warnanya sendiri, "Housing" bisa jingga di satu layar dan hijau di
 * layar berikutnya, dan pembacanya membandingkan dua hal yang salah tanpa sadar.
 *
 * ── Dari mana empat warna ini ──
 * Empat slot pertama palet kategorikal baku, dalam URUTAN TETAP — tidak pernah diputar. Keduanya
 * (terang & gelap) sudah dijalankan lewat pemeriksa palet dan LULUS seluruh gerbang:
 *
 *   terang : pita terang OK · kroma OK · pisah buta-warna ΔE 9,1 (protan) · normal ΔE 22,9
 *   gelap  : pita terang OK · kroma OK · pisah buta-warna ΔE 8,4 (protan) · normal ΔE 19,8
 *
 * Pada mode terang, dua slot berkontras di bawah 3:1 terhadap latar. Itu bukan izin untuk
 * mengabaikannya: setiap grafik di modul ini WAJIB memakai label langsung dan selalu didampingi
 * tabel angkanya, sehingga tidak ada nilai yang hanya bisa dibaca lewat warna.
 */

export type Komponen = 'tanah' | 'housing' | 'infra' | 'license';

export const KOMPONEN: Array<{ kunci: Komponen; label: string; pendek: string; slug: string }> = [
    { kunci: 'tanah', label: 'Tanah', pendek: 'Tanah', slug: 'tanah' },
    { kunci: 'housing', label: 'Housing', pendek: 'Housing', slug: 'housing' },
    { kunci: 'infra', label: 'Infrastruktur', pendek: 'Infra', slug: 'infra' },
    { kunci: 'license', label: 'License Permit', pendek: 'License', slug: 'license' },
];

/** Slot 1–4 palet kategorikal. Urutannya TETAP: tanah, housing, infra, license. */
export const WARNA: Record<Komponen, { terang: string; gelap: string }> = {
    tanah: { terang: '#2a78d6', gelap: '#3987e5' },
    housing: { terang: '#eb6834', gelap: '#d95926' },
    infra: { terang: '#1baf7a', gelap: '#199e70' },
    license: { terang: '#eda100', gelap: '#c98500' },
};

/** Variabel CSS dipakai agar satu elemen SVG bisa melayani kedua mode tanpa dirender dua kali. */
export const VAR: Record<Komponen, string> = {
    tanah: 'var(--c2-tanah)',
    housing: 'var(--c2-housing)',
    infra: 'var(--c2-infra)',
    license: 'var(--c2-license)',
};

/** Warna komposisi lahan — SEKUENSIAL satu rona, bukan kategorikal: ketiganya bagian dari satu lahan. */
export const LAHAN = {
    saleable: 'var(--c2-lahan-1)',
    rth: 'var(--c2-lahan-2)',
    jalan: 'var(--c2-lahan-3)',
};

export const rp = (v: number | null | undefined): string =>
    v === null || v === undefined ? '—' : 'Rp ' + Math.round(Number(v)).toLocaleString('id-ID');

/** Tarif per m² ditampilkan dengan sen: angkanya dikalikan luas, jadi pembulatan ke rupiah penuh terlihat di totalnya. */
export const rp2 = (v: number | null | undefined): string =>
    v === null || v === undefined ? '—' : 'Rp ' + Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const m2 = (v: number | null | undefined): string =>
    v === null || v === undefined ? '—' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const persen = (v: number | null | undefined, digit = 2): string =>
    v === null || v === undefined ? '—' : (Number(v) * 100).toFixed(digit) + '%';

export const angka = (v: number | null | undefined): string =>
    v === null || v === undefined ? '—' : Number(v).toLocaleString('id-ID');
