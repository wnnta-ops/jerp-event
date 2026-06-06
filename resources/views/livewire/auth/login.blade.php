<div class="rounded-xl border shadow-sm p-6 md:p-8"
     style="background-color: var(--color-surface); border-color: var(--color-surface-border); border-radius: var(--radius-card);">

    <div class="flex flex-col items-center text-center mb-6">
        <span class="grid place-items-center w-12 h-12 rounded-xl text-white text-xl font-bold mb-3"
              style="background-color: var(--color-brand-600);">J</span>
        <h1 class="text-2xl font-bold tracking-tight" style="color: var(--color-text);">J-ERP</h1>
        <p class="text-sm mt-1" style="color: var(--color-text-muted);">Masuk ke akun Anda</p>
    </div>

    <form wire:submit="submit" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1.5">
            <label for="email" class="text-sm font-medium" style="color: var(--color-text);">Email</label>
            <input id="email" type="email" wire:model="email" autocomplete="email" required autofocus
                   class="rounded-lg border px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-offset-1"
                   style="background-color: var(--color-surface-muted); border-color: var(--color-surface-border); color: var(--color-text); --tw-ring-color: var(--color-brand-500);">
            @error('email') <span class="text-xs" style="color: var(--color-danger);">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="password" class="text-sm font-medium" style="color: var(--color-text);">Password</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password" required
                   class="rounded-lg border px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-offset-1"
                   style="background-color: var(--color-surface-muted); border-color: var(--color-surface-border); color: var(--color-text); --tw-ring-color: var(--color-brand-500);">
            @error('password') <span class="text-xs" style="color: var(--color-danger);">{{ $message }}</span> @enderror
        </div>

        <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color: var(--color-text-muted);">
            <input type="checkbox" wire:model="remember"
                   class="rounded" style="accent-color: var(--color-brand-600);">
            <span>Ingat saya</span>
        </label>

        <x-button type="submit" variant="primary" size="lg" class="w-full mt-2">
            <span wire:loading.remove wire:target="submit">Masuk</span>
            <span wire:loading wire:target="submit">Memproses…</span>
        </x-button>
    </form>

    <p class="text-center text-xs mt-6" style="color: var(--color-text-muted);">
        Phase 0 demo — gunakan <code class="font-mono">admin@jerp.test / password</code>
    </p>
</div>
