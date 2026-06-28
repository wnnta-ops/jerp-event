<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly string $outboxId) {}

    public function handle(): void
    {
        $row = DB::table('notifications_outbox')->where('id', $this->outboxId)->first();
        if (! $row || $row->status === 'sent') {
            return;
        }

        DB::table('notifications_outbox')->where('id', $this->outboxId)->update([
            'status' => 'sending', 'attempts' => $row->attempts + 1, 'updated_at' => now(),
        ]);

        try {
            $rendered = json_decode($row->rendered, true);
            $payload  = json_decode($row->payload, true);

            match ($row->channel) {
                'mail'     => $this->sendMail($row, $rendered),
                'whatsapp' => $this->sendWhatsapp($row, $rendered),
                'sms'      => $this->sendSms($row, $rendered),
                'inApp'    => $this->sendInApp($row, $rendered, $payload),
                default    => null,
            };

            DB::table('notifications_outbox')->where('id', $this->outboxId)->update([
                'status' => 'sent', 'sent_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification send failed', ['outbox_id' => $this->outboxId, 'error' => $e->getMessage()]);

            DB::table('notifications_outbox')->where('id', $this->outboxId)->update([
                'status' => $row->attempts >= 2 ? 'dead' : 'failed',
                'last_error' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    private function sendMail(object $row, array $rendered): void
    {
        $user = DB::table($row->notifiable_type === 'user' ? 'users' : 'customers')->find($row->notifiable_id);
        if (! $user?->email) return;

        Mail::raw($rendered['body'] ?? '', function ($m) use ($user, $rendered): void {
            $m->to($user->email)->subject($rendered['subject'] ?? 'Notifikasi');
        });
    }

    private function sendWhatsapp(object $row, array $rendered): void
    {
        // TODO: integrasi WA Business API
        Log::info('WA notif (placeholder)', ['to' => $row->notifiable_id, 'body' => $rendered['body'] ?? '']);
    }

    private function sendSms(object $row, array $rendered): void
    {
        // TODO: integrasi SMS gateway
        Log::info('SMS notif (placeholder)', ['to' => $row->notifiable_id, 'body' => $rendered['body'] ?? '']);
    }

    private function sendInApp(object $row, array $rendered, array $payload): void
    {
        DB::table('notifications')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'type'            => $row->event,
            'notifiable_type' => $row->notifiable_type,
            'notifiable_id'   => $row->notifiable_id,
            'data'            => json_encode([
                'subject' => $rendered['subject'] ?? '',
                'body'    => $rendered['body']    ?? '',
                'payload' => $payload,
            ]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
