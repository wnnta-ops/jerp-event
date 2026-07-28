-- =====================================================================
-- KOREKSI DATA MIGRASI FO  (jerp)
-- Jalankan SEKALI pada DB server SETELAH backup. Aman & idempoten
-- (boleh dijalankan ulang, hasil sama). TIDAK perlu migrasi ulang —
-- data server masih utuh, hanya salah petakan; koreksi di tempat.
--
--   Cara pakai (di server):
--     mysqldump ... jerp fo_reservasi fo_skema fo_skema_lines fo_units > backup_fo.sql   # backup dulu
--     mysql -uUSER -p jerp < koreksi-migrasi-fo.sql
--
-- Sudah diuji di LOKAL (rollback + terapkan) — hasil sesuai.
-- =====================================================================

-- (A) RESERVASI: kembalikan harga_jual <-> booking_fee yang tertukar.
--     Migrasi menaruh HARGA di booking_fee & FEE di harga_jual.
--     Baris tertukar dikenali: booking_fee menyimpan harga (>=50jt) dan > harga_jual.
--     Pakai snapshot (subquery) supaya swap benar — assignment MySQL kiri->kanan
--     akan MERUSAK bila ditulis "SET a=b, b=a" langsung.
UPDATE fo_reservasi r
JOIN (
    SELECT id, harga_jual AS h, booking_fee AS b
      FROM fo_reservasi
     WHERE booking_fee > harga_jual
       AND booking_fee >= 50000000
) o ON o.id = r.id
SET r.harga_jual = o.b,
    r.booking_fee = o.h;

-- (B) SKEMA BARIS: isi basis kosong (dump legacy) supaya editor TIDAK menghapus
--     nilai termin jadi 0 saat disimpan. Nilai <=100 = persen, >100 = nominal (Rp).
--     + normalisasi kode jadwal legacy (D/M/F).
UPDATE fo_skema_lines
   SET persen = nominal, nominal = NULL, basis = 'PERSEN'
 WHERE (basis IS NULL OR basis = '') AND nominal IS NOT NULL AND nominal <= 100;

UPDATE fo_skema_lines
   SET basis = 'NOMINAL'
 WHERE (basis IS NULL OR basis = '') AND nominal IS NOT NULL AND nominal > 100;

UPDATE fo_skema_lines
   SET jadwal_basis = CASE jadwal_basis
        WHEN 'D' THEN 'HARI'
        WHEN 'M' THEN 'BULAN'
        WHEN 'F' THEN 'TANGGAL'
        ELSE jadwal_basis END
 WHERE jadwal_basis IN ('D','M','F');

-- (C) SKEMA HEADER: turunkan dp_persen/ang_persen/bf_nominal dari baris
--     (dipakai mesin hitung SkemaCalculatorService; migrasi mengisinya 0).
--     Lalu bulatkan artefak split (mis. DP 1.67 x3 = 5.01 -> 5) bila selisih <= 0.05.
UPDATE fo_skema s SET
  dp_persen  = COALESCE((SELECT SUM(COALESCE(l.persen, l.nominal))
                           FROM fo_skema_lines l WHERE l.skema_id = s.id AND l.tipe = 'DP'), 0),
  ang_persen = COALESCE((SELECT SUM(COALESCE(l.persen, l.nominal))
                           FROM fo_skema_lines l WHERE l.skema_id = s.id AND l.tipe = 'ANG'), 0),
  bf_nominal = COALESCE((SELECT SUM(CASE WHEN l.nominal > 100 THEN l.nominal ELSE 0 END)
                           FROM fo_skema_lines l WHERE l.skema_id = s.id AND l.tipe = 'BF'), 0);

UPDATE fo_skema SET
  dp_persen  = IF(ABS(dp_persen  - ROUND(dp_persen))  <= 0.05, ROUND(dp_persen),  dp_persen),
  ang_persen = IF(ABS(ang_persen - ROUND(ang_persen)) <= 0.05, ROUND(ang_persen), ang_persen);

-- (D) UNIT: kupas prefix {lokasi}-{tahap}- yang tergandakan di kode_unit
--     (mis. JRK3-004-JRK3-004-XIX-23A -> JRK3-004-XIX-23A). Hanya baris yang
--     benar-benar dobel yang tersentuh. (Deploy juga fix kode composeKodeUnit
--     agar tak terulang saat unit disimpan ulang.)
UPDATE fo_units u
JOIN fo_lokasi lk ON lk.id = u.lokasi_id
JOIN fo_tahap  th ON th.id = u.tahap_id
SET u.kode_unit = SUBSTRING(u.kode_unit, CHAR_LENGTH(CONCAT(lk.kode,'-',th.kode,'-')) + 1)
WHERE u.kode_unit LIKE CONCAT(lk.kode,'-',th.kode,'-',lk.kode,'-',th.kode,'-%');

-- =====================================================================
-- VERIFIKASI (jalankan setelah koreksi; semua harus wajar):
--   SELECT COUNT(*) FROM fo_reservasi WHERE booking_fee > harga_jual;      -- harus 0
--   SELECT code, dp_persen, ang_persen, bf_nominal FROM fo_skema ORDER BY CAST(code AS UNSIGNED);
--   SELECT COUNT(*) FROM fo_skema_lines WHERE basis IS NULL OR basis='';    -- harus 0
--   SELECT COUNT(*) FROM fo_units u JOIN fo_lokasi lk ON lk.id=u.lokasi_id
--     JOIN fo_tahap th ON th.id=u.tahap_id
--     WHERE u.kode_unit LIKE CONCAT(lk.kode,'-',th.kode,'-',lk.kode,'-',th.kode,'-%'); -- harus 0
-- =====================================================================
