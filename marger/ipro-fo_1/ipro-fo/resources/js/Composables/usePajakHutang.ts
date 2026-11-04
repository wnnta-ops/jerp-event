/**
 * Hitungan Nilai Transaksi → PPN → PPh → Dibayar (net) untuk layar yang meminta rincian pajak
 * di luar Registrasi Invoice Hutang (mis. Memo Urgent). Rumus & perilaku isian sama dengan
 * tahap 2 Payable/Form.vue dan PajakHutang.php di server — server tetap menghitung ulang.
 */
import { computed, watch } from 'vue';

export type FormPajak = {
    dpp: number | null; ppn_mode: 'none' | 'with'; ppn_pct: number; ppn_incl: boolean; ppn_base: number;
    pph_mode: 'none' | 'withheld' | 'borne'; pph_type: string; pph_pct: number; pph_base: number;
};

export const PAJAK_AWAL: FormPajak = {
    dpp: null, ppn_mode: 'none', ppn_pct: 11, ppn_incl: false, ppn_base: 0,
    pph_mode: 'none', pph_type: '', pph_pct: 0, pph_base: 0,
};

export const PPH_RATES: Record<string, number> = { pph21: 2.5, pph23: 2.0, pph4_2: 1.75 };

const r0 = (v: any) => Math.round(Number(v) || 0);
const fl0 = (v: any) => Math.floor(Number(v) || 0);
/** PPN: desimal tepat 0,5 ke BAWAH (ceil(v − 0,5)) — sama dengan server. */
const rpnn = (v: any) => Math.ceil((Number(v) || 0) - 0.5);

export function usePajakHutang(form: FormPajak) {
    const dppR = computed(() => r0(form.dpp));
    const ppnOn = computed(() => form.ppn_mode === 'with');
    const inclOn = computed(() => ppnOn.value && form.ppn_incl === true);
    const objekIsi = computed(() => (ppnOn.value ? Math.min(r0(form.ppn_base) || dppR.value, dppR.value) : 0));
    const ppnNonObject = computed(() => (ppnOn.value ? Math.max(0, dppR.value - objekIsi.value) : 0));
    const ppn = computed(() => {
        if (!ppnOn.value) return 0;
        const pct = Number(form.ppn_pct) || 0;

        return inclOn.value ? rpnn(objekIsi.value * pct / (100 + pct)) : rpnn(objekIsi.value * pct / 100);
    });
    const ppnBase = computed(() => (inclOn.value ? objekIsi.value - ppn.value : objekIsi.value));
    const dppNet = computed(() => (inclOn.value ? dppR.value - ppn.value : dppR.value));
    const pphRefBase = computed(() => (ppnOn.value ? ppnBase.value : dppNet.value));
    const pphOn = computed(() => form.pph_mode === 'withheld' || form.pph_mode === 'borne');
    const pphBase = computed(() => (pphOn.value ? Math.min(r0(form.pph_base) || pphRefBase.value, dppNet.value) : 0));
    const pphNonObject = computed(() => Math.max(0, dppNet.value - pphBase.value));
    const pphJournal = computed(() => {
        if (!pphOn.value) return 0;
        const raw = pphBase.value * (Number(form.pph_pct) || 0) / 100;

        return form.pph_type === 'pph4_2' ? fl0(raw) : r0(raw);
    });
    const pph = computed(() => (form.pph_mode === 'borne' ? 0 : pphJournal.value));
    const total = computed(() => dppNet.value + ppn.value);
    const net = computed(() => (form.pph_mode === 'borne' ? total.value : total.value - pph.value));

    // Perilaku isian sama dengan tahap 2 invoice: tarif default per jenis PPh, objek default mengikuti DPP/objek PPN.
    watch(() => form.pph_type, (t) => { if (t && PPH_RATES[t] !== undefined) form.pph_pct = PPH_RATES[t]; });
    watch(() => form.pph_mode, (m) => { if ((m === 'withheld' || m === 'borne') && !Number(form.pph_base)) form.pph_base = pphRefBase.value; });
    watch(() => form.ppn_mode, (m) => { if (m === 'with') form.ppn_base = Number(form.dpp) || 0; });
    watch(() => form.dpp, (val, old) => {
        if (form.ppn_mode === 'with' && (!Number(form.ppn_base) || Number(form.ppn_base) === Number(old))) form.ppn_base = Number(val) || 0;
    });
    watch(pphRefBase, (val, old) => {
        if (pphOn.value && (!Number(form.pph_base) || Number(form.pph_base) === Number(old))) form.pph_base = Number(val) || 0;
    });

    return { dppNet, ppn, ppnBase, ppnNonObject, pphOn, pphBase, pphNonObject, pph, total, net };
}
