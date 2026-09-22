{{--
    Dua tahap konfirmasi sebelum memo rekom batal dicetak & "Ajukan Pembatalan" dibuka.

    Kata-katanya dipatok sesuai permintaan dan TIDAK boleh diringkas: tahap kedua adalah
    satu-satunya tempat operator diberi tahu bahwa tindakan ini mengirim instruksi ke admin
    sales — tanpa kalimat itu, tombol ini terbaca sekadar "cetak".

    Kedua tahap tinggal di SATU modal (bukan dua modal beruntun) karena modal kedua yang
    menggantikan modal pertama harus didaftarkan sebagai aksi tabel tersendiri, dan aksi tabel
    yang disembunyikan ikut terhitung "disabled" oleh Filament sehingga tidak bisa dipasang.

    "Tidak" pada kedua tahap menutup modal — operator kembali ke halaman semula, persis
    seperti perilaku tombol serupa di Reject Proses KPR / Reject BI Checking.

    $panggil / $batal dikirim pemanggil karena aksi baris dan aksi header memakai method
    Livewire yang berbeda; dipatok di sini, salah satu dari keduanya akan diam saja saat ditekan.
--}}
<div x-data="{ tahap: 1 }" class="space-y-6">
    <div x-show="tahap === 1">
        <p class="text-sm text-gray-950 dark:text-white">
            apakah anda yakin akan membuat rekom batal?
        </p>
        @if (($nomor ?? null))
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">SPR: <span class="font-medium">{{ $nomor }}</span></p>
        @elseif (($jumlah ?? 0) > 0)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $jumlah }} SPR dipilih.</p>
        @endif
    </div>

    <div x-show="tahap === 2" x-cloak>
        <p class="text-sm text-gray-950 dark:text-white">
            rekom batal ini akan memberikan instruksi ke admin sales untuk pembatalan spr,
            apakah anda yakin akan hal tersebut?
        </p>
    </div>

    <div class="flex justify-end gap-3">
        <x-filament::button color="gray" type="button" x-on:click="$wire.{{ $batal }}()">
            Tidak
        </x-filament::button>

        <x-filament::button color="danger" type="button" x-show="tahap === 1" x-on:click="tahap = 2">
            Ya
        </x-filament::button>

        <x-filament::button color="danger" type="button" x-show="tahap === 2" x-cloak
                            x-on:click="$wire.{{ $panggil }}()">
            Ya
        </x-filament::button>
    </div>
</div>
