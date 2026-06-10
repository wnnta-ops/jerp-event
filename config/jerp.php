<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subdomain publik (opsional)
    |--------------------------------------------------------------------------
    |
    | Kosongkan untuk dev/lokal — halaman publik tetap diakses lewat path
    | (/nup/daftar, /siteplan). Isi di server produksi bila ingin halaman
    | publik punya subdomain sendiri, mis:
    |   NUP_DOMAIN=daftar.domainanda.com
    |   SITEPLAN_DOMAIN=siteplan.domainanda.com
    |
    | Saat diisi, root "/" subdomain tsb menyajikan halaman terkait langsung.
    | Path lama tetap berfungsi (kompatibel + preview admin).
    |
    */

    'nup_domain' => env('NUP_DOMAIN'),

    'siteplan_domain' => env('SITEPLAN_DOMAIN'),

    'booked_domain' => env('BOOKED_DOMAIN'),

];
