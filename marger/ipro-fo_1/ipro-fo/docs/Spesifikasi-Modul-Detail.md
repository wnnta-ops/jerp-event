# Spesifikasi Modul Detail — Java Core System
### Lampiran Teknis PRD · Penjelasan Lengkap per Modul

| | |
|---|---|
| **Versi** | 1.0 · 2026-06-04 |
| **Sifat** | Dokumen pendamping PRD — rincian **field, status, alur, aturan, jurnal, route** tiap modul |
| **Cara baca** | Tiap modul memakai templat seragam: **Tujuan · Tabel & Field · Status · Hak Akses · Alur Kerja · Aturan & Validasi · Jurnal · Penomoran · Endpoint** |

> **Legenda field:** `*` = wajib · `?` = opsional/nullable · `(FK)` = referensi tabel lain · `(enc)` = terenkripsi · `(money)` = nilai uang (decimal) · `(soft)` = soft-delete (`deleted_at`).
> Sumber kebenaran: skema 184 tabel (`docs/_schema.txt`), route (`routes/web.php`), kode (`app/Domain`).

---

# BAGIAN I — FONDASI & MEKANISME LINTAS-MODUL

Mekanisme berikut dipakai **semua** modul; dijelaskan sekali di sini, lalu dirujuk.

## F1. Multi-Tenant (`tenants`)
- **Tujuan:** satu instalasi melayani banyak badan usaha terpisah.
- **Field:** `id, code*, name*, domain?, settings(json)?, activated_at?`.
- **Mekanisme:** Trait **`HasTenant`** otomatis mengisi `tenant_id` saat create + men-**scope** semua query ke tenant aktif. Tiap tabel transaksi punya `tenant_id`.

## F2. Audit Trail (`audit_logs`)
- **Tujuan:** jejak siapa-mengubah-apa-kapan.
- **Field:** `user_id*, event*(created/updated/deleted), auditable_type/_id*(polimorfik), old_values(json), new_values(json), context(json), ip_address, user_agent, created_at`.
- **Mekanisme:** Trait **`HasAuditTrail`** pada model yang dilacak.

## F3. RBAC — Peran & Hak Akses (`roles, permissions, role_has_permissions, model_has_roles`)
- **Tujuan:** kontrol akses berbasis peran (Spatie Permission).
- **Peran (`roles`):** `name*, description, guard_name, is_system`. **11 peran** bawaan.
- **Permission (`permissions`):** `name*` (pola `<domain>.<aksi>`), `module, label`. **193 permission** / 15 domain.
- **Mekanisme:** `Gate::before` → **SuperAdmin** lolos semua. Tiap controller memanggil `abort_unless($user->can('<perm>'), 403)`.

## F4. Approval Engine (`approvals, approval_chains, approval_chain_steps, approval_steps, approval_actions`)
- **Tujuan:** persetujuan berjenjang generik untuk dokumen apa pun (diskon, cashback, refund, jurnal, budget, exception).
- **`approval_chains`:** definisi rantai per `document_type` + `match_conditions(json)` (mis. ambang nilai). **`approval_chain_steps`:** `level, approver_type, approver_value, threshold, is_final`.
- **`approvals`** (instance): `approvable_type/_id(polimorfik), document_type, payload(json), status*(pending/approved/rejected), submitted_by, submitted_at, completed_at`. **`approval_steps`/`approval_actions`:** rekam keputusan tiap level.
- **Alur:** dokumen dibuat → `submit` membuat `approvals` (pending) → approver tiap level `approve/reject` → bila final approved → dokumen lanjut; bila reject → dokumen kembali.

## F5. Penomoran (`numerators`)
- **Field:** `code*, prefix, format*, seq_padding, reset_strategy(yearly/monthly/never), current_period, current_seq`.
- **Mekanisme:** tiap jenis dokumen punya pola; nomor di-generate atomik + unik per periode. Contoh format di §17 PRD.

## F6. Parameter Sistem (`parameters`)
- **Field:** `group*, key*, value*, data_type, label, is_public`. Contoh: `tax.ppn_pct`, dll. Dikelola di Settings.

## F7. Buku Besar — `JournalService` (`bs_journals`, `bs_journal_lines`)
- **`bs_journals`** (header): `journal_number*, journal_date*, period_year/month, source(memorial/cash/ap/ar), reference, description, status*(draft/finalized/posted), total_debit/credit(money), cost_center_id, finalized_at, approved_by, posted_at`.
- **`bs_journal_lines`:** `account_id*(FK CoA), debit(money), credit(money), description, cost_center_id, department_id, ledger_id, location_id, currency, kurs`.
- **Aturan WAJIB (di `JournalService::post`):** total Dr = total Cr; ≥ 2 baris; **periode tidak `closed`**; tiap baris punya akun *postable*. Pelanggaran → posting **ditolak** (exception).
- **Dampak:** **setiap modul akuntansi** memanggil service ini → integritas GL terjamin (lihat tabel jurnal §16 PRD).

---

# BAGIAN II — MODUL FRONT OFFICE (PENJUALAN & CRM)

## 1. CRM — Leads (`crm_leads`)
- **Tujuan:** kelola prospek dari awal kontak hingga konversi jadi customer/booking (papan Kanban).
- **Tabel/Field:** `lead_number*, name*, phone?, email?, data_source_id?(FK sumber), agent_id?, assigned_to?(user), unit_id?(minat), interest?, stage*, est_value(money)?, customer_id?(hasil convert), booking_id?(hasil convert), lost_reason?, last_contact_at?, next_followup_at?, notes?` (soft).
- **Status (`stage`):** tahapan funnel (mis. baru → kontak → kualifikasi → penawaran → menang/kalah).
- **Hak Akses:** `crm.*` (Sales/Agent/Manager).
- **Alur:** Buat lead → **pindah tahap** (Kanban drag/`move`) → catat **aktivitas/follow-up** → **convert** (jadi Customer/Booking) **atau** **lost** (+alasan).
- **Aturan:** convert mengisi `customer_id`/`booking_id`; lost mengisi `lost_reason`; `next_followup_at` untuk pengingat.
- **Endpoint:** `crm.leads.{index,create,store,show,update,move,convert,activity,lost}`.

## 2. Customer / CIF (`customers`, `customer_documents`)
- **Tujuan:** master data pembeli (Customer Information File) lengkap & sesuai regulasi.
- **Tabel/Field (inti):** `code*, full_name*, id_type*, id_number*(enc), dob, birth_place, gender, marital_status, religion, nationality, phone*, phone_alt, email, occupation, employer_name, monthly_income, status`. **Alamat KTP:** `address_line, address_rt_rw, kelurahan, kecamatan, city, province, postal_code`. **NPWP:** `npwp, npwp_name, npwp_same_as_ktp, npwp_address/rt_rw/kelurahan/kecamatan/kota/postal_code`. **Surat-menyurat:** `mailing_same_as_ktp, mailing_*`. **Kantor:** `office_*, job_title, office_phone`. **Darurat:** `emergency_name/relationship/phone/email`. **Identitas berlaku:** `id_expires_at, id_lifetime`. (soft)
- **Dokumen:** `customer_documents` (KTP/NPWP/KK scan: `type, label, file_path, mime, size_bytes`).
- **Hak Akses:** `master.*` / `sales.*`.
- **Aturan:** `id_number` **terenkripsi**; centang **"Sama dengan KTP"** menyalin alamat (AddressClone) → mailing/office; centang NPWP "Sama dengan KTP" → `npwp_name`=full_name, `npwp`=id_number. **Merge** menggabungkan duplikat (+ pemetaan referensi). **Upload massal** via CSV template.
- **Endpoint:** `customers.{merge,merge.references,merge.apply,upload,upload.template,upload.store}`, `cif.{index,show}`.

## 3. Master Properti — Project, Cluster, Block, Unit, Tipe, Siteplan
- **Tujuan:** struktur produk yang dijual.
- **Tabel:** `projects (code,name,location,launched_at,siteplan_meta)` › `clusters (code,name)` › `blocks (code)` › **`units`** › `unit_types (code,name,lt,lb,bedrooms,bathrooms)`.
- **`units`:** `unit_code*, project_id, cluster_id, block_id, unit_type_id, address, base_price*(money), current_price(money), status*, attributes(json LT/LB)` (soft).
- **`siteplan_points`:** koordinat unit di siteplan (`x,y,width,height,rotation,svg_path`).
- **Status unit:** available / hold / booked / sold (siklus).
- **Alur:** kelola master → **Siteplan** interaktif (klik unit → status + **"Buat Booking"**).
- **Endpoint:** `siteplan.{show,update}` + Setup Sales.

## 4. Pricelist (`pricelists`, `pricelist_items`, `sales_price_histories`)
- **Tujuan:** daftar harga berversi per unit.
- **Tabel:** `pricelists (name, effective_from/until, is_active)` › `pricelist_items (unit_id, price, discount_max_pct)`. Perubahan harga dicatat di `sales_price_histories (old_price,new_price,source,note,changed_by)`.
- **Alur:** buat pricelist → isi harga per unit → **aktifkan** (1 aktif/periode) → harga unit mengikuti.
- **Endpoint:** `pricelist.{index,create,units,store,show,activate,destroy}`.

## 5. NUP — Nomor Urut Pemesanan (`nup_schemes`, `nup_registrations`, `nup_payments`, `launchings`)
- **Tujuan:** antrian prioritas pembelian (terutama saat launching).
- **`nup_schemes`:** `code,name, nup_amount(money), open_from/to, is_refundable, refund_penalty_pct, launching_id`.
- **`nup_registrations`:** `nup_number*, queue_no*, customer_id, registered_at, sales_person_id, agent_id, status*, form_data(json), payment_status, payment_provider/ref/url/external_id, verification_status, verified_at/by, verification_note` (soft).
- **`nup_payments`:** `amount, paid_at, payment_method, reference_no`.
- **Status:** registrasi → (bayar) → **verifikasi** → siap promote ke booking; bisa **refund** (dengan penalti).
- **Alur:** Daftar (internal **atau** form **publik anonim**) → bayar (`pay`) → **verifikasi** admin → **promote** ke Booking / **refund**.
- **Hak Akses:** publik (store/done) tanpa login; internal `sales.*`.
- **Endpoint:** `nup.{create,public.store,public.done}` (publik); `nup.{index,verifikasi,show,bayar,pay,refund,verify,destroy}`.

## 6. Hold Unit (`unit_holds`)
- **Tujuan:** tahan unit sementara agar tidak diambil orang lain.
- **Field:** `unit_id*, customer_id?, sales_person_id?, held_at*, expires_at*, reason?, status*`.
- **Status:** active / released / expired.
- **Alur:** Buat hold (set masa berlaku) → unit "terkunci" → **release** manual **atau** **kedaluwarsa otomatis** (scheduler `hold:expire` tiap 00:30).
- **Endpoint:** `holds.{index,create,store,release}`.

## 7. Booking (`bookings`, `booking_discounts`, `booking_status_histories`, `payment_schedules`, `payment_scheme_terms`)
- **Tujuan:** transaksi pemesanan unit — jantung penjualan.
- **`bookings`:** `booking_number*, customer_id*, unit_id*, payment_scheme_id*, sales_person_id, agent_id, nup_registration_id?, booking_date*, base_price(money), discount_amount/pct, gimmick_value, ppn_pct, ppn_amount, total_price(money), booking_source, status*, notes` (soft).
- **`booking_discounts`:** `reason, basis, value, approval_id` → diskon **melewati approval**.
- **`payment_schedules`** (jadwal tagihan): `installment_no, label, due_date, amount(money), paid_amount, status, payment_type, sequence` — dibentuk dari **skema pembayaran** (`payment_schemes`/`payment_scheme_terms`: DP, cicilan, dll.).
- **`booking_status_histories`:** jejak transisi status.
- **Status (state machine):** draft → pending → **confirmed** → (cancelled / expired). Diskon → approval; auto-confirm bila tanpa diskon.
- **Alur:** Pilih unit (dari siteplan/hold/NUP) → **preview** harga (diskon+gimmick+PPN+total) → simpan → (diskon→approval) → **confirmed** → **Jadwal Tagihan** + **Jurnal Kontrak** terbentuk.
- **Jurnal Kontrak:** **Dr Piutang Usaha / Cr Pendapatan Penjualan (+ Cr PPN Keluaran)**.
- **Aturan:** kedaluwarsa otomatis (`booking:expire`); `promote` dari NUP; `cancel` (→ cancellation flow).
- **Endpoint:** `bookings.{preview,cancel,promote,gn,gu,reschedule}`.

## 8. Contract / PPJB (`contracts`, `contract_documents`, `contract_clauses`, `contract_signers`, `contract_operations`, `ppjb_documents`)
- **Tujuan:** kontrak jual-beli (PPJB) + dokumennya.
- **`contracts`:** `contract_number*, booking_id*, signed_at, status, template_id, snapshot(json), **ppjb_status, ppjb_system_number, ppjb_manual_number, ppjb_use_manual_number, ppjb_date, ppjb_printed_at, ppjb_signed_at**, cancelled_at` (soft).
- **`contract_documents`:** checklist dokumen (`document_type, is_present, file_path, verified_at/by`). **`contract_clauses`:** pasal. **`contract_signers`:** penandatangan (`role,name,id_number,signed_at,signature_path`).
- **`contract_operations`:** operasi atas kontrak (`operation_no, type, reason, old_values, new_values, amount, status`) — mis. penyesuaian PPN.
- **Status PPJB:** draft → printed → signing → **signed/complete**; penomoran **ganda** (sistem & manual).
- **Alur:** dari Booking confirmed → **print PPJB** → **start-signing** → **complete/sign** → kelengkapan dokumen (toggle compliance) → penomoran.
- **Endpoint:** `contracts.{index,show,update,print,sign,ppjb.print,ppjb.start-signing,ppjb.complete,ppjb.numbering,documents.toggle}`, `sales.kontrak-ops.{index,find,ppn,ppn.preview,ppn.apply,operation,apply}`, `sales.migrasi.{index,template,upload}`.

## 9. Gimmick (`gimmicks`, `gimmick_packages`, `gimmick_package_items`, `contract_gimmicks`)
- **Tujuan:** bonus/insentif penjualan (free DP, voucher, barang) yang menempel ke kontrak.
- **`gimmicks`:** `code,name,description, value(money), gimmick_type, satuan_barang, harga_satuan, effective_from/to, is_active`.
- **`gimmick_packages` + items:** paket berisi beberapa gimmick (per cluster, masa berlaku).
- **`contract_gimmicks`:** gimmick terpasang di kontrak (`value, qty, harga_satuan, total_harga, target_lunas, diberikan, diterima, tgl_diterima, gimmick_package_id`).
- **Alur:** apply **paket** ke kontrak → toggle **"diterima"** per item → remove bila batal.
- **Endpoint:** `contracts.gimmicks.apply-package`, `gimmicks.{toggle-diterima,remove}`.

## 10. TTS — Tanda Terima Sementara (`tts_receipts`, `tts_allocations`, `tts_giros`, `payment_allocations`)
- **Tujuan:** bukti penerimaan pembayaran dari customer → alokasi ke jadwal tagihan.
- **`tts_receipts`:** `tts_number*, customer_id*, booking_id*, amount(money)*, payment_method*, received_date*, bank_account_id, reference_no, status*, received_by, posted_by, posted_at` (soft).
- **`tts_allocations`:** alokasi nominal ke `payment_schedule_id`. **`tts_giros`:** bila bayar pakai giro (`giro_number, issuing_bank, giro_date, due_date, status, cleared_at, rejected_at`).
- **`payment_allocations`:** alokasi pembayaran generik (`source_type, source_id, payment_method, amount, allocated_at, reference_number`).
- **Status:** draft → **posted** (mengakui penerimaan). Giro: pending → cleared/rejected.
- **Alur:** Buat TTS → alokasikan ke termin → **post** → **Kuitansi otomatis** terbit. Giro: **clear-giro** / **reject-giro**.
- **Jurnal:** **Dr Kas/Bank / Cr Piutang Usaha**.
- **Endpoint:** `tts.{index,create,store,show,post,clear-giro,reject-giro}`.

## 11. Kuitansi (`kuitansis`, `kuitansi_items`)
- **Tujuan:** kuitansi resmi penerimaan (cetak).
- **Field:** `kuitansi_number*, tts_receipt_id*(FK), customer_id, issued_date, amount(money), terbilang, signed_by, status` (soft). Item: `description, amount`.
- **Alur:** otomatis dari TTS posted → **cetak PDF** (No Unit muncul via join booking→unit).
- **Endpoint:** `kuitansi.{index,show,pdf}`.

## 12. Memo (Free DP / Subsidi DP) (`memos`)
- **Tujuan:** pembayaran non-tunai (potongan/subsidi DP) sebagai pengganti uang masuk.
- **Field:** `memo_number*, type*(free_dp/subsidi_dp), memo_date, amount(money), reference_type/_id, description, bank_account_id, status*, approved_by/at, rejected_by/at, cancelled_by/at, reason, approval_id` (soft).
- **Status:** draft → approved/rejected → (cancelled).
- **Alur:** Grant **Free DP**/**Subsidi DP** ke booking → approval → alokasi ke jadwal; bisa **cancel**.
- **Endpoint:** `memos.{free-dp,subsidi-dp,cancel}`.

## 13. Cashback (`cashbacks`) & Refund (`refunds`)
- **Cashback:** `booking_id*, amount(money), reason, status*(pending/approved/paid), approval_id, paid_at`. Alur: ajukan → **approve** → **pay**.
- **Refund:** `refund_number*, refundable_type/_id(polimorfik), amount(money), requested_at, paid_at, memo_id, approval_id, status*`. Alur: ajukan → **approve** → **pay** (pengembalian dana).
- **Endpoint:** `cashbacks.{index,store,approve,pay}`, `refunds.{index,approve,pay}`.

## 14. Komisi (`commissions`, `commission_lines`, `commission_schemes`, `commission_scheme_terms`, `commission_payouts`, `incentives`, `incentive_schemes`)
- **Tujuan:** hitung & bayar komisi agen/sales otomatis.
- **`commission_schemes`+terms:** aturan tarif per `agent_type, trigger_event, rate_pct/flat_amount, term_no`.
- **`commissions`:** `booking_id*, commission_scheme_id, base_amount(money), status`. **`commission_lines`:** `agent_id, tier, rate_pct, amount, trigger_event, status`. **`commission_payouts`:** `amount, paid_at, memo_id, status, approval_id`.
- **`incentives`/`incentive_schemes`:** insentif (BLT/reward) — `type, beneficiary_type, amount, status, submitted/approved/realized`.
- **Alur:** booking memicu perhitungan komisi → payout (approval) → bayar.
- **Endpoint:** incentives.* (BLT/reward), komisi via Setup Sales/finance.

## 15. KPA / Bank Loan (`kpa_applications`, `kpa_sp3k`, `kpa_akads`, `kpa_realizations`, `kpa_retentions`, `kpa_buybacks`, `kpa_documents`, `bank_partners`, `bank_products`, `bank_retention_terms`, `retention_categories`)
- **Tujuan:** proses pembiayaan bank (KPR/KPA) untuk pembeli.
- **`kpa_applications`:** `application_number*, booking_id*, customer_id*, bank_partner_id, bank_product_id, plafon_requested/approved(money), tenor_month, submitted_at, reviewed_at, status*, reject_reason, handled_by` (soft).
- **`kpa_sp3k`:** persetujuan kredit (`sp3k_number, sp3k_date, approved_amount, interest_pct, tenor_month, expires_at`). **`kpa_akads`:** akad (`akad_at, place, notary, status`). **`kpa_realizations`:** pencairan (`disbursed_at, disbursed_amount, retention_amount, bank_account_id`). **`kpa_retentions`:** retensi bank (`amount, released_at, status, claim_reason`). **`kpa_buybacks`:** buyback.
- **Master:** `bank_partners` (bank rekanan), `bank_products` (produk KPR + bunga/tenor), `retention_categories` + `bank_retention_terms` (aturan retensi).
- **Status:** draft → **submit** → **SP3K** → **akad** → akad-complete (→ realisasi/retensi).
- **Endpoint:** `kpa.{index,create,store,show,submit,sp3k,akad,akad-complete}`, `kpa.setup.retensi.*`, `kpa.setup.bank.*`.

## 16. Legal (`ppjb_documents`, `ajb_documents`, `bast_documents`, `imb_documents`, `sertifikat_documents`)
- **Tujuan:** dokumen legal pasca-jual.
- **AJB:** `ajb_number, signed_at, notary_name, file_path, status`. **BAST:** `bast_number, handed_over_at, checklist(json), status`. **IMB:** `imb_number, issued_at, file_path` (per unit). **Sertifikat:** `type, serial_number, issued_at, owner_customer_id` (per unit).
- **Alur:** terbit & lacak per booking/unit; status compliance.

## 17. Collection — Penagihan (`followups`, `dendas`, `pjt_headers/_details`, `tunggakan_headers/_details`, `surat_peringatans`, `skl_documents`)
- **Tujuan:** menagih tunggakan & mengelola denda hingga lunas.
- **`followups`:** `customer_id, sales_person_id, channel, contacted_at, result, notes, next_action_at, status`.
- **`dendas`:** `payment_schedule_id, computed_date, overdue_days, rate_per_day, amount(money), amount_waived, amount_paid, status` — **perumusan** rumus denda + **pemutihan/waive**.
- **`pjt_headers/_details`:** Piutang Jatuh Tempo (regenerate per periode).
- **`tunggakan_headers/_details`:** rekap tunggakan per customer (`term_overdue_count, total_amount, first_overdue_at`).
- **`surat_peringatans`:** SP (`sp_number, level, issued_at, deadline_at, status, signed_by`). **`skl_documents`:** SKL/lunas (`skl_number, issued_at, signed_by, status, approval_id`).
- **Otomatis (scheduler):** `billing:jatuh-tempo` (01:00), `pjt:generate` (02:00), `tunggakan:update` (02:30).
- **Alur:** jatuh tempo → followup → denda → **SP** (berjenjang) → (lunas) **SKL** (cetak PDF).
- **Endpoint:** `followups.*, dendas.index, denda.{perumusan,perumusan.update,pemutihan,pemutihan.waive}, pjt.{index,regenerate}, tunggakan.index, sp.{index,issue}, skl.{index,issue,pdf}`.

## 18. Exception Flow (`cancellation_requests`, `name_change_requests`, `unit_change_requests`, `reschedule_requests`)
- **Tujuan:** perubahan/pembatalan transaksi terkendali (approval).
- **GN — Ganti Nama** (`name_change_requests`): `old/new_customer_id, admin_fee, approval_id, status`.
- **GU — Ganti Unit** (`unit_change_requests`): `old/new_unit_id, price_diff, admin_fee, approval_id, status`.
- **Reschedule** (`reschedule_requests`): `proposed_schedule(json), admin_fee, approval_id, status`.
- **Pembatalan** (`cancellation_requests`): `reason, paid_to_date, penalty_amount, refund_amount, approval_id, status`.
- **Alur:** ajukan → **approval** → terapkan (+ Surat Peringatan/biaya admin terkait).
- **Endpoint:** `bookings.{gn,gu,reschedule}`, `exceptions.index`.

## 19. Virtual Account & Pembayaran (`virtual_accounts`, `va_transactions`, `anonymous_payments`, `bank_accounts`)
- **Tujuan:** terima pembayaran via VA bank otomatis.
- **`virtual_accounts`:** `customer_id, bank_partner_id, va_number, status`. **`va_transactions`:** `amount, received_at, external_reference, status, tts_receipt_id`.
- **`anonymous_payments`:** pembayaran masuk tak teridentifikasi (`amount, received_at, reference_no, status, customer_id?, tts_receipt_id?`) → **dialokasikan** manual.
- **`bank_accounts`:** rekening perusahaan (`account_name/number, bank_name, is_virtual_pool`).
- **Alur:** assign VA ke customer → bayar → **webhook Xendit** (`webhooks.xendit`) → buat transaksi + TTS otomatis; pembayaran anonim → alokasi.
- **Endpoint:** `va.{index,assign,transactions,anonymous,anonymous.allocate}`, `rekening.*`, `webhooks.xendit`.

## 20. Faktur Pajak (FO) (`faktur_pajaks`, `faktur_pajak_items`)
- **Tujuan:** faktur pajak penjualan properti.
- **Field:** `fp_number*, customer_id, booking_id, issued_at, dpp(money), ppn(money), ppnbm, total, status, djp_response(json)`.
- **Alur:** terbit dari penjualan → masuk **rekap Faktur Keluaran** (lihat BO §Tax). *(Modul BO Tax mengelola e-Faktur/SPT.)*
- **Endpoint:** `faktur-pajak.{index,create,store}`.

## 21. Kalkulator (`calculator.*`)
- **Tujuan:** simulasi mandiri (standalone) tanpa menyimpan transaksi.
- **BPHTB:** hitung Bea Perolehan Hak atas Tanah/Bangunan. **KPR:** simulasi cicilan (plafon, bunga, tenor). **Skema:** simulasi skema pembayaran (DP, cicilan, diskon).
- **Endpoint:** `calculator.{index,bphtb,kpr,skema}`.

# BAGIAN III — MODUL BACK OFFICE / BUSINESS SUPPORT (AKUNTANSI)

> Semua modul di bawah berhak akses domain `businesssupport.*` (`view` / `transaction.create` / `setup.manage` / `approval.act`) dan memposting jurkal lewat **`JournalService`** (§F7).

## 22. Bagan Akun — CoA (`bs_accounts`)
- **Tujuan:** struktur akun 6 level untuk seluruh pembukuan.
- **Field:** `code*(1/3/5/7/9/11 digit → level 1–6), name*, type*(asset/liability/equity/income/expense), normal_balance(D/C), parent_id/parent_code, category_code, level, is_postable*, is_active` (soft).
- **Aturan:** hanya akun **is_postable** (daun) yang boleh dijurnal; tipe diturunkan dari digit pertama.
- **Endpoint:** `bs.accounts.{index,store,update,destroy}`.

## 23. Master Dimensi (`bs_cost_centers`, `bs_departments`, `bs_ledgers`, `bs_locations`, `bs_suppliers`)
- **Cost Center:** `project_id, code, name, manager` — pusat biaya (dipakai SPK/COGS/Budget/jurnal).
- **Dimensi Jurnal:** **Departemen / Ledger / Lokasi** (`code, name, is_active`) — label tambahan per baris jurnal (+ `currency`, `kurs`).
- **Supplier:** `code,name,category, contact_person, phone, email, address, city, npwp, bank_*` — pemasok/kontraktor.
- **Endpoint:** `bs.cost-centers.*, bs.journal-dimensions.*, bs.suppliers.*`.

## 24. General Ledger / Jurnal (`bs_journals`, `bs_journal_lines`, `bs_fiscal_periods`)
- **Tujuan:** jurnal manual + buku besar (lihat §F7 untuk struktur & aturan balance).
- **Lifecycle jurnal manual:** **Baru** → `finalize` (`finalized_at`) → `approve` (`approved_by`, status **posted**) / `reject`.
- **Import CSV:** kolom `TANGGAL,KODE_AKUN,DEBIT,KREDIT,KETERANGAN,REFERENSI`; baris ber-REFERENSI sama digabung 1 jurnal; **validasi balance**; opsi **dry-run**.
- **`bs_fiscal_periods`:** `year, month, status(open/closed), closed_at` — kunci periode.
- **Laporan GL:** Buku Besar (`ledger`), Neraca Saldo (`trialbalance`), Neraca & L/R (`financials`), Arus Kas (`cashflow`), Neraca Lajur (`worksheet`), Komparasi (`comparative`), **GL Lanjutan** (`extra`: komparasi tahunan / multi-periode / histori saldo).
- **Endpoint:** `bs.gl.{index,period.close,period.open,journal.index,journal.create,journal.store,journal.show,journal.finalize,journal.approve,journal.reject,journal.import,journal.import.store,extra,ledger,trialbalance,financials,cashflow,worksheet,comparative}`.

## 25. Hutang — AP (`bs_ap_invoices` + lines/schedules/payments/kuitansis/memorials/berkas, `bs_ap_categories`)
- **Tujuan:** kelola hutang usaha (invoice supplier → bayar → pajak).
- **`bs_ap_invoices`:** `invoice_number*, supplier_invoice_no*(WAJIB), invoice_date*, due_date, supplier_id*, category_id, trx_type(normal/denda/saldo_awal), description, dpp(money)*, ppn_mode(none/with), ppn_pct, ppn_amount, pph_mode(none/withheld), pph_pct, pph_amount, pph_account_id, total, net_payable, paid_amount, status*(open/paid), journal_id, expense/payable_account_id, kurs, transfer_detail, tax_invoice_no(NSFP), unit_id?, customer_id?` (soft).
- **Anak:** `..._lines` (uraian: qty,unit_price,amount), `..._schedules` (jadwal: installment_no, due_date, amount, paid_amount, status), `..._payments`.
- **Kategori:** `bs_ap_categories (code,name, debit_account_id, payable_account_id)` → menentukan akun jurnal.
- **Kuitansi Hutang** (`bs_ap_kuitansis`+lines): voucher bayar multi-invoice (`kuitansi_number, supplier_id, cash_account_id, alat_transaksi(tunai/transfer/giro), kasir, total, status, journal_id, void_journal_id`) — bisa **void**.
- **Memorial** (`bs_ap_memorials`): penyesuaian non-tunai (`trx_type, counter_account_id, amount, is_writeoff`) — termasuk **Penghapusan** (write-off).
- **Kelengkapan Berkas** (`bs_ap_berkas` master + `bs_ap_invoice_berkas` checklist `is_complete`).
- **Jurnal:** Invoice → **Dr Beban/Persediaan + Dr PPN Masukan / Cr Hutang Usaha**; Bayar/Kuitansi → **Dr Hutang / Cr Kas**.
- **Penomoran:** `AP.INV/<KATEGORI>/MUP/YY/<ROMAWI>/NNNNN`, `AP.KUI/MUP/YY/<ROMAWI>/NNNNN`.
- **Endpoint:** `bs.ap.{index,create,store,aging,card,categories.*,kuitansi.*,posting.{po,spk},memorial.*,penghapusan.*,berkas.*,show,pay}`.

## 26. Piutang — AR (`bs_ar_invoices` + lines/schedules/payments/kuitansis/memorials, `bs_ar_categories`)
- **Tujuan:** kelola piutang usaha (tagih → terima → reschedule).
- **`bs_ar_invoices`:** mirror AP: `invoice_number*, customer_id*, category_id, trx_type, dpp*, ppn_mode/pct/amount, pph_mode/pct/amount, total, net_receivable, paid_amount, status*(open/paid), income/receivable_account_id, customer_ref_no, tax_invoice_no, journal_id` (soft) + lines/schedules/payments.
- **Kategori:** `bs_ar_categories (income_account_id, receivable_account_id)`.
- **Reschedule Jatuh Tempo:** jadwal ulang **sisa** piutang (angsuran terbayar **dikunci**, sisa di-split jadi termin baru, header due-date tersinkron) — **tanpa jurnal** (hanya timing).
- **Kuitansi Piutang** (`bs_ar_kuitansis`): penerimaan multi-invoice + **void**. **Memorial Piutang**: penyesuaian + **Penghapusan**.
- **Jurnal:** Invoice → **Dr Piutang (+ Dr PPh dibayar dimuka) / Cr Pendapatan + Cr PPN Keluaran**; Terima → **Dr Kas / Cr Piutang**.
- **Endpoint:** `bs.ar.{index,create,store,aging,card,categories.*,kuitansi.*,memorial.*,penghapusan.*,show,pay,reschedule}`.

## 27. Kas & Bank (`bs_cash_transactions`)
- **Tujuan:** mutasi kas/bank harian.
- **Field:** `trx_number*, trx_type*(in/out/transfer), trx_date*, cash_account_id*, counter_account_id*(≠cash), amount(money)*, payee, description, reference, journal_id` (soft).
- **Jurnal:** Masuk → **Dr Kas/Bank / Cr counter**; Keluar → **Dr counter / Cr Kas/Bank**.
- **Endpoint:** `bs.cashbook.{index,balances,create,store,show}`.

## 28. Kas Kecil — Petty Cash (`bs_cash_transactions` akun KAS KECIL)
- **Tujuan:** kas kecil operasional (top-up & belanja) memakai akun `10101010102 KAS KECIL`.
- **Field input:** `trx_type(in/out), trx_date, counter_account_id*, amount*, payee, description` (cash_account dipaksa = Kas Kecil).
- **Jurnal:** Top-up → **Dr Kas Kecil / Cr Bank**; Belanja → **Dr Beban / Cr Kas Kecil**.
- **Endpoint:** `bs.pettycash.{index,create,store}`.

## 29. Bon Sementara / Uang Muka (`bs_cash_advances`, `bs_cash_advance_lines`)
- **Tujuan:** panjar ke pemegang → realisasi → selesaikan sisa/kekurangan.
- **Field:** `advance_number*, advance_date*, holder*, purpose*, cash_account_id*, advance_account_id, amount(money)*, status*(outstanding/realized), realized_date, realized_amount, settlement, settlement_status(null/pending/settled), journal_id, realize_journal_id, settle_journal_id` (soft). Lines realisasi: `expense_account_id, description, amount`.
- **Alur:** **Terbit** (Dr Uang Muka / Cr Kas) → **Realisasi** (Dr Beban / Cr Uang Muka; opsi **tunda penyelesaian**) → **Refund** (sisa: Dr Kas / Cr Uang Muka) **atau** **Reimburse** (kekurangan: Dr Uang Muka / Cr Kas).
- **Endpoint:** `bs.advance.{index,create,store,show,realize,refund,reimburse}`.

## 30. Cek / Giro Masuk (`bs_giros`)
- **Tujuan:** kelola cek/giro dari customer hingga cair/tolak.
- **Field:** `giro_number*, receive_date*, due_date*, bank_name*, cheque_number, drawer*, amount(money)*, giro_account_id, counter_account_id*, cash_account_id, status*(in_hand/cleared/bounced), settled_date, receive_journal_id, settle_journal_id` (soft).
- **Alur:** **Terima** (Dr Piutang Giro / Cr counter) → **Kliring** (Dr Bank / Cr Piutang Giro) **atau** **Tolak/bounce** (balik).
- **Endpoint:** `bs.giro.{index,create,store,show,clear,bounce}`.

## 31. Rekonsiliasi Bank (`bs_bank_reconciliations`, `bs_bank_rec_lines`)
- **Tujuan:** cocokkan buku (Cash Book/GL) dengan rekening koran.
- **Field:** `account_id*, statement_date, statement_balance(money), book_balance(money), status, reconciled_at`. Lines: `journal_line_id, tx_date, debit, credit, is_cleared`.
- **Alur:** pilih akun + saldo koran → centang baris cleared → **finalize** (saldo cocok).
- **Endpoint:** `bs.bankrec.{index,store,show,toggle,finalize}`.

## 32. Aset Tetap (`bs_fixed_assets`, `bs_depreciation_entries`)
- **Tujuan:** kelola aset, penyusutan, pelepasan.
- **Field:** `asset_number*, name*, category*(bangunan/kendaraan/inventaris/tanah), acquisition_date*, acquisition_cost(money)*, residual_value, useful_life_months, method(straight_line), asset/accum/expense_account_id, accumulated_depreciation, last_depreciation_date, status*(active/fully_depreciated/disposed/sold), acquire_journal_id, **disposed_date, disposal_type(write_off/sale), disposal_proceeds, disposal_note, disposal_journal_id**` (soft). Entri: `bs_depreciation_entries (period_year/month, amount, book_value_after, journal_id)`.
- **Alur & Jurnal:** Perolehan (Dr Aset / Cr dana) → **Penyusutan** garis lurus per periode/batch (Dr Beban / Cr Akumulasi) → **Penghapusan** (Dr Akum + Dr Rugi / Cr Aset) / **Penjualan** (Dr Kas+Akum / Cr Aset + Laba|−Rugi) / **Saldo Awal Penyusutan** (Dr Laba Ditahan / Cr Akumulasi).
- **Penomoran:** `FA/YYMM/NNNN`.
- **Endpoint:** `bs.fa.{index,create,store,run,run.store,show,depreciate,dispose,sell,opening}`.

## 33. Persediaan / Stock (`bs_items`, `bs_stock_categories`, `bs_uoms`, `bs_warehouses`, `bs_material_requests`+lines, `bs_stock_transactions`+lines, `bs_stock_movements`)
- **Tujuan:** kelola barang/material proyek + kartu stok.
- **Master:** `bs_items (code,name,brand,type,min_stock, stock_category_id, uom_id)`, `bs_stock_categories`, `bs_uoms`, `bs_warehouses (project_id, code, name)`.
- **FPB / Material Request** (`bs_material_requests`): `mr_number, request_date, needed_date, department, status, approved_at/by` + lines (`item_id, qty, kind, spec`) → **approve**.
- **Transaksi** (`bs_stock_transactions`): `trx_number, trx_type(receipt/issue), warehouse_id, supplier_id, purchase_order_id, material_request_id` + lines → **`bs_stock_movements`** (kartu stok: `direction(in/out), qty, unit_price, balance_after, ref_*`).
- **Jurnal:** Penerimaan → **Dr Persediaan / Cr ...**; Pengeluaran → **Dr Beban/CIP / Cr Persediaan**.
- **Endpoint:** `bs.items.*, bs.inv.setup.*, bs.fpb.{index,create,store,show,approve}, bs.receipt.*, bs.issue.*, bs.stockcard.index`.

## 34. Pembelian / PO (`bs_purchase_orders`, `bs_purchase_order_lines`)
- **Tujuan:** order pembelian ke supplier.
- **Field:** `po_number*, project_id, supplier_id*, material_request_id?, po_date*, due_date, po_type(barang/jasa), term_payment, subtotal(money), ppn_pct/amount, pph_pct, total, status*(draft/approved/closed), **dp_amount, dp_account_id, dp_journal_id, revision_count, revised_at**` (soft). Lines: `item_id, qty, qty_received, unit_price, amount`.
- **Alur:** Buat → **approve** → **close**; **Revisi** (edit baris + hitung ulang, revision_count++); **Posting Termin** (uang muka: **Dr Uang Muka Pembelian / Cr Kas**); **Adjust PPN** (set ulang % + total).
- **Penomoran:** `PO/JRES/YYMM/NNNN`.
- **Endpoint:** `bs.po.{index,create,store,show,approve,close,revise,termin,adjust-ppn}`.

## 35. Kontraktor / SPK (`bs_work_orders` + progress/addendums/materials/progress_mutations)
- **Tujuan:** kelola kontrak kerja kontraktor (progress, retensi, addendum, dll.).
- **`bs_work_orders`:** `spk_number*, spk_date*, supplier_id*, cost_center_id, construction_account_id*(CIP), title*, contract_value(money)*, addendum_value, retention_pct, ppn_pct, progress_value, material_used, progress_mutated, retention_held, status*(active/closed/cancelled), retention_released_at, cancelled_at, cancel_reason` (soft).
- **Anak:** `..._progress` (termin: `progress_value, retention_amount, ppn_amount, net_payable, status(unpaid/paid), journal_id, pay_journal_id`), `..._addendums` (`kind(value/ppn), value_delta, old/new_contract_value, old/new_ppn_pct`), `..._materials` (`usage_date, amount, inventory_account_id, journal_id`), `..._progress_mutations` (`amount, from/to_account_id, from/to_cost_center_id, journal_id`).
- **Alur & Jurnal:** **Progress** (Dr CIP + PPN Masukan / Cr Hutang + Hutang Retensi) → **Bayar** (Dr Hutang / Cr Kas) → **Cairkan Retensi** (Dr Hutang Retensi / Cr Kas, SPK closed). **Addendum** (± nilai kontrak; guard: ≥ progress terpakai). **Adjust PPN** (progress berikutnya). **Pembatalan** (guard: tak ada termin belum-bayar & retensi). **Pemakaian Barang** (Dr CIP / Cr Persediaan). **Mutasi Progress** (reklasifikasi: Dr CIP-tujuan / Cr CIP-asal; guard: ≤ progress belum dimutasi).
- **Penomoran:** `SPK/YYMM/NNNN`.
- **Endpoint:** `bs.spk.{index,create,store,show,progress,progress.pay,retensi,addendum,adjust-ppn,cancel,material,mutate-progress}`.

## 36. Budget Control (`bs_budgets`, `bs_budget_mutations`)
- **Tujuan:** anggaran per tahun/akun/cost-center + workflow + realisasi.
- **`bs_budgets`:** `year*, cost_center_id?, account_id*, amount(money)*, notes, status*(draft/submitted/approved/rejected), submitted_at, approved_at, approved_by, approval_note`.
- **`bs_budget_mutations`:** `year, kind(transfer/koreksi), from/to_budget_id, amount, mutation_date, note`.
- **Alur:** **Alokasi** → **Ajukan** (submit) → **Setujui/Tolak** (approve) → **Mutasi** (transfer antar baris / koreksi). Tampilan **budget vs realisasi** (realisasi = Σ jurnal beban posted per akun/cost-center). **Upload Massal** (lihat §40).
- **Endpoint:** `bs.budget.{index,store,update,destroy,submit,approve,mutate}`.

## 37. COGS / HPP (`bs_cogs_recognitions`)
- **Tujuan:** akui beban pokok penjualan (manual + RealCost per unit).
- **Field:** `cogs_number*, recognition_date*, reference, description, inventory_account_id*, cogs_account_id, cost_center_id, amount(money)*, journal_id` (soft).
- **RealCost engine:** **Generate** → hitung **pool CIP** (saldo akun konstruksi ≤ tanggal) ÷ alokasi ke unit proyek (dasar **rata** / **proporsi harga**); **Posting** → akui HPP hanya untuk unit **terjual & belum diakui** (idempoten via `reference`=kode unit).
- **Jurnal:** **Dr Beban Pokok Penjualan / Cr Persediaan/CIP**.
- **Penomoran:** `HPP/YYMM/NNNN`.
- **Endpoint:** `bs.cogs.{index,store,realcost,realcost.post}`.

## 38. Payroll & Karyawan (`bs_employees`, `bs_payroll_runs`, `bs_payroll_lines`)
- **Tujuan:** master karyawan + proses penggajian + jurnal.
- **`bs_employees`:** `nik*, name*, position, cost_center_id, join_date, base_salary(money), allowance, bank_name, bank_account, is_active` (soft).
- **`bs_payroll_runs`:** `run_number*, period_year/month*, run_date*, total_bruto/deduction/net, status, journal_id` (soft). Lines per karyawan: `gaji_pokok, tunjangan, pph21, bpjs, potongan_lain, bruto, net`.
- **Alur:** Run periode (% PPh21 + % BPJS) → hitung semua karyawan aktif → **jurnal otomatis**.
- **Jurnal:** **Dr Beban Gaji & Tunjangan / Cr Hutang PPh21 + Cr Hutang BPJS + Cr Kas**.
- **Penomoran:** `PAY/YYMM/NNNN`.
- **Endpoint:** `bs.employees.*, bs.payroll.{index,store,show}`.

## 39. Pajak — Tax (`bs.tax.*`; data dari `bs_ap_invoices`/`bs_ar_invoices` field pajak)
- **Tujuan:** kelola PPN/PPh, e-Faktur, SPT Masa.
- **Faktur Keluaran/Masukan + Rekap:** dari invoice AR/AP ber-PPN; set **NSFP** (`tax_invoice_no`).
- **e-Faktur:** export **CSV & XML** (skema impor DJP). **SPT Masa:** cetak **PPN**, **PPh**, **Buku Pembantu Pajak**. **PPh** rekap (Pasal 21/23/4(2)) + export. **Dashboard Pajak** (PPN/PPh 12 bulan + jatuh tempo setor/lapor).
- **Endpoint:** `bs.tax.{rekap,keluaran,masukan,nsfp,export.keluaran,export.masukan,cetak.ppn,cetak.buku,cetak.pph,efaktur.csv,efaktur.xml,dashboard,pph,pph.export}`.

## 40. Tutup Buku & Saldo Awal (`bs_fiscal_periods`; Closing service)
- **Tutup Bulanan** (`close`): kunci periode (`status=closed`) → tak bisa tambah jurnal; **Buka kembali** (`reopen`).
- **Tutup Tahunan** (`year`): **jurnal penutup** L/R → **Dr Pendapatan / Cr Beban / (Cr|Dr) Laba Ditahan** (laba/rugi).
- **Neraca Awal** (`neraca`): carry-forward Laba Ditahan tahun berjalan. **Jurnal Saldo Awal** (`saldoawal` + CSV).
- **Endpoint:** `bs.closing.{index,neraca,saldoawal,saldoawal.csv,close,reopen,year}`, `bs.gl.period.{close,open}`.

## 41. Upload Massal & Proyeksi (`bs.bulk.*`, `bs.projection`)
- **Upload Massal CSV** (3 mode): **Aset** (registrasi massal), **Anggaran** (alokasi massal), **Saldo Awal Stok** (Dr Persediaan / Cr Laba Ditahan) — dengan **dry-run** + laporan error per-baris.
- **Proyeksi Hutang/Piutang:** bucket Jatuh Tempo (overdue) + 12 bulan ke depan, per supplier/customer.
- **Endpoint:** `bs.bulk.{form,store}`, `bs.projection`.

---

# BAGIAN IV — SECURITY (SDM LAPANGAN), SETUP SALES & SETTINGS

## 42. Security — Modul SDM Lapangan (`sec_attendances`, `sec_job_histories`, `sec_technical_problems`, `sec_user_companies`, `sec_user_projects`)
- **Tujuan:** manajemen petugas/keamanan eProperty (berbeda dari RBAC sistem).
- **Absensi** (`sec_attendances`): `employee_id, date, clock_in, clock_out, status, note`. **Riwayat Jabatan** (`sec_job_histories`): `position, division, start/end_date`. **Technical/Tiket** (`sec_technical_problems`): `ticket_no, title, module, priority, status, reported_by, assigned_to, resolved_at`. **Matrikulasi** company/project (`sec_user_companies`, `sec_user_projects`): pemetaan akses user ke company/project.
- **Endpoint:** `security.{index,log,matrikulasi.company,matrikulasi.project,matrikulasi.toggle,absensi,absensi.store,jobhistory,jobhistory.store,technical,technical.store,technical.update}`.

## 43. Setup Sales (`payment_schemes`+terms, `sales_persons`, `agents`+`agent_types`, `pricelists`, `data_sources`, `activity_logs`)
- **Tujuan:** master & konfigurasi penjualan.
- **Skema Pembayaran** (`payment_schemes`): `code,name,type, down_payment_pct, installment_months, discount_pct, present_value, tiered_discounts(json), booking_fee, bf_treatment, dp_count/basis/value, inst_count/basis/value, schedule_first/interval/unit` + terms.
- **Sales** (`sales_persons`: user_id, code, name, manager_id). **Agent** (`agents`: code,name, agent_type_id, npwp, bank_*, parent_agent_id).
- **Sumber Data** (`data_sources`), **Activity Log** (`activity_logs` polimorfik: subject, category, description, attachment).
- **Endpoint:** `setup-sales.*, scheme.*, sales.{index,store,update,destroy}, agent.*, pricelist.*, reports.*, calculator.*, activity.*, audit.record`.

## 44. Settings — Administrasi Sistem (`users`, `roles`, `permissions`, `numerators`, `parameters`, `companies`, `notification_*`, `mfa_secrets`, `password_histories`, `sessions`)
- **User** (`users`): `name, email*, username, phone, password(hash), avatar_path, employee_nip, is_active, is_blocked, failed_login_count, blocked_until, dark_mode, locale, last_login_at/ip` (soft) — + **MFA** (`mfa_secrets`), **histori password** (`password_histories`), **sesi** (`sessions`).
- **Role & Permission:** §F3 (RBAC). **Numerator:** §F5. **Parameter:** §F6. **Company** (`companies`: code,name,npwp). **Notifikasi:** `notification_templates`, `notification_preferences`, `notifications_outbox` (event,channel,payload,status).
- **Keamanan login:** kunci akun setelah gagal beruntun (`failed_login_count`, `blocked_until`), wajib ganti password, MFA opsional.

---

## Penutup
Dokumen ini melengkapi **PRD utama** dengan rincian teknis tiap modul (field, status, alur, aturan, jurnal, penomoran, endpoint). Untuk gambaran tingkat tinggi & diagram, lihat `PRD-Java-Core-System`. Untuk kebenaran mutlak, rujuk kode (`app/Domain`), skema (`docs/_schema.txt`), dan route (`routes/web.php`).

*— Java Core System · 2026-06-04 —*
