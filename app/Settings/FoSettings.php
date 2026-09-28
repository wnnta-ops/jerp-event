<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FoSettings extends Settings
{
    public float $default_booking_fee;

    public int $default_tenor_bulan;

    public float $default_dp_persen;

    public int $reservasi_expiry_days;

    public int $tts_auto_confirm_minutes;

    public bool $recaptcha_enabled;

    public float $recaptcha_min_score;

    public float $komisi_pph_default_persen;

    // ── BLT Batal Sepihak ────────────────────────────────────────────────────
    // Nilai tetap untuk sales ketika kontrak dibatalkan sepihak oleh pembeli, beserta
    // potongan PPh 21-nya. Tidak diambil dari Skema BLT: skema menghitung dari nilai
    // kontrak, sedangkan yang ini justru muncul karena kontraknya TIDAK jadi.
    // Dipakai HitungPembatalan; lihat di sana untuk rumus lengkapnya.
    public float $blt_batal_sepihak_nilai;

    public float $blt_batal_sepihak_pph_persen;

    public string $notification_email;

    // Formulir NUP publik — pemeliharaan
    public bool $nup_public_maintenance;

    public ?string $nup_public_maintenance_start;

    public ?string $nup_public_maintenance_end;

    public ?string $nup_public_maintenance_note;

    public ?string $nup_public_maintenance_bypass;

    // Siteplan Live (acara NUP)
    public bool $siteplanlive_show_stock;

    public bool $siteplanlive_sold_animation;

    public ?int $siteplanlive_focus_lokasi_id;

    public ?string $siteplanlive_focus_tahap;

    public static function group(): string
    {
        return 'fo';
    }
}
