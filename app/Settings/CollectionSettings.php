<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pengaturan modul Collection (penagihan AR setelah kontrak jadi SPR).
 *
 * - Termin SP: berapa hari sebelum jatuh tempo dikirim reminder, dan berapa hari
 *   keterlambatan memicu SP I / II / III / rekomendasi pembatalan.
 * - Template SP: subjek + isi pesan reminder & SP I/II/III (Email/WA).
 *
 * Placeholder yang didukung di subjek/isi:
 *   {nama} {no_kontrak} {unit} {tagihan} {jatuh_tempo} {nominal} {sisa} {hari_telat}
 */
class CollectionSettings extends Settings
{
    // ── Termin (Setup Termin SP) ─────────────────────────────────────────────
    public int $reminder_days_before;       // kirim reminder H- sebelum jatuh tempo

    public int $sp1_overdue_days;           // SP I bila telat ≥ n hari

    public int $sp2_overdue_days;           // SP II bila telat ≥ n hari

    public int $sp3_overdue_days;           // SP III bila telat ≥ n hari

    public int $pembatalan_overdue_days;    // rekomendasi pembatalan bila telat ≥ n hari

    public bool $channel_email;             // kirim via Email

    public bool $channel_wa;                // kirim via WhatsApp

    // ── Template (Setup SP) ──────────────────────────────────────────────────
    public string $reminder_subject;

    public string $reminder_body;

    public string $sp1_subject;

    public string $sp1_body;

    public string $sp2_subject;

    public string $sp2_body;

    public string $sp3_subject;

    public string $sp3_body;

    // ID template WhatsApp (Qontak) — opsional, dipakai saat gateway WA aktif.
    public string $wa_template_reminder;

    public string $wa_template_sp1;

    public string $wa_template_sp2;

    public string $wa_template_sp3;

    // Kop & tanda tangan Surat Peringatan tercetak (PDF).
    public string $sp_company_name;     // nama perusahaan (kop)

    public string $sp_signer_name;      // nama penandatangan (mis. Finance Supervisor)

    public string $sp_signer_title;     // jabatan penandatangan

    public string $sp_collection_phone; // telp Bag. Collection (footer)

    public int $sp_due_days;            // tenggat pelunasan (jatuh tempo = tgl surat + n hari)

    // Saklar pengiriman OTOMATIS harian (collection:run terjadwal). Default MATI
    // (insiden email massal). Otomatis = WA SAJA — email selalu manual dari
    // halaman Collection, apa pun nilai saklar ini.
    public bool $auto_kirim_wa;

    public static function group(): string
    {
        return 'collection';
    }
}
