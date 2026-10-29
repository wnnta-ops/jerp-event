<?php

namespace App\Modules\Eprop\Support;

/**
 * Peta tabel untuk sinkron eProperty → J-ERP.
 *
 * Basis data BAYANGAN dibangun dari salinan J-ERP lalu diisi ulang dengan skrip migrasi yang
 * sama dari kiriman eProperty terbaru. Karena itu:
 *  - baris MASTER (akun, cost center, supplier, unit, agen, skema, …) ber-id SAMA di bayangan dan
 *    J-ERP — kecuali master baru dari eProperty, yang disalin apa adanya bila id-nya belum ada;
 *  - baris DOKUMEN dikosongkan lalu dimuat ulang di bayangan, jadi id-nya BERBEDA dan harus
 *    dipetakan lewat kunci alaminya (nomor dokumen eProperty, yang juga dipakai migrasi).
 *
 * Kunci konfigurasi per dokumen:
 *  db       'fo' (jerp) / 'bo' (ipro_bo)
 *  kunci    kolom nomor dokumen (kunci alami, satu kolom)
 *  ref      kolom → tabel dokumen lain (dipetakan lewat kunci alami tabel itu)
 *  master   kolom → tabel master (id sama; disalin bila belum ada)
 *  anak     baris rincian: [tabel, fk ke induk, kunci_anak (dicocokkan di tempat) | null (ganti semua), ref, master,
 *           nominal = baris rincian baru dicek dulu terhadap input J-ERP bernominal sama di induk yang sama]
 *  refPoli  ref yang tabel tujuannya ditentukan kolom lain: kolom => [kolom_jenis, [nilai_jenis => tabel]]
 *  tunda    ref yang diisi sesudah semua dokumen tersalin (referensi melingkar, mis. TTS ↔ Kwitansi)
 *  jaga     kolom yang nilainya di J-ERP tidak pernah ditimpa (diisi/diolah J-ERP sendiri)
 *  perbarui true = perubahan di eProperty atas dokumen yang SUDAH ada ikut diterapkan
 *  cocok    pencocokan dengan input J-ERP (nomor beda): nominal, pihak, pihak_wajib, teks, tanggal
 *  label    kolom yang dirangkai menjadi ringkasan di laporan
 */
final class DaftarTabel
{
    /** Kolom yang tidak ikut dibandingkan / disalin sebagai isi. */
    public const SISTEM = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    /** Urutan proses (induk sebelum yang merujuknya). */
    public static function dokumen(): array
    {
        return [
            // ── FO ──────────────────────────────────────────────────────────
            'fo_customers' => [
                'db' => 'fo', 'modul' => 'Customer', 'kunci' => 'code',
                'master' => ['project_id' => 'projects', 'bank_id' => 'banks'],
                'perbarui' => false,
                'cocok' => ['pihak' => 'nik', 'pihak_wajib' => true, 'teks' => 'full_name'],
                'label' => ['full_name', 'nik'],
            ],
            'fo_reservasi' => [
                'db' => 'fo', 'modul' => 'Reservasi', 'kunci' => 'no_reservasi',
                'ref' => ['customer_id' => 'fo_customers'], 'master' => ['unit_id' => 'fo_units'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'booking_fee', 'pihak' => 'unit_id', 'pihak_wajib' => true, 'tanggal' => 'tgl_reservasi'],
                'label' => ['unit_id', 'booking_fee'],
            ],
            'fo_kontrak' => [
                'db' => 'fo', 'modul' => 'Kontrak (SPR)', 'kunci' => 'no_kontrak',
                'ref' => ['reservasi_id' => 'fo_reservasi', 'customer_id' => 'fo_customers'],
                'master' => ['unit_id' => 'fo_units', 'skema_id' => 'fo_skema', 'agent_id' => 'fo_agents'],
                'anak' => [
                    ['tabel' => 'fo_kontrak_tagihan', 'fk' => 'kontrak_id', 'kunci' => ['urutan'], 'jaga' => ['pelunasan']],
                    ['tabel' => 'fo_kontrak_skema_line', 'fk' => 'kontrak_id', 'kunci' => null],
                    ['tabel' => 'fo_kontrak_gimmick', 'fk' => 'kontrak_id', 'kunci' => null, 'master' => ['gimmick_id' => 'fo_gimmicks']],
                ],
                'jaga' => ['data_lama', 'no_kontrak_sementara'],
                'perbarui' => true,
                // pihak_juga: SPR baru dianggap SAMA bila customer-nya juga sama — unit + nominal saja
                // terlalu longgar (unit bisa berpindah pembeli). Beda customer → ditahan, dicek manual.
                'cocok' => ['nominal' => 'total_kontrak', 'pihak' => 'unit_id', 'pihak_wajib' => true, 'pihak_juga' => ['customer_id'], 'tanggal' => 'tgl_kontrak'],
                'label' => ['unit_id', 'total_kontrak', 'cara_bayar'],
            ],
            'fo_tts' => [
                'db' => 'fo', 'modul' => 'TTS', 'kunci' => 'no_tts',
                'ref' => ['customer_id' => 'fo_customers', 'kontrak_id' => 'fo_kontrak'],
                'tunda' => ['bkm_id' => 'fo_bkm'], 'master' => ['bank_id' => 'banks'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'kontrak_id', 'teks' => 'keterangan', 'tanggal' => 'tgl_tts'],
                'label' => ['jenis_pembayaran', 'amount', 'keterangan'],
            ],
            'fo_bkm' => [
                'db' => 'fo', 'modul' => 'Kwitansi (BKM FO)', 'kunci' => 'no_bkm',
                'ref' => ['tts_id' => 'fo_tts', 'customer_id' => 'fo_customers', 'kontrak_id' => 'fo_kontrak'],
                'refAnak' => ['kontrak_tagihan_id' => ['fo_kontrak_tagihan', 'kontrak_id']],
                'master' => ['bank_id' => 'banks'],
                'perbarui' => false,
                'tambahPelunasan' => true,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'kontrak_id', 'teks' => 'keterangan', 'tanggal' => 'tgl_bkm'],
                'label' => ['jenis_pembayaran', 'amount', 'keterangan'],
            ],
            'fo_memo' => [
                'db' => 'fo', 'modul' => 'Memo FO', 'kunci' => 'no_memo',
                'ref' => ['customer_id' => 'fo_customers', 'kontrak_id' => 'fo_kontrak'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'kontrak_id', 'teks' => 'keterangan', 'tanggal' => 'tgl_memo'],
                'label' => ['jenis', 'amount', 'keterangan'],
            ],
            'fo_komisi' => [
                'db' => 'fo', 'modul' => 'Komisi', 'kunci' => 'no_komisi',
                'ref' => ['kontrak_id' => 'fo_kontrak'], 'master' => ['agent_id' => 'fo_agents'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'kontrak_id', 'pihak_wajib' => true, 'tanggal' => 'tgl_komisi'],
                'label' => ['agent_id', 'amount', 'status'],
            ],
            'fo_blt' => [
                'db' => 'fo', 'modul' => 'BLT', 'kunci' => 'no_blt',
                'ref' => ['kontrak_id' => 'fo_kontrak'], 'master' => ['agent_id' => 'fo_agents', 'sales_level_id' => 'fo_sales_levels'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'nilai_blt', 'pihak' => 'kontrak_id', 'pihak_wajib' => true, 'teks' => 'level_type', 'tanggal' => 'tgl_blt'],
                'label' => ['level_type', 'nilai_blt', 'status'],
            ],
            'fo_refund' => [
                'db' => 'fo', 'modul' => 'Refund FO', 'kunci' => 'no_refund',
                'ref' => ['customer_id' => 'fo_customers', 'kontrak_id' => 'fo_kontrak', 'reservasi_id' => 'fo_reservasi'],
                'master' => ['bank_id' => 'banks'], 'perbarui' => true,
                'cocok' => ['nominal' => 'nilai_refund', 'pihak' => 'kontrak_id', 'tanggal' => 'tgl_refund'],
                'label' => ['jenis', 'nilai_refund', 'keterangan'],
            ],
            'fo_pengalihan_hak' => [
                'db' => 'fo', 'modul' => 'Pengalihan Hak', 'kunci' => 'no_pengalihan',
                'ref' => ['kontrak_id' => 'fo_kontrak', 'customer_lama_id' => 'fo_customers', 'customer_baru_id' => 'fo_customers'],
                'perbarui' => true,
                'cocok' => ['pihak' => 'kontrak_id', 'pihak_wajib' => true, 'tanggal' => 'tgl_pengalihan'],
                'label' => ['kontrak_id', 'status'],
            ],
            'fo_pindah_unit' => [
                'db' => 'fo', 'modul' => 'Pindah Unit', 'kunci' => 'no_pindah',
                'ref' => ['kontrak_id' => 'fo_kontrak', 'customer_id' => 'fo_customers'],
                'master' => ['unit_lama_id' => 'fo_units', 'unit_baru_id' => 'fo_units'], 'perbarui' => true,
                'cocok' => ['pihak' => 'kontrak_id', 'pihak_wajib' => true, 'tanggal' => 'tgl_pindah'],
                'label' => ['kontrak_id', 'status'],
            ],

            // ── BO ──────────────────────────────────────────────────────────
            // SPK sebelum AP: invoice progress SPK merujuk baris progress-nya.
            'bs_work_orders' => [
                'db' => 'bo', 'modul' => 'SPK', 'kunci' => 'spk_number',
                'master' => ['supplier_id' => 'bs_suppliers', 'cost_center_id' => 'bs_cost_centers', 'construction_account_id' => 'bs_accounts'],
                'anak' => [
                    ['tabel' => 'bs_work_order_items', 'fk' => 'work_order_id', 'kunci' => null],
                    ['tabel' => 'bs_work_order_terms', 'fk' => 'work_order_id', 'kunci' => null],
                    ['tabel' => 'bs_work_order_units', 'fk' => 'work_order_id', 'kunci' => null],
                    ['tabel' => 'bs_work_order_progress', 'fk' => 'work_order_id', 'kunci' => ['kpl_number', 'progress_date'],
                        'ref' => ['journal_id' => 'bs_journals', 'pay_journal_id' => 'bs_journals'], 'nominal' => 'progress_value'],
                ],
                'perbarui' => true,
                'cocok' => ['nominal' => 'contract_value', 'pihak' => 'supplier_id', 'teks' => 'title', 'tanggal' => 'spk_date'],
                'label' => ['title', 'contract_value'],
            ],
            'bs_ap_invoices' => [
                'db' => 'bo', 'modul' => 'AP Invoice', 'kunci' => 'invoice_number',
                'ref' => ['journal_id' => 'bs_journals'],
                // Invoice progress/retensi SPK: source_id = id baris progress SPK (dipetakan lewat nomor SPK + KPL).
                'refPoli' => ['source_id' => ['source_type', ['spk' => 'bs_work_order_progress']]],
                'master' => ['supplier_id' => 'bs_suppliers', 'category_id' => 'bs_ap_categories', 'expense_account_id' => 'bs_accounts',
                    'payable_account_id' => 'bs_accounts', 'pph_account_id' => 'bs_accounts'],
                'anak' => [
                    ['tabel' => 'bs_ap_invoice_lines', 'fk' => 'ap_invoice_id', 'kunci' => null],
                    ['tabel' => 'bs_ap_invoice_schedules', 'fk' => 'ap_invoice_id', 'kunci' => null],
                ],
                'perbarui' => true,
                'cocok' => ['nominal' => 'total', 'pihak' => 'supplier_id', 'teks' => 'description', 'tanggal' => 'invoice_date'],
                'label' => ['description', 'total'],
            ],
            'bs_ap_kuitansis' => [
                'db' => 'bo', 'modul' => 'Kuitansi AP', 'kunci' => 'kuitansi_number',
                'ref' => ['journal_id' => 'bs_journals', 'void_journal_id' => 'bs_journals'],
                'master' => ['supplier_id' => 'bs_suppliers', 'cash_account_id' => 'bs_accounts'],
                // Pembayaran AP tidak punya nomor sendiri: reference = nomor kuitansinya.
                'anak' => [
                    ['tabel' => 'bs_ap_payments', 'fk' => null, 'pengikat' => ['reference' => 'kuitansi_number'], 'kunci' => null,
                        'ref' => ['ap_invoice_id' => 'bs_ap_invoices', 'journal_id' => 'bs_journals'], 'master' => ['cash_account_id' => 'bs_accounts']],
                    ['tabel' => 'bs_ap_kuitansi_lines', 'fk' => 'kuitansi_id', 'kunci' => null,
                        'ref' => ['ap_invoice_id' => 'bs_ap_invoices'], 'refPembayaran' => 'payment_id'],
                ],
                'perbarui' => true,
                'cocok' => ['nominal' => 'total', 'pihak' => 'supplier_id', 'teks' => 'description', 'tanggal' => 'kuitansi_date'],
                'label' => ['description', 'total'],
            ],
            'bs_ap_memorials' => [
                'db' => 'bo', 'modul' => 'Memo AP', 'kunci' => 'memorial_number',
                'ref' => ['ap_invoice_id' => 'bs_ap_invoices', 'journal_id' => 'bs_journals'],
                'master' => ['supplier_id' => 'bs_suppliers', 'counter_account_id' => 'bs_accounts', 'payable_account_id' => 'bs_accounts'],
                'perbarui' => false,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'supplier_id', 'teks' => 'description', 'tanggal' => 'memorial_date'],
                'label' => ['description', 'amount'],
            ],
            'bs_cash_transactions' => [
                'db' => 'bo', 'modul' => 'Kas & Bank (BKK/BKM)', 'kunci' => 'trx_number',
                'ref' => ['journal_id' => 'bs_journals'],
                'master' => ['cash_account_id' => 'bs_accounts', 'counter_account_id' => 'bs_accounts'],
                'anak' => [['tabel' => 'bs_cash_transaction_lines', 'fk' => 'cash_transaction_id', 'kunci' => null,
                    'master' => ['account_id' => 'bs_accounts', 'cost_center_id' => 'bs_cost_centers']]],
                'perbarui' => false,
                'cocok' => ['nominal' => 'amount', 'teks' => 'description', 'tanggal' => 'trx_date', 'jenis' => 'trx_type'],
                'label' => ['trx_type', 'payee', 'description', 'amount'],
            ],
            'bs_cash_advances' => [
                'db' => 'bo', 'modul' => 'Bon Sementara', 'kunci' => 'advance_number',
                'ref' => ['journal_id' => 'bs_journals', 'realize_journal_id' => 'bs_journals', 'settle_journal_id' => 'bs_journals'],
                'master' => ['cash_account_id' => 'bs_accounts', 'advance_account_id' => 'bs_accounts', 'cost_center_id' => 'bs_cost_centers'],
                'anak' => [['tabel' => 'bs_cash_advance_lines', 'fk' => 'cash_advance_id', 'kunci' => null,
                    'master' => ['expense_account_id' => 'bs_accounts', 'cost_center_id' => 'bs_cost_centers']]],
                // Cost center bon diisi di J-ERP (per tim) — tidak ditimpa nilai kosong dari eProperty.
                'jaga' => ['cost_center_id', 'received_document_date', 'realisasi_document_date', 'approvals', 'created_by'],
                'perbarui' => true,
                'cocok' => ['nominal' => 'amount', 'pihak' => 'holder', 'pihak_wajib' => true, 'pihak_teks' => true, 'teks' => 'purpose', 'tanggal' => 'advance_date'],
                'label' => ['holder', 'purpose', 'amount'],
            ],
            'bs_material_requests' => [
                'db' => 'bo', 'modul' => 'Permintaan Barang', 'kunci' => 'mr_number',
                'master' => ['cost_center_id' => 'bs_cost_centers'],
                'anak' => [['tabel' => 'bs_material_request_lines', 'fk' => 'material_request_id', 'kunci' => null, 'master' => ['item_id' => 'bs_items']]],
                'perbarui' => true,
                'cocok' => ['teks' => 'notes', 'tanggal' => 'request_date'],
                'label' => ['department', 'notes'],
            ],
            'bs_purchase_orders' => [
                'db' => 'bo', 'modul' => 'Purchase Order', 'kunci' => 'po_number',
                'ref' => ['material_request_id' => 'bs_material_requests', 'dp_journal_id' => 'bs_journals'],
                'master' => ['supplier_id' => 'bs_suppliers', 'dp_account_id' => 'bs_accounts'],
                'anak' => [['tabel' => 'bs_purchase_order_lines', 'fk' => 'purchase_order_id', 'kunci' => null, 'master' => ['item_id' => 'bs_items']]],
                'perbarui' => true,
                'cocok' => ['nominal' => 'total', 'pihak' => 'supplier_id', 'teks' => 'notes', 'tanggal' => 'po_date'],
                'label' => ['notes', 'total'],
            ],
            'bs_stock_transactions' => [
                'db' => 'bo', 'modul' => 'Transaksi Stok', 'kunci' => 'trx_number',
                'ref' => ['purchase_order_id' => 'bs_purchase_orders', 'material_request_id' => 'bs_material_requests'],
                'master' => ['warehouse_id' => 'bs_warehouses', 'supplier_id' => 'bs_suppliers'],
                'anak' => [['tabel' => 'bs_stock_transaction_lines', 'fk' => 'stock_transaction_id', 'kunci' => null, 'master' => ['item_id' => 'bs_items']]],
                'perbarui' => false,
                'cocok' => ['teks' => 'notes', 'tanggal' => 'trx_date', 'jenis' => 'trx_type'],
                'label' => ['trx_type', 'reference', 'notes'],
            ],
            'kpr_applications' => [
                'db' => 'bo', 'modul' => 'KPR', 'kunci' => 'application_number',
                'master' => ['booking_id' => 'bookings', 'customer_id' => 'customers', 'bank_partner_id' => 'bank_partners'],
                'anak' => [
                    ['tabel' => 'kpr_sp3k', 'fk' => 'kpr_application_id', 'kunci' => ['sp3k_number']],
                    ['tabel' => 'kpr_akads', 'fk' => 'kpr_application_id', 'kunci' => null],
                    ['tabel' => 'kpr_realizations', 'fk' => 'kpr_application_id', 'kunci' => ['reference_no', 'retention_category_id'],
                        'ref' => ['journal_id' => 'bs_journals'], 'nominal' => 'disbursed_amount'],
                ],
                'perbarui' => true,
                'cocok' => ['pihak' => 'booking_id', 'pihak_wajib' => true, 'tanggal' => 'submitted_at'],
                'label' => ['booking_id', 'status', 'plafon_approved'],
            ],
            'kpr_initiations' => [
                'db' => 'bo', 'modul' => 'Inisiasi KPR', 'kunci' => 'initiation_number',
                // Riwayat tahap (JSON) dibentuk ulang tiap migrasi dijalankan — bukan perubahan data.
                'jaga' => ['stages'],
                'ref' => ['kpr_application_id' => 'kpr_applications'],
                'master' => ['booking_id' => 'bookings', 'customer_id' => 'customers', 'bank_partner_id' => 'bank_partners'],
                'perbarui' => true,
                'cocok' => ['pihak' => 'booking_id', 'pihak_wajib' => true],
                'label' => ['booking_id', 'status', 'current_stage'],
            ],

            // Jurnal TERAKHIR: jurnal milik dokumen ikut dokumennya; yang tersisa = jurnal memorial.
            'bs_journals' => [
                'db' => 'bo', 'modul' => 'Jurnal GL', 'kunci' => 'journal_number',
                'master' => ['cost_center_id' => 'bs_cost_centers'],
                'anak' => [['tabel' => 'bs_journal_lines', 'fk' => 'journal_id', 'kunci' => null,
                    'master' => ['account_id' => 'bs_accounts', 'cost_center_id' => 'bs_cost_centers']]],
                'perbarui' => false,
                'cocok' => ['nominal' => 'total_debit', 'teks' => 'description', 'tanggal' => 'journal_date'],
                'native_sumber' => ['memorial', 'manual'],
                'label' => ['description', 'total_debit'],
            ],
        ];
    }

    /** Master: disalin dari bayangan bila id-nya belum ada di J-ERP. Isinya: master → kolom induknya. */
    public static function master(): array
    {
        return [
            'fo_units' => ['db' => 'fo', 'master' => ['lokasi_id' => 'fo_lokasi', 'tahap_id' => 'fo_tahap', 'unit_type_id' => 'fo_unit_types']],
            'fo_lokasi' => ['db' => 'fo'], 'fo_tahap' => ['db' => 'fo', 'master' => ['lokasi_id' => 'fo_lokasi']],
            'fo_unit_types' => ['db' => 'fo'], 'fo_agents' => ['db' => 'fo'], 'fo_sales_levels' => ['db' => 'fo'],
            'fo_skema' => ['db' => 'fo'], 'fo_gimmicks' => ['db' => 'fo'], 'banks' => ['db' => 'fo'], 'projects' => ['db' => 'fo'],
            'bs_accounts' => ['db' => 'bo'], 'bs_cost_centers' => ['db' => 'bo'], 'bs_suppliers' => ['db' => 'bo'],
            'bs_ap_categories' => ['db' => 'bo'], 'bs_items' => ['db' => 'bo'], 'bs_warehouses' => ['db' => 'bo'],
            'bank_partners' => ['db' => 'bo'], 'bookings' => ['db' => 'bo'], 'customers' => ['db' => 'bo'],
        ];
    }
}
