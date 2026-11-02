/**
 * Cost center bawaan baris jurnal — cermin CostCenterAkun::pilih (PHP). Ubah keduanya bersamaan.
 *
 *   akun "dikosongkan" → kosong · COA tanpa daftar → unit kerja user ·
 *   unit kerja user (Utama dulu) yang ada di daftar COA → itu · selain itu → default COA.
 */
export type AkunCc = { id: number; cost_center_id?: number | null; cost_center_optional?: boolean; cc_ids?: number[] };

export function pilihCc(acc: AkunCc | null | undefined, milikUser: number[] = []): number | null {
    if (!acc) return milikUser[0] ?? null;
    if (acc.cost_center_optional) return null;
    const ids = (acc.cc_ids ?? []).map(Number);
    if (!ids.length) return milikUser[0] ?? null;
    const cocok = milikUser.map(Number).find((c) => ids.includes(c));
    if (cocok !== undefined) return cocok;
    const def = acc.cost_center_id != null ? Number(acc.cost_center_id) : null;
    return def !== null && ids.includes(def) ? def : ids[0];
}

/** Pilihan cost center untuk baris akun ini: daftar COA bila ada, selain itu semua. */
export function opsiCc<T extends { id: number }>(acc: AkunCc | null | undefined, semua: T[]): T[] {
    const ids = (acc?.cc_ids ?? []).map(Number);
    return ids.length ? semua.filter((c) => ids.includes(Number(c.id))) : semua;
}
