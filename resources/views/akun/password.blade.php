@extends('layouts.app', ['title' => 'Ganti Password'])

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="mb-1 text-xl font-bold">Ganti Password</h1>
        <p class="mb-5 text-sm" style="color: var(--color-text-muted);">
            Untuk akun {{ auth()->user()->email }}. Password ini dipakai untuk masuk ke Java Core System.
        </p>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('akun.password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            @foreach ([
                ['password_lama', 'Password lama', 'current-password'],
                ['password', 'Password baru (minimal 8 karakter)', 'new-password'],
                ['password_confirmation', 'Ulangi password baru', 'new-password'],
            ] as [$nama, $label, $auto])
                <div>
                    <label for="{{ $nama }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                    <input id="{{ $nama }}" name="{{ $nama }}" type="password" autocomplete="{{ $auto }}" required
                           class="h-10 w-full rounded-lg border px-3 text-sm dark:bg-gray-900"
                           style="border-color: var(--color-surface-border);">
                    @error($nama)
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background-color: var(--color-brand-600);">
                    Simpan Password
                </button>
                <a href="{{ route('dashboard') }}" class="text-sm hover:underline" style="color: var(--color-text-muted);">Kembali</a>
            </div>
        </form>
    </div>
@endsection
