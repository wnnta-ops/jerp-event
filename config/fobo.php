<?php

/**
 * FO → BO (FOBO) bridge configuration.
 *
 * Maps each FO financial event `jenis` to the default GL accounts used when
 * auto-posting the journal. Accounting can tune these without code changes.
 * An event may override `akun_debet` / `akun_kredit` per-instance.
 *
 * `debet` = account debited for the amount, `kredit` = account credited.
 */
return [
    'default_company_resolver' => null, // null → first company in tenant

    'mapping' => [
        // Penerimaan kas/bank dari customer (TTS/BKM)
        'KAS_MASUK' => ['debet' => '1.1.01.002', 'kredit' => '1.1.02.001'],
        // Penjualan unit (closing/kasir jual)
        'SALES' => ['debet' => '1.1.02.001', 'kredit' => '4.1.01.001'],
        // Pembayaran komisi sales
        'KOMISI' => ['debet' => '5.2.01.001', 'kredit' => '2.1.01.001'],
        // Pencairan akad KPR dari bank
        'AKAD' => ['debet' => '1.1.01.104', 'kredit' => '1.1.02.001'],
        // Buyback / pembatalan
        'BUYBACK' => ['debet' => '2.1.01.004', 'kredit' => '1.1.01.002'],
        // Refund kelebihan bayar
        'REFUND' => ['debet' => '2.1.01.004', 'kredit' => '1.1.01.002'],
        // Memo cashback/koreksi
        'MEMO' => ['debet' => '5.9.01.001', 'kredit' => '1.1.02.001'],
    ],
];
