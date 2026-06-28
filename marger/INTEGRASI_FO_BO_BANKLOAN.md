# Desain Integrasi — FO (J-ERP) + BO & BankLoan (ipro-fo)

> Model: **2 aplikasi, 1 server MySQL, SSO**. FO = J-ERP (Filament, tetap). BO + BankLoan = ipro-fo (Vue). FO ipro-fo & BO/Bankloan lama J-ERP **dipensiunkan**.

## 1. Arsitektur
```
┌─────────────────┐         ┌──────────────────────────┐
│  FO  (J-ERP)    │  share  │  BO + BankLoan (ipro-fo) │
│  Filament       │◄──DB───►│  Inertia/Vue            │
│  db_fo (fo_*)   │  + SSO  │  db_bo (bs_*, kpr_*)    │
└─────────────────┘         └──────────────────────────┘
        1 server MySQL · 1 login (SSO) · data nyambung
```
- **Master data** (customer/unit/project): **J-ERP = sumber kebenaran**. ipro-fo membaca via VIEW (tanpa duplikasi).
- **Posting GL**: FO kirim event → tulis jurnal ke GL ipro-fo (`JournalService::post`).
- **KPR↔KPA**: lihat §4 (ada komplikasi — perlu keputusan).

## 2. Skema kedua sisi (ringkas)
**J-ERP (db_fo):** `fo_customers`, `fo_units`, `fo_kontrak`, `fo_kontrak_tagihan` (jadwal), BKM/TTS, `bo_*` (BO lama — dipensiunkan), bankloan lama.
**ipro-fo (db_bo):** master `customers`, `units`, `bookings`, `payment_schedules`; Finance `tts_receipts`+alokasi; GL `bs_accounts`, `bs_journals`(+lines), `bs_fiscal_periods`, AP/AR, cash, rekonsiliasi; BankLoan `kpr_applications`, `kpr_sp3k`, `kpr_akads`, `kpr_realizations`, `kpr_retentions`, `bank_partners`, `bank_products`, `retention_categories`. Semua **multi-tenant** (`tenant_id`).

## 3. BO / GL — INTEGRASI BERSIH ✅
Titik masuk GL ipro-fo: **`app/Domain/BusinessSupport/Services/JournalService.php → post(header, lines)`** (double-entry, validasi debit=kredit + periode open).

**Bridge FO→GL:** FOBO J-ERP (`config/fobo.php`) sudah memetakan 8 jenis event → kode COA:
| Jenis (FO) | Debet | Kredit |
|---|---|---|
| KAS_MASUK | 1.1.01.002 | 1.1.02.001 |
| SALES | 1.1.02.001 | 4.1.01.001 |
| KOMISI | 5.2.01.001 | 2.1.01.001 |
| AKAD | 1.1.01.104 | 1.1.02.001 |
| BUYBACK/REFUND | 2.1.01.004 | 1.1.01.002 |
| MEMO | 5.9.01.001 | 1.1.02.001 |
| BIAYA_KPR | 5.9.01.002 | 1.1.01.002 |

**Rencana:** buat **tabel pemetaan COA J-ERP → bs_accounts ipro-fo** (kode → account_id). `PostFoEventToGl` listener diubah: alih-alih tulis ke `bo_jurnal` lama, panggil `JournalService::post()` ipro-fo (lewat koneksi `db_bo`) dengan account_id hasil mapping. → **Fase 3**.

## 4. BankLoan / KPA — ADA KOMPLIKASI ⚠️ (perlu keputusan)
KPA ipro-fo **erat terkait FO ipro-fo**:
- `kpr_applications.booking_id → bookings` (ipro-fo), `customer_id → customers`.
- Pencairan (`RecordDisbursement`) **auto-bikin TTS ipro-fo** + alokasi ke `payment_schedules` ipro-fo.
- GL KPA terjadi lewat **TTS ipro-fo** (bukan langsung).

Padahal FO kita pakai `fo_kontrak` + `fo_kontrak_tagihan` + **BKM/TTS J-ERP** (bukan bookings/payment_schedules/TTS ipro-fo). Jadi KPA ipro-fo **tidak bisa langsung** dipakai tanpa menyentuh FO ipro-fo.

**Opsi (pilih saat Fase 4):**
- **A. KPR tetap di FO J-ERP** (yang sudah ada: `/bankloan` J-ERP), ipro-fo hanya untuk **BO/GL + akuntansi pencairan**. Pencairan KPR FO → kirim event ke GL ipro-fo (lewat §3). **Paling sederhana, tak menyentuh KPA ipro-fo.**
- **B. Pakai KPA ipro-fo penuh**, tapi rewire FK-nya ke `fo_kontrak`/`fo_kontrak_tagihan`/BKM J-ERP (bukan bookings/payment_schedules/TTS ipro-fo). **Effort besar** (ubah banyak Action ipro-fo).
- **C. Adopsi sebagian FO ipro-fo** (bookings + payment_schedules + TTS) khusus alur KPR. **Membingungkan** (FO dobel).

> **Rekomendasi: Opsi A** untuk fase 2-app sekarang (KPR pakai Bankloan J-ERP yang sudah jalan; ipro-fo fokus BO/GL). KPA ipro-fo bisa diadopsi nanti saat 1-app.

## 5. Master data share (Fase 2)
- J-ERP master: `db_fo.fo_customers`, `db_fo.fo_units`, `db_fo.projects`.
- Di `db_bo`, buat **VIEW**: `customers`, `units`, `projects` → SELECT dari `db_fo.*` (peta kolom: `fo_customers.full_name`→`customers.full_name`, dst). Model ipro-fo baca view → real-time, tanpa duplikasi.
- Tabel master asli ipro-fo (`customers`,`units`) **dikosongkan/diganti view** (atau di-rename + view menimpa). Perlu hati-hati: kolom yang dipakai ipro-fo harus ada di view.
- **tenant_id**: samakan satu tenant (mapping id tenant J-ERP↔ipro-fo).

## 6. SSO (Fase 5)
- Pakai folder `SSO` yang sudah ada. 1 login → token berlaku di FO & BO.
- Tabel `users` disatukan (atau SSO mengeluarkan identitas, tiap app map ke user lokalnya).
- Menu: tambah link silang (FO → BO/Bankloan & sebaliknya).

## 7. Yang dimatikan / dibuang
- ipro-fo: **route + menu FO** (Sales/Booking/NUP/TTS/Siteplan/Collection/Commission/Legal) — pakai FO J-ERP. Sisakan BO (BusinessSupport) + BankLoan + Finance(GL) + Settings + Auth.
- J-ERP: panel **Bo** + (opsional) **Bankloan** lama + modul `app/Modules/BO` + migrasi `bo_*` — setelah GL pindah ke ipro-fo. *(Hati-hati: FOBO bridge dipindah dulu sebelum buang BO lama.)*

## 8. Urutan eksekusi
- **Fase 0** ✅ jalankan ipro-fo lokal (MySQL, port terisolasi).
- **Fase 1** ✅ dokumen ini.
- **Fase 2** satukan DB (1 server) + VIEW master + nonaktifkan FO ipro-fo.
- **Fase 3** FOBO bridge → `JournalService::post` ipro-fo + tabel mapping COA.
- **Fase 4** KPR (Opsi A): pencairan KPR FO → event GL ipro-fo. (KPA ipro-fo = nanti.)
- **Fase 5** SSO + menu silang.
- **Fase 6** theme FO Filament (termasuk **halaman login**) mirip ipro-fo.

## 9. Risiko utama
1. **Master data**: kolom view harus lengkap sesuai yang dipakai ipro-fo (kalau kurang → error). Mitigasi: petakan kolom teliti, isi default bila perlu.
2. **KPA coupling** (§4) → pakai Opsi A dulu.
3. **tenant_id / id** beda antar app → perlu peta/penyamaan.
4. **2 app, 2 deploy** → ops lebih kompleks (wajar untuk fase 2-app).

---

# REVISI (grounded, 2026-06-27) — setelah pemetaan skema nyata + arahan user

**Status:** Fase 0 ✅ (ipro-fo jalan di MySQL), Fase 2a ✅ (1 MySQL: `jerp-mysql` berisi `jerp` + `ipro_bo`, ipro-mysql mati, via network `shared-db`).

## A. Arahan user (verbatim)
- Data BO/Bankloan **mengalir dari FO J-ERP**: customer, unit, **SPR/SPRS, TTS**.
- **KPR = handoff**: FO J-ERP **"pembuatan SP"** (inisiasi) → ipro-fo **"pemberkasan dst"** (modul KPA/kpr_initiations yang sudah ada: SP3K→Akad→Pencairan→Retensi). Bukan Opsi A/B murni — **hybrid**.

## B. Peta skema nyata
**FO J-ERP** (`app/Modules/FO/...`): `fo_customers`, `fo_units`, `fo_kontrak`(SPR)+`fo_kontrak_tagihan`(jadwal), `fo_tts`→`fo_bkm`(receipt, posting GL), `fo_komisi`, `KprPengajuan`(SP KPR). **Bridge FOBO sudah ada**: `FoFinancialEvent`→`PostFoEventToGl` (`app/Modules/BO/FoBoBridge/`), idempoten via `bo_fobo_postings`, mapping `config/fobo.php` (KAS_MASUK, SALES, KOMISI, AKAD, BUYBACK, REFUND, MEMO, BIAYA_KPR). Aktif: KAS_MASUK (BKM), BIAYA_KPR.
**BO ipro-fo** (`ipro_bo`): `customers`,`units`,`bookings`(SPR)+`payment_schedules`,`tts_receipts`+`tts_allocations`; GL `JournalService::post(header,lines)` — lines butuh **`account_id`=`bs_accounts.id`**, validasi debit=kredit + periode open. KPR: `kpr_initiations`(7 tahap)→`kpr_applications`→`kpr_sp3k`→`kpr_akads`→`kpr_realizations`(pencairan,+TTS)→`kpr_retentions`. tenant default code `MUP`.

## C. Keputusan arsitektur (perlu konfirmasi)
1. **Posting GL FO→ipro-fo** (J-ERP tak bisa panggil PHP ipro-fo):
   - **(a) tulis langsung** ke `ipro_bo.bs_journals`+`bs_journal_lines` (1 MySQL → koneksi DB kedua di J-ERP; replikasi validasi balanced). Cepat, tanpa API. **← rekomendasi (1 MySQL).**
   - (b) ipro-fo sediakan API internal `POST /api/journal`; J-ERP kirim HTTP (pakai JournalService asli). Lebih rapi, perlu endpoint+auth.
2. **Kode COA**: J-ERP `1.1.01.002` ≠ ipro-fo `1-1100`. Opsi: (i) tabel mapping `kode_jerp → bs_account.id`, atau (ii) re-seed `bs_accounts` ipro-fo pakai kode dotted J-ERP. **← rekomendasi (i) mapping** (tak ubah COA ipro-fo).
3. **Master data FO→BO**: push event-driven (perluas pola FoFinancialEvent) menulis `customer/unit/booking/tts` ke `ipro_bo` saat dibuat/diubah di FO. (VIEW murni gagal: MySQL FK tak bisa ke VIEW.)

## D. Urutan eksekusi (revisi)
- **Fase 2b**: nonaktifkan FO ipro-fo (sisakan BO+Bankloan+GL+Settings+Auth). Sync master FO→`ipro_bo` (customer, unit).
- **Fase 3**: retarget bridge FO→GL ipro-fo (`bs_journals`) + tabel mapping COA + push SPR→booking & TTS→tts_receipts.
- **Fase 4**: FO `KprPengajuan`(SP) → `ipro_bo.kpr_initiations` (handoff); ipro-fo lanjut pemberkasan→retensi.
- **Fase 5**: SSO. **Fase 6**: theme FO + login mirip ipro-fo.
