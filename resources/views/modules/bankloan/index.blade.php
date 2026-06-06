@extends('layouts.app', ['title' => 'Bank Loan'])

@section('content')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold tracking-tight mb-2" style="color: var(--color-text);">Bank Loan (KPR)</h1>
        <p class="text-sm mb-6" style="color: var(--color-text-muted);">
            Proses kredit bank: pengajuan, BI checking, interview, SP3K, akad, realisasi, retensi.
        </p>
        <x-card>
            <h3 class="font-semibold mb-1.5" style="color: var(--color-text);">Belum diimplementasi (Phase 2)</h3>
            <p class="text-sm" style="color: var(--color-text-muted);">
                Modul BankLoan adalah port dari legacy KPR API (.NET 7 + EF Core). 103 migration EF Core
                akan di-port ke Laravel migration pada fase 2. Kerangka: <code class="font-mono text-xs">app/Modules/BankLoan/</code>.
            </p>
        </x-card>
    </div>
@endsection
