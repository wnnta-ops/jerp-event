<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FoBridge;

use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\BudgetIkutInvoice;
use App\Domain\BusinessSupport\Services\LampiranPerubahanBudget;
use App\Http\Controllers\Web\ApprovalInbox\ApprovalInboxController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dokumen yang bisa di-preview app approver (PWA) untuk satu approval Approval Inbox:
 *   file-{id}  : lampiran berkas (TTD Berkas / Invoice Hutang dari berkas) — file asli
 *   memo       : lembar Memo Urgent (ApprovalInboxController::cetak, mode preview)
 *   lampiran   : lampiran perubahan budget (Alokasi / Koreksi)
 *   alokasi-{id} / koreksi-{id} : lampiran alokasi / koreksi turunan Invoice Hutang (diputuskan bersamanya)
 * Lembar HTML dikirim mandiri: logo & TTD disisipkan sebagai data URI, karena alamat aslinya
 * butuh sesi BO. Hak lihat dicek pemanggil (ApprovalInboxQuery::canView).
 */
final class DokumenApproval
{
    /** @return list<array{kunci:string, nama:string, jenis:?string, mime:string, ukuran:?int}> */
    public function daftar(Approval $approval): array
    {
        $m = $approval->approvable;
        $out = [];
        if ($m instanceof MemoInternal) {
            $out[] = ['kunci' => 'memo', 'nama' => 'Lembar '.($m->memo_number ?: 'memo'), 'jenis' => 'Lembar memo', 'mime' => 'text/html', 'ukuran' => null];
        }
        if ($m instanceof BsBudgetAllocation || $m instanceof BsBudgetCorrection) {
            $out[] = ['kunci' => 'lampiran', 'nama' => 'Lampiran perubahan budget '.($m->doc_no ?? ''), 'jenis' => 'Lampiran', 'mime' => 'text/html', 'ukuran' => null];
        }
        foreach ($this->turunan($approval) as $t) {
            $jenis = $t instanceof BsBudgetAllocation ? 'alokasi' : 'koreksi';
            $out[] = ['kunci' => $jenis.'-'.$t->id, 'nama' => 'Lampiran '.$jenis.' '.($t->doc_no ?? ''), 'jenis' => 'Lampiran '.$jenis.' budget', 'mime' => 'text/html', 'ukuran' => null];
        }
        foreach ($this->berkas($approval)?->files ?? [] as $f) {
            $out[] = ['kunci' => 'file-'.$f->id, 'nama' => (string) $f->original_name, 'jenis' => $f->labelJenis(), 'mime' => (string) ($f->mime ?: 'application/octet-stream'), 'ukuran' => $f->size !== null ? (int) $f->size : null];
        }

        return $out;
    }

    public function isi(Approval $approval, string $kunci, Request $request): Response
    {
        $m = $approval->approvable;

        if ($kunci === 'memo' && $m instanceof MemoInternal) {
            $sub = Request::create('/approval-inbox/'.$approval->id.'/cetak', 'GET', ['preview' => 1]);
            $sub->setUserResolver(fn () => $request->user());
            $html = app(ApprovalInboxController::class)->cetak($sub, $approval)->getContent();

            return $this->html((string) $html, 'memo-'.($m->memo_number ?: $approval->id));
        }

        if ($kunci === 'lampiran' && ($m instanceof BsBudgetAllocation || $m instanceof BsBudgetCorrection)) {
            $lampiran = app(LampiranPerubahanBudget::class);
            $data = $m instanceof BsBudgetAllocation ? $lampiran->alokasi($m) : $lampiran->koreksi($m);

            return $this->html(view('pdf.lampiran-perubahan-budget', $data)->render(), 'lampiran-'.($m->doc_no ?? $approval->id));
        }

        if (preg_match('/^(alokasi|koreksi)-(\d+)$/', $kunci, $x)) {
            $kelas = $x[1] === 'alokasi' ? BsBudgetAllocation::class : BsBudgetCorrection::class;
            $t = $this->turunan($approval)->first(fn ($t) => $t instanceof $kelas && (int) $t->id === (int) $x[2]);
            abort_unless($t !== null, 404, 'Dokumen tidak ditemukan.');
            $lampiran = app(LampiranPerubahanBudget::class);
            $data = $t instanceof BsBudgetAllocation ? $lampiran->alokasi($t) : $lampiran->koreksi($t);

            return $this->html(view('pdf.lampiran-perubahan-budget', $data)->render(), 'lampiran-'.($t->doc_no ?? $t->id));
        }

        if (preg_match('/^file-(\d+)$/', $kunci, $x)) {
            $berkas = $this->berkas($approval);
            $file = $berkas?->files->firstWhere('id', (int) $x[1]);
            abort_unless($file instanceof BsApBerkasUploadFile, 404, 'Dokumen tidak ditemukan.');
            $disk = Storage::disk(ApBerkasService::DISK);
            abort_unless($disk->exists($file->path), 404, 'File tidak ada di penyimpanan.');

            return response($disk->get($file->path), 200, [
                'Content-Type' => $file->mime ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addcslashes((string) $file->original_name, '"\\').'"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        abort(404, 'Dokumen tidak ditemukan.');
    }

    /** Alokasi / koreksi turunan Invoice Hutang (kosong untuk jenis lain). */
    private function turunan(Approval $approval): \Illuminate\Support\Collection
    {
        return BudgetIkutInvoice::berlaku($approval) ? app(BudgetIkutInvoice::class)->turunan($approval) : collect();
    }

    /** Berkas di balik approval: TTD Berkas itu sendiri, atau berkas sumber Invoice Hutang (juga yang masih menunggu alokasi / koreksi). */
    private function berkas(Approval $approval): ?BsApBerkasUpload
    {
        $m = $approval->approvable;
        if ($m instanceof BsApBerkasUpload) {
            return $m->loadMissing('files.jenis:id,name');
        }
        if ($m instanceof BsApInvoice && $m->source_type === ApBerkasService::INVOICE_SOURCE) {
            return BsApBerkasUpload::withTrashed()->with('files.jenis:id,name')->find($m->source_id);
        }
        if ($m instanceof BsApBudgetRequest && ! empty($m->data['berkas_upload_id'])) {
            return BsApBerkasUpload::withTrashed()->with('files.jenis:id,name')->find((int) $m->data['berkas_upload_id']);
        }

        return null;
    }

    /** HTML mandiri: logo & TTD jadi data URI, tanpa skrip (dibuka di iframe sandbox). */
    private function html(string $html, string $nama): Response
    {
        $html = preg_replace_callback('#src="(/images/[^"]+)"#', function ($x) {
            $path = public_path(ltrim($x[1], '/'));

            return is_file($path) ? 'src="'.$this->dataUri((string) file_get_contents($path), (string) mime_content_type($path)).'"' : $x[0];
        }, $html) ?? $html;

        $html = preg_replace_callback('#src="[^"]*/users/(\d+)/signature[^"]*"#', function ($x) {
            $u = User::find((int) $x[1]);
            $disk = Storage::disk('local'); // sama dengan UserSignatureController::DISK

            return $u && $u->signature_path && $disk->exists($u->signature_path)
                ? 'src="'.$this->dataUri((string) $disk->get($u->signature_path), 'image/png').'"'
                : 'src=""';
        }, $html) ?? $html;

        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nama).'.html';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$file.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function dataUri(string $isi, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($isi);
    }
}
