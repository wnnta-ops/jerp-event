<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Termin SP
        $this->migrator->add('collection.reminder_days_before', 3);
        $this->migrator->add('collection.sp1_overdue_days', 1);
        $this->migrator->add('collection.sp2_overdue_days', 14);
        $this->migrator->add('collection.sp3_overdue_days', 30);
        $this->migrator->add('collection.pembatalan_overdue_days', 45);
        $this->migrator->add('collection.channel_email', true);
        $this->migrator->add('collection.channel_wa', true);

        // Template SP — reminder
        $this->migrator->add('collection.reminder_subject', 'Pengingat Pembayaran {tagihan} — {no_kontrak}');
        $this->migrator->add('collection.reminder_body',
            "Yth. Bapak/Ibu {nama},\n\n".
            "Kami mengingatkan tagihan {tagihan} untuk unit {unit} (Kontrak {no_kontrak}) ".
            "akan jatuh tempo pada {jatuh_tempo} sebesar Rp {nominal}.\n\n".
            "Mohon melakukan pembayaran sebelum tanggal tersebut. Terima kasih.\n\nHormat kami,\nPT. Mitra Usaha Propertindo");

        // Template SP I
        $this->migrator->add('collection.sp1_subject', 'Surat Peringatan I — {no_kontrak}');
        $this->migrator->add('collection.sp1_body',
            "Yth. Bapak/Ibu {nama},\n\n".
            "Berdasarkan catatan kami, pembayaran {tagihan} untuk unit {unit} (Kontrak {no_kontrak}) ".
            "yang jatuh tempo {jatuh_tempo} sebesar Rp {nominal} belum kami terima ({hari_telat} hari).\n\n".
            "Dengan ini kami sampaikan SURAT PERINGATAN I. Mohon segera melakukan pembayaran. Terima kasih.\n\nHormat kami,\nPT. Mitra Usaha Propertindo");

        // Template SP II
        $this->migrator->add('collection.sp2_subject', 'Surat Peringatan II — {no_kontrak}');
        $this->migrator->add('collection.sp2_body',
            "Yth. Bapak/Ibu {nama},\n\n".
            "Pembayaran {tagihan} untuk unit {unit} (Kontrak {no_kontrak}) sebesar Rp {nominal} ".
            "telah melewati jatuh tempo {jatuh_tempo} selama {hari_telat} hari dan belum kami terima.\n\n".
            "Dengan ini kami sampaikan SURAT PERINGATAN II. Mohon segera melunasi untuk menghindari sanksi sesuai perjanjian.\n\nHormat kami,\nPT. Mitra Usaha Propertindo");

        // Template SP III
        $this->migrator->add('collection.sp3_subject', 'Surat Peringatan III (Terakhir) — {no_kontrak}');
        $this->migrator->add('collection.sp3_body',
            "Yth. Bapak/Ibu {nama},\n\n".
            "Hingga saat ini pembayaran {tagihan} untuk unit {unit} (Kontrak {no_kontrak}) sebesar Rp {nominal} ".
            "masih belum kami terima, telah melewati jatuh tempo {jatuh_tempo} selama {hari_telat} hari.\n\n".
            "Dengan ini kami sampaikan SURAT PERINGATAN III (TERAKHIR). Apabila dalam waktu yang ditentukan pembayaran ".
            "tetap tidak dilakukan, maka kontrak dapat dibatalkan sesuai ketentuan yang berlaku.\n\nHormat kami,\nPT. Mitra Usaha Propertindo");

        // WA template id (kosong = pakai gateway Null/log saat WA belum dikonfigurasi)
        $this->migrator->add('collection.wa_template_reminder', '');
        $this->migrator->add('collection.wa_template_sp1', '');
        $this->migrator->add('collection.wa_template_sp2', '');
        $this->migrator->add('collection.wa_template_sp3', '');
    }
};
