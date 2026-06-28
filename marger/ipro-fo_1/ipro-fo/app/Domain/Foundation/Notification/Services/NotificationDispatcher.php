<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Notification\Services;

use App\Domain\Foundation\Notification\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Multi-channel notification dispatcher.
 *
 * Mengirim notifikasi ke kanal yang sesuai (mail/whatsapp/sms/inApp)
 * via tabel `notifications_outbox` — diproses oleh queue worker.
 */
final class NotificationDispatcher
{
    /**
     * @param array<int, string> $channels Channels to send (e.g. ['inApp', 'whatsapp', 'mail'])
     * @param array<string, mixed> $payload Template variables (mis. customer_name, amount, due_date)
     */
    public function send(
        string $event,
        Model $notifiable,
        array $channels = ['inApp'],
        array $payload = [],
    ): void {
        foreach ($channels as $channel) {
            $template = NotificationTemplate::query()
                ->where('event', $event)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->first();

            if (! $template) {
                continue;
            }

            $rendered = $this->renderTemplate($template, $payload);

            DB::table('notifications_outbox')->insert([
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $notifiable->tenant_id ?? tenant_id(),
                'event'           => $event,
                'channel'         => $channel,
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id'   => $notifiable->getKey(),
                'payload'         => json_encode($payload),
                'rendered'        => json_encode($rendered),
                'status'          => 'pending',
                'scheduled_at'    => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{subject:?string, body:string}
     */
    private function renderTemplate(NotificationTemplate $template, array $payload): array
    {
        $subject = $template->subject;
        $body    = $template->body;

        foreach ($payload as $key => $value) {
            $placeholder = '{{ '.$key.' }}';
            $subject = $subject !== null ? str_replace($placeholder, (string) $value, $subject) : null;
            $body    = str_replace($placeholder, (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
