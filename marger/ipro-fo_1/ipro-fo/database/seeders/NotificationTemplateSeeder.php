<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Notification\Models\NotificationTemplate;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $templates = [
            // Booking
            ['event' => 'booking.created',  'channel' => 'inApp',    'subject' => 'Booking Berhasil',           'body' => 'Booking {{ booking_number }} berhasil dibuat dengan total {{ total_price }}.'],
            ['event' => 'booking.created',  'channel' => 'whatsapp', 'subject' => null,                          'body' => 'Halo {{ customer_name }}, booking unit {{ unit_code }} berhasil. Nomor: {{ booking_number }}.'],

            // Payment
            ['event' => 'payment.received', 'channel' => 'inApp',    'subject' => 'Pembayaran Diterima',        'body' => 'Pembayaran sebesar {{ amount }} untuk {{ booking_number }} telah diterima.'],
            ['event' => 'payment.received', 'channel' => 'whatsapp', 'subject' => null,                          'body' => 'Halo {{ customer_name }}, pembayaran Anda {{ amount }} berhasil diterima. Terima kasih.'],

            // Due reminder
            ['event' => 'due.reminder.h3',  'channel' => 'whatsapp', 'subject' => null,                          'body' => 'Reminder: tagihan {{ amount }} akan jatuh tempo {{ due_date }}.'],
            ['event' => 'due.overdue',      'channel' => 'whatsapp', 'subject' => null,                          'body' => '⚠️ Tagihan {{ amount }} sudah lewat jatuh tempo {{ due_date }}. Mohon segera dibayar.'],

            // SP
            ['event' => 'sp.issued',        'channel' => 'inApp',    'subject' => 'Surat Peringatan',           'body' => 'SP {{ level }} diterbitkan untuk Anda. Deadline: {{ deadline }}.'],

            // Approval
            ['event' => 'approval.assigned', 'channel' => 'inApp',   'subject' => 'Approval Menunggu',          'body' => 'Anda diminta menyetujui {{ document_type }} #{{ approval_id }}.'],
            ['event' => 'approval.approved', 'channel' => 'inApp',   'subject' => 'Approval Disetujui',         'body' => 'Permintaan {{ document_type }} Anda telah disetujui.'],
            ['event' => 'approval.rejected', 'channel' => 'inApp',   'subject' => 'Approval Ditolak',           'body' => 'Permintaan {{ document_type }} Anda ditolak. Alasan: {{ reason }}.'],

            // KPR
            ['event' => 'kpr.sp3k.issued',  'channel' => 'whatsapp', 'subject' => null,                          'body' => 'SP3K dari {{ bank_name }} sudah terbit. Tunggu undangan Akad.'],
            ['event' => 'kpr.disbursed',    'channel' => 'inApp',    'subject' => 'Pencairan KPR',              'body' => 'Pencairan KPR {{ amount }} berhasil. Status booking dilanjutkan.'],
        ];

        foreach ($templates as $t) {
            NotificationTemplate::updateOrCreate(
                ['tenant_id' => $tenant->id, 'event' => $t['event'], 'channel' => $t['channel']],
                $t + ['is_active' => true],
            );
        }
    }
}
