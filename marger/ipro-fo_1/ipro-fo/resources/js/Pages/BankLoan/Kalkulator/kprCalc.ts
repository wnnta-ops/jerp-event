/* ============================================================
   Mesin hitung KPR — mendukung beberapa skema bunga:
   1) fixed_floating : bunga fixed N tahun pertama, lalu floating
   2) anuitas         : bunga tetap sepanjang tenor (anuitas)
   3) efektif          : bunga efektif / menurun (pokok tetap)
   4) tiered          : bunga berjenjang beberapa tahap lalu floating
   Semua tipe (kecuali efektif) diselesaikan lewat mesin "periode"
   yang sama, supaya opsi subsidi bunga tahun pertama (khusus BTN)
   bisa diterapkan secara umum ke skema apa pun.
   ============================================================

   DIPINDAHKAN APA ADANYA dari site/calc.js milik aplikasi Kalkulator KPR Java Residence.
   Aritmetika, urutan operasi, dan SETIAP pembulatannya sengaja tidak disentuh: satu
   Math.round yang bergeser satu tempat menghasilkan angka yang tetap terlihat masuk akal
   tetapi tidak lagi sama dengan yang selama ini dipakai tim penjualan.

   Kesamaannya DIKUNCI OLEH UJI, bukan oleh niat baik: tests/Manual/uji-kalkulator-kpr-mesin.cjs
   menjalankan 150 kombinasi (5 bank × seluruh skemanya × tenor 10/15/20/25/30 th × subsidi
   on/off) lalu membandingkan sidik jari hasilnya dengan sidik jari mesin ASLI. Berkas ini
   tidak boleh "dirapikan" tanpa uji itu ikut hijau.
*/

export interface KprTier {
    years: number;
    ratePercent: number;
    tierLabel?: string;
}

export interface KprScheme {
    id?: string;
    type: 'fixed_floating' | 'tiered' | 'anuitas' | 'efektif';
    label?: string;
    fixedYears?: number;
    fixedRatePercent?: number;
    floatingRatePercent?: number;
    ratePercent?: number;
    tiers?: KprTier[];
    minTenorYears?: number;
}

export interface KprPeriod {
    months: number;
    rate: number;
    label: string;
}

export interface KprRow {
    month: number;
    payment: number;
    principal: number;
    interest: number;
    balance: number;
    rateLabel: string;
}

export interface KprYear {
    year: number;
    firstMonthPayment: number;
    totalPayment: number;
    totalInterest: number;
    endBalance: number;
    rateLabel: string;
}

export function idr(n: number): string {
    n = Math.round(n || 0);

    return 'Rp' + n.toLocaleString('id-ID');
}

/** Payment anuitas standar */
export function annuityPayment(principal: number, monthlyRate: number, months: number): number {
    if (months <= 0) return 0;
    if (monthlyRate === 0) return principal / months;
    const f = Math.pow(1 + monthlyRate, months);

    return principal * monthlyRate * f / (f - 1);
}

/** Ubah skema (fixed_floating / tiered / anuitas) jadi daftar periode
 *  {months, rate, label} berurutan dari awal tenor. Periode terakhir
 *  boleh berdurasi Infinity (dipotong otomatis sesuai tenor terpilih). */
export function schemeToPeriods(scheme: KprScheme): KprPeriod[] | null {
    if (scheme.type === 'fixed_floating') {
        return [
            { months: Math.max(0, Math.round((scheme.fixedYears || 0) * 12)), rate: scheme.fixedRatePercent as number, label: Number(scheme.fixedRatePercent).toFixed(2) + '% (fixed)' },
            { months: Infinity, rate: scheme.floatingRatePercent as number, label: Number(scheme.floatingRatePercent).toFixed(2) + '% (floating)' },
        ];
    }
    if (scheme.type === 'tiered') {
        const periods: KprPeriod[] = (scheme.tiers || []).map((t) => ({
            months: Math.max(0, Math.round((t.years || 0) * 12)),
            rate: t.ratePercent,
            label: (t.tierLabel ? t.tierLabel + ' ' : '') + Number(t.ratePercent).toFixed(2) + '%',
        }));
        periods.push({ months: Infinity, rate: scheme.floatingRatePercent as number, label: Number(scheme.floatingRatePercent).toFixed(2) + '% (floating)' });

        return periods;
    }
    if (scheme.type === 'anuitas') {
        return [{ months: Infinity, rate: scheme.ratePercent as number, label: Number(scheme.ratePercent).toFixed(2) + '%' }];
    }

    return null; // 'efektif' tidak berbasis anuitas, ditangani terpisah
}

/** Sisipkan diskon bunga di N bulan pertama ke sebuah daftar periode,
 *  memecah periode yang terpotong tanpa mengubah urutan/panjang total. */
export function applyFirstYearSubsidy(periods: KprPeriod[], subsidyPercent: number, subsidyMonths: number): KprPeriod[] {
    if (! subsidyPercent || subsidyPercent <= 0 || ! subsidyMonths) return periods;
    const result: KprPeriod[] = [];
    let remaining = subsidyMonths;
    for (const p of periods) {
        if (remaining <= 0) { result.push(p); continue; }
        const part = Math.min(p.months, remaining);
        result.push({ months: part, rate: Math.max(0, p.rate - subsidyPercent), label: p.label + ` (subsidi -${subsidyPercent}%)` });
        remaining -= part;
        const leftover = p.months - part;
        if (leftover > 0) result.push({ months: leftover, rate: p.rate, label: p.label });
    }

    return result;
}

/** Bangun baris amortisasi bulanan dari daftar periode, dengan anuitas
 *  dihitung ulang setiap masuk periode baru berdasarkan sisa pokok & sisa tenor. */
export function buildFromPeriods(periods: KprPeriod[], plafon: number, tenorMonths: number): KprRow[] {
    const rows: KprRow[] = [];
    let balance = plafon;
    let monthsConsumed = 0;

    for (const period of periods) {
        if (monthsConsumed >= tenorMonths) break;
        const remainingTenor = tenorMonths - monthsConsumed;
        const monthsInPeriod = Math.min(period.months, remainingTenor);
        if (monthsInPeriod <= 0) continue;

        const r = (period.rate / 100) / 12;
        const pay = annuityPayment(balance, r, remainingTenor);
        for (let i = 1; i <= monthsInPeriod; i++) {
            const interest = balance * r;
            let principal = pay - interest;
            const isLastMonthOverall = (monthsConsumed + i === tenorMonths);
            if (isLastMonthOverall) principal = balance;
            balance = Math.max(0, balance - principal);
            rows.push({ month: monthsConsumed + i, payment: principal + interest, principal, interest, balance, rateLabel: period.label });
        }
        monthsConsumed += monthsInPeriod;
    }

    return rows;
}

/**
 * Bangun tabel amortisasi bulanan.
 * scheme: { type: 'fixed_floating'|'anuitas'|'efektif'|'tiered', ...params }
 * plafon: pokok pinjaman, tenorMonths: total bulan
 * subsidyPercent (opsional): potongan bunga di subsidyMonths bulan pertama
 * subsidyMonths (opsional, default 12)
 */
export function buildAmortization(scheme: KprScheme, plafon: number, tenorMonths: number, subsidyPercent?: number, subsidyMonths?: number): KprRow[] {
    subsidyMonths = subsidyMonths || 12;
    const sub = subsidyPercent || 0;

    if (scheme.type === 'efektif') {
        const rows: KprRow[] = [];
        let balance = plafon;
        const principalFlat = plafon / tenorMonths;
        for (let m = 1; m <= tenorMonths; m++) {
            const discounted = sub > 0 && m <= subsidyMonths;
            const ratePercent = discounted ? Math.max(0, (scheme.ratePercent as number) - sub) : (scheme.ratePercent as number);
            const r = (ratePercent / 100) / 12;
            const interest = balance * r;
            balance = Math.max(0, balance - principalFlat);
            rows.push({ month: m, payment: principalFlat + interest, principal: principalFlat, interest, balance,
                rateLabel: ratePercent.toFixed(2) + '%' + (discounted ? ` (subsidi -${sub}%)` : '') });
        }

        return rows;
    }

    let periods = schemeToPeriods(scheme);
    if (! periods) return [];
    periods = applyFirstYearSubsidy(periods, sub, subsidyMonths);

    return buildFromPeriods(periods, plafon, tenorMonths);
}

/** Ringkasan cepat tanpa membangun seluruh tabel (dipakai utk update instan) */
export function quickSummary(scheme: KprScheme, plafon: number, tenorMonths: number, subsidyPercent?: number, subsidyMonths?: number): { firstPayment: number; lastPayment: number; changesOverTime: boolean } {
    const rows = buildAmortization(scheme, plafon, tenorMonths, subsidyPercent, subsidyMonths);
    if (! rows.length) return { firstPayment: 0, lastPayment: 0, changesOverTime: false };
    const changesOverTime = rows.some((r) => Math.abs(r.payment - rows[0].payment) > 1);

    return { firstPayment: rows[0].payment, lastPayment: rows[rows.length - 1].payment, changesOverTime };
}

/** Estimasi gaji minimum: 2x angsuran pada suku bunga terakhir/floating
 *  (yaitu angsuran tertinggi yang akan dijalani sepanjang tenor).
 *
 *  CATATAN: maxInstallmentRatio (0,35 pada SEMUA bank di konfigurasi) SENGAJA TIDAK DIPAKAI
 *  di sini — aslinya begitu, dan menggantinya dengan angsuran/0,35 akan mengubah SELURUH
 *  angka gaji minimum yang selama ini dipakai. Jangan "perbaiki" tanpa permintaan tegas. */
export function minSalaryFor(lastInstallment: number): number {
    return (lastInstallment || 0) * 2;
}

export interface DpRincian {
    dpFull: number;
    dpSubsidi: number;
    dpDiscounted: number;
    terminTotal: number;
    termin1: number;
    termin2: number;
    termin3: number;
    plafon: number;
}

/**
 * DP, tiga terminnya, dan plafon KPR.
 *
 * Dipindahkan dari app.js aplikasi aslinya. Subsidi DP 20% memang angka mati di sana
 * (tidak ada di konfigurasi bank mana pun) dan dipertahankan apa adanya.
 *
 * Pembulatan termin SENGAJA jatuh di termin 3: t1 dan t2 dibulatkan, sisanya diberikan ke
 * t3. Membagi rata bertiga akan menggeser total sebesar 1–2 rupiah dan membuat jumlah tiga
 * termin tidak lagi sama dengan DP yang harus dibayar.
 */
export function hitungDp(harga: number, utj: number, dpPct: number, freeDp: boolean, dpTambahan: number): DpRincian {
    const dpFull = Math.round(harga * dpPct / 100);
    const dpSubsidi = Math.round(dpFull * 0.2);
    const dpDiscounted = dpFull - dpSubsidi;
    const terminTotal = Math.max(0, dpDiscounted - utj);
    const termin1 = Math.round(terminTotal / 3);
    const termin2 = termin1;
    const termin3 = terminTotal - termin1 - termin2;
    const plafon = freeDp
        ? harga - utj - dpFull - dpTambahan
        : harga - utj - dpSubsidi - terminTotal - dpTambahan;

    return { dpFull, dpSubsidi, dpDiscounted, terminTotal, termin1, termin2, termin3, plafon };
}

/** Ringkas baris bulanan menjadi ringkasan per tahun (12 bulan per baris) */
export function buildYearlySummary(rows: KprRow[]): KprYear[] {
    const years: KprYear[] = [];
    let idx = 0;
    let year = 1;
    while (idx < rows.length) {
        const chunk = rows.slice(idx, idx + 12);
        const totalPayment = chunk.reduce((s, r) => s + r.payment, 0);
        const totalInterest = chunk.reduce((s, r) => s + r.interest, 0);
        years.push({
            year,
            firstMonthPayment: chunk[0].payment,
            totalPayment,
            totalInterest,
            endBalance: chunk[chunk.length - 1].balance,
            rateLabel: chunk[0].rateLabel,
        });
        idx += 12;
        year += 1;
    }

    return years;
}
