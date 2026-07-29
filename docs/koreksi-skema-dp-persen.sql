-- =====================================================================
-- KOREKSI PERSEN BARIS SKEMA  (jerp)  — samakan total baris DP/ANG ke header.
--
-- Masalah: generator jadwal tagihan SPR (KontrakGeneratorService) menghitung
-- nilai tiap termin dari PERSEN BARIS (fo_skema_lines.persen), bukan header.
-- Baris warisan legacy menyimpan DP 1.67 x3 = 5.01% (harusnya 5%), CASH 99.99%,
-- INHOUSE 100.02%, RUHA 20.01% — sehingga SPR meleset ~0.01% dari nilai bersih
-- (mis. unit 553jt: DP 27.705.300 & plafond 522.294.700, seharusnya 27.650.000
-- & 522.350.000). Header sudah 5% (koreksi-migrasi-fo.sql), baris belum.
--
-- Perbaikan: sesuaikan BARIS TERAKHIR tiap tipe (DP/ANG) agar totalnya = header.
--   contoh DP 1.67/1.67/1.67 -> 1.67/1.67/1.66 (total 5.00%).
-- Aman & idempoten (jalan ulang tak mengubah apa pun). Teruji di lokal.
--
--   sudo docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" jerp fo_skema_lines' > backup_skema_lines_$(date +%F).sql
--   sudo docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" jerp' < docs/koreksi-skema-dp-persen.sql
--
-- CATATAN: SPR yang jadwal tagihannya SUDAH ter-generate memakai nilai lama
-- (tersimpan di fo_kontrak_tagihan). Setelah koreksi, GENERATE ULANG SPR itu
-- (buka SPR -> Simpan, atau ubah skema lalu simpan) agar tagihan ikut ter-update.
-- SPR baru otomatis benar.
-- =====================================================================

-- (A) DP: baris DP terakhir tiap skema -> total DP = header dp_persen.
UPDATE fo_skema_lines l
JOIN (
    SELECT skema_id, MAX(urutan) AS last_ur, SUM(persen) AS sp
      FROM fo_skema_lines
     WHERE tipe = 'DP' AND basis = 'PERSEN'
     GROUP BY skema_id
) g ON g.skema_id = l.skema_id AND l.urutan = g.last_ur AND l.tipe = 'DP'
JOIN fo_skema s ON s.id = l.skema_id
SET l.persen = ROUND(l.persen + (s.dp_persen - g.sp), 4)
WHERE ABS(s.dp_persen - g.sp) > 0.001 AND s.dp_persen > 0;

-- (B) ANG: baris ANG terakhir tiap skema -> total ANG = header ang_persen
--     (relevan untuk CASH/INHOUSE; KPR memakai PENCAIRAN = sisa, jadi tak terpengaruh).
UPDATE fo_skema_lines l
JOIN (
    SELECT skema_id, MAX(urutan) AS last_ur, SUM(persen) AS sp
      FROM fo_skema_lines
     WHERE tipe = 'ANG' AND basis = 'PERSEN'
     GROUP BY skema_id
) g ON g.skema_id = l.skema_id AND l.urutan = g.last_ur AND l.tipe = 'ANG'
JOIN fo_skema s ON s.id = l.skema_id
SET l.persen = ROUND(l.persen + (s.ang_persen - g.sp), 4)
WHERE ABS(s.ang_persen - g.sp) > 0.001 AND s.ang_persen > 0;

-- VERIFIKASI (harus 0):
--   SELECT COUNT(*) FROM fo_skema s WHERE s.dp_persen>0
--     AND ABS(COALESCE((SELECT SUM(persen) FROM fo_skema_lines l WHERE l.skema_id=s.id AND l.tipe='DP'),0)-s.dp_persen)>0.001;
