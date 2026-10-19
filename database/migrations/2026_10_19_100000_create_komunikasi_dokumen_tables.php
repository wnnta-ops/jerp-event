<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan → Komunikasi & Dokumen.
 *
 *  - fo_wa_templates      : daftar template WhatsApp yang ditempel dari dashboard Qontak.
 *  - fo_template_pesan    : teks email + pilihan template WA per jenis pesan, per cluster
 *                           (lokasi_id NULL = berlaku untuk semua cluster).
 *  - fo_log_pesan         : catatan SETIAP email/WA yang dikirim aplikasi (Monitoring Pesan).
 *  - fo_template_dokumen  : teks SPR/SPRS ber-VERSI dengan tanggal berlaku, per cluster.
 *
 * ID template WA yang selama ini di .env (QONTAK_TEMPLATE_*) dipindahkan ke fo_wa_templates
 * dan dipasang di Template Pesan dengan urutan parameter yang sama persis seperti sekarang —
 * pengiriman tidak berubah sampai seseorang mengubahnya dari layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_wa_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('nama', 120);
            $t->string('template_id', 120);
            $t->text('contoh_isi')->nullable();
            $t->unsignedTinyInteger('jumlah_param')->default(0);
            $t->boolean('pakai_dokumen')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('keterangan')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('fo_template_pesan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('jenis', 40);
            $t->unsignedBigInteger('lokasi_id')->nullable();
            $t->boolean('kirim_email')->default(true);
            $t->string('email_subjek')->nullable();
            $t->longText('email_isi')->nullable();
            $t->boolean('email_lampiran')->default(true);
            $t->boolean('kirim_wa')->default(true);
            $t->unsignedBigInteger('wa_template_id')->nullable();
            $t->json('wa_params')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'jenis', 'lokasi_id'], 'fo_tpl_pesan_jenis_idx');
        });

        Schema::create('fo_log_pesan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('channel', 8);                // EMAIL / WA
            $t->string('jenis', 40);                 // KONFIRMASI_NUP, KWITANSI_UTJ, SP1, …
            $t->string('status', 12);                // TERKIRIM / GAGAL / DILEWATI
            $t->text('alasan')->nullable();
            $t->string('penerima_nama')->nullable();
            $t->string('tujuan')->nullable();        // email / nomor HP
            $t->string('subjek')->nullable();
            $t->string('ref_type', 40)->nullable();  // nup / bkm / kontrak / collection_notice
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('ref_no')->nullable();
            $t->unsignedBigInteger('kontrak_id')->nullable()->index();
            $t->string('wa_template_id', 120)->nullable();
            $t->json('payload')->nullable();
            $t->unsignedSmallInteger('percobaan')->default(1);
            $t->unsignedBigInteger('kirim_ulang_dari')->nullable();
            $t->unsignedBigInteger('dikirim_oleh')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at'], 'fo_log_pesan_status_idx');
            $t->index(['jenis', 'ref_type', 'ref_id'], 'fo_log_pesan_ref_idx');
        });

        Schema::create('fo_template_dokumen', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('dokumen', 12);               // SPR / SPRS
            $t->unsignedBigInteger('lokasi_id')->nullable();
            $t->unsignedInteger('versi');
            $t->date('berlaku_mulai');
            $t->json('isi');
            $t->text('catatan')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'dokumen', 'lokasi_id', 'berlaku_mulai'], 'fo_tpl_dok_berlaku_idx');
        });

        $this->pindahkanTemplateWaDariEnv();
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_template_dokumen');
        Schema::dropIfExists('fo_log_pesan');
        Schema::dropIfExists('fo_template_pesan');
        Schema::dropIfExists('fo_wa_templates');
    }

    /** ID template di .env → fo_wa_templates + Template Pesan bawaan (semua cluster). */
    private function pindahkanTemplateWaDariEnv(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');
        $now = now();

        // jenis => [nama, config key, jumlah param, pakai dokumen, urutan isian, email lampiran]
        $peta = [
            'TAGIHAN_UTJ' => ['Tagihan UTJ', 'template_utj', 4, false, ['nama', 'nama_acara', 'nominal_utj', 'link_bayar'], false],
            'KONFIRMASI_NUP' => ['Konfirmasi NUP', 'template_konfirmasi', 6, true, ['nama', 'nama_acara', 'no_nup', 'no_hp', 'nik', 'sales'], true],
            'KWITANSI_UTJ' => ['Kwitansi UTJ', 'template_utj_paid', 3, true, ['nama', 'nominal_angka', 'nama_acara'], true],
        ];

        foreach ($peta as $jenis => [$nama, $key, $jumlah, $dok, $params, $lampiran]) {
            $waId = null;
            $idQontak = (string) config('services.qontak.'.$key, '');
            if ($idQontak !== '') {
                $waId = DB::table('fo_wa_templates')->insertGetId([
                    'tenant_id' => $tenantId, 'nama' => $nama, 'template_id' => $idQontak,
                    'jumlah_param' => $jumlah, 'pakai_dokumen' => $dok, 'is_active' => true,
                    'keterangan' => 'Dipindahkan dari .env (services.qontak.'.$key.')',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('fo_template_pesan')->insert([
                'tenant_id' => $tenantId, 'jenis' => $jenis, 'lokasi_id' => null,
                'kirim_email' => true, 'email_subjek' => null, 'email_isi' => null, 'email_lampiran' => $lampiran,
                'kirim_wa' => true, 'wa_template_id' => $waId, 'wa_params' => json_encode($params),
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Kwitansi Pembayaran (DP/angsuran/pelunasan) — BARU, dimatikan sampai diatur dari layar.
        DB::table('fo_template_pesan')->insert([
            'tenant_id' => $tenantId, 'jenis' => 'KWITANSI_BAYAR', 'lokasi_id' => null,
            'kirim_email' => false, 'email_subjek' => 'Kwitansi Pembayaran {jenis_pembayaran} — {no_kontrak}',
            'email_isi' => '<p>Yth. Bapak/Ibu {nama},</p><p>Terima kasih, pembayaran Anda telah kami terima:</p>'
                .'<p>No. Kwitansi : {no_kwitansi}<br>Tanggal : {tgl_bayar}<br>Unit : {unit}<br>'
                .'Pembayaran : {jenis_pembayaran}<br>Nominal : {nominal}</p>'
                .'<p>Kwitansi resmi terlampir dalam email ini.</p><p>Hormat kami,<br>{nama_perusahaan}</p>',
            'email_lampiran' => true, 'kirim_wa' => false, 'wa_template_id' => null,
            'wa_params' => json_encode(['nama', 'no_kwitansi', 'nominal_angka', 'unit']),
            'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
