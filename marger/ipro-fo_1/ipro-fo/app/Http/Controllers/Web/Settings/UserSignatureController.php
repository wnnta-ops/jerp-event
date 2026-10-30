<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * TTD (tanda tangan) PNG per user. File disimpan di disk privat 'local', tidak pernah
 * di public/, dan hanya bisa diambil lewat route ini oleh user yang login di tenant yang
 * sama — TTD ikut tercetak di dokumen yang memang boleh mereka lihat (Approval Inbox).
 */
class UserSignatureController extends Controller
{
    private const DISK = 'local';

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->can('system.user.update'), 403);

        $request->validate([
            'signature' => 'required|file|mimes:png|mimetypes:image/png|max:1024|dimensions:max_width=2000,max_height=1000',
        ], [
            'signature.mimes' => 'TTD harus berupa file PNG.',
            'signature.mimetypes' => 'TTD harus berupa file PNG.',
            'signature.max' => 'Ukuran TTD maksimal 1 MB.',
            'signature.dimensions' => 'Ukuran gambar TTD maksimal 2000 × 1000 piksel.',
        ]);

        $old = $user->signature_path;
        $path = $request->file('signature')->storeAs(
            "signatures/{$user->tenant_id}",
            $user->id.'-'.now()->format('YmdHis').'.png',
            self::DISK,
        );

        $user->forceFill(['signature_path' => $path, 'signature_updated_at' => now()])->save();
        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return back()->with('success', "TTD {$user->name} disimpan.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->can('system.user.update'), 403);

        if ($user->signature_path) {
            Storage::disk(self::DISK)->delete($user->signature_path);
        }
        $user->forceFill(['signature_path' => null, 'signature_updated_at' => null])->save();

        return back()->with('success', "TTD {$user->name} dihapus.");
    }

    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();
        abort_unless($viewer && (int) $viewer->tenant_id === (int) $user->tenant_id, 403);
        abort_unless($user->signature_path && Storage::disk(self::DISK)->exists($user->signature_path), 404);

        return response()->file(Storage::disk(self::DISK)->path($user->signature_path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
