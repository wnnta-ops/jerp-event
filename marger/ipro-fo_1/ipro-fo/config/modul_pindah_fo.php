<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Modul yang sudah PINDAH ke FO (J-ERP)
|------------------------------------------------------------------------------
| Sales, Collection, dan Setup Sales kini dikerjakan sepenuhnya di FO. Menunya
| sudah lama dicabut dari sidebar BO, TETAPI rutenya masih hidup — 49 rute yang
| tetap bisa dibuka dengan mengetik alamatnya, dan tetap bisa MENULIS ke data
| yang sekarang dikelola FO. Menu yang hilang bukan pengaman.
|
| Sakelar ini mematikan rutenya, bukan menghapus kodenya. Bila ternyata masih
| ada yang memerlukannya, cukup set true di .env lalu restart — tanpa deploy
| ulang dan tanpa mengembalikan kode apa pun.
|
|   BO_MODUL_SALES_AKTIF=true
|   BO_MODUL_COLLECTION_AKTIF=true
|   BO_MODUL_SETUP_SALES_AKTIF=true
|   BO_MODUL_NUP_PUBLIK_AKTIF=true
*/

return [
    'sales' => (bool) env('BO_MODUL_SALES_AKTIF', false),
    'collection' => (bool) env('BO_MODUL_COLLECTION_AKTIF', false),
    'setup_sales' => (bool) env('BO_MODUL_SETUP_SALES_AKTIF', false),

    // Form pendaftaran NUP publik BO (tanpa login). Bukan bagian dari menu
    // mana pun, jadi mematikan menu tidak pernah menutupnya.
    'nup_publik' => (bool) env('BO_MODUL_NUP_PUBLIK_AKTIF', false),
];
