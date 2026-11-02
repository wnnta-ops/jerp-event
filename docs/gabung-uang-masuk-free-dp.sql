-- =====================================================================================
-- Gabung baris "UANG MASUK" hasil konversi Free DP eProperty menjadi 1 baris per kontrak
-- =====================================================================================
--
-- Latar: ETL 45_samakan_free_dp.sql (28 Okt 2026) mengganti nama tiap baris subsidi
-- eProperty (DP 1/2/3, SUBSIDI DP, DISKON, …) menjadi "UANG MASUK" SATU PER SATU →
-- kontrak hasil migrasi tampil 2–4 baris UANG MASUK, padahal kontrak J-ERP cukup 1.
--
-- Per kontrak yang punya >1 baris (keterangan 'Free DP eProperty:%'):
--   - baris pertama (urutan, id) dipertahankan: nilai & pelunasan = jumlah, jatuh tempo =
--     yang paling awal, keterangan = 'Free DP eProperty: DP 1 + DP 2 + DP 3'
--   - kwitansi (fo_bkm) & collection notice yang menunjuk baris lain dipindah ke baris itu
--   - baris lain dihapus
-- Nilai Kontrak, total tagihan, pelunasan, PPN, kwitansi & jurnal TIDAK berubah.
--
-- Cadangan (dibuat sekali, tidak pernah ditimpa — aman dijalankan ulang):
--   bak_gabung_um_tagihan  (salinan baris yang tersentuh)
--   bak_gabung_um_bkm      (tautan kwitansi asal)
--   bak_gabung_um_notice   (tautan notice asal)
--
-- Cara pakai (dari ~/docker/jerp):
--   UJI  : docker compose exec -T mysql sh -c 'mysql -ujerp -p"$MYSQL_PASSWORD" --table jerp' < docs/gabung-uang-masuk-free-dp.sql
--   JALAN: ( echo "SET @jalankan = 1;"; cat docs/gabung-uang-masuk-free-dp.sql ) | docker compose exec -T mysql sh -c 'mysql -ujerp -p"$MYSQL_PASSWORD" --table jerp'
-- Tanpa @jalankan = 1 semua perubahan di-ROLLBACK (hanya laporan). Dengan @jalankan = 1
-- tetap di-ROLLBACK bila pemeriksaan akhir tidak lolos.
-- Sesudah JALAN: docker compose exec app php artisan ipro:backfill spr   (samakan jadwal di BO)
-- =====================================================================================

SET @jalankan = IFNULL(@jalankan, 0);

-- ── Cadangan (DDL di luar transaksi) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bak_gabung_um_tagihan LIKE fo_kontrak_tagihan;
CREATE TABLE IF NOT EXISTS bak_gabung_um_bkm (
    bkm_id BIGINT UNSIGNED PRIMARY KEY, kontrak_tagihan_id BIGINT UNSIGNED NULL, dicadangkan_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS bak_gabung_um_notice (
    notice_id BIGINT UNSIGNED PRIMARY KEY, kontrak_tagihan_id BIGINT UNSIGNED NULL, dicadangkan_at DATETIME NOT NULL
);

-- Catatan: JANGAN ALTER tabel sementara di dalam transaksi — ALTER memicu COMMIT diam-diam.
START TRANSACTION;

-- Kunci baris UANG MASUK hasil konversi selama transaksi (FOR UPDATE tak boleh di CREATE … SELECT).
SELECT COUNT(*) INTO @dikunci FROM fo_kontrak_tagihan
 WHERE tipe = 'ADM' AND nama_tagihan = 'UANG MASUK' AND keterangan LIKE 'Free DP eProperty:%' FOR UPDATE;

-- Baris yang digabung: hanya kontrak dengan >1 baris hasil konversi.
DROP TEMPORARY TABLE IF EXISTS tmp_um;
CREATE TEMPORARY TABLE tmp_um AS
SELECT t.id, t.kontrak_id, t.urutan, t.jatuh_tempo, t.nilai, t.pelunasan,
       SUBSTRING_INDEX(SUBSTRING(t.keterangan, 20), ' — ', 1) AS nama_asli
FROM fo_kontrak_tagihan t
JOIN (SELECT kontrak_id FROM fo_kontrak_tagihan
       WHERE tipe = 'ADM' AND nama_tagihan = 'UANG MASUK' AND keterangan LIKE 'Free DP eProperty:%'
       GROUP BY kontrak_id HAVING COUNT(*) > 1) g ON g.kontrak_id = t.kontrak_id
WHERE t.tipe = 'ADM' AND t.nama_tagihan = 'UANG MASUK' AND t.keterangan LIKE 'Free DP eProperty:%';

-- Ringkasan per kontrak + baris yang dipertahankan.
DROP TEMPORARY TABLE IF EXISTS tmp_um_kontrak;
CREATE TEMPORARY TABLE tmp_um_kontrak (PRIMARY KEY (kontrak_id)) AS
SELECT kontrak_id,
       CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY urutan, id), ',', 1) AS UNSIGNED) AS keep_id,
       SUM(nilai) AS nilai, SUM(pelunasan) AS pelunasan, MIN(jatuh_tempo) AS jatuh_tempo,
       LEFT(CONCAT('Free DP eProperty: ', GROUP_CONCAT(nama_asli ORDER BY urutan, id SEPARATOR ' + ')), 255) AS keterangan,
       COUNT(*) AS n_baris
FROM tmp_um GROUP BY kontrak_id;

DROP TEMPORARY TABLE IF EXISTS tmp_um_buang;
CREATE TEMPORARY TABLE tmp_um_buang (PRIMARY KEY (id)) AS
SELECT u.id, k.keep_id FROM tmp_um u JOIN tmp_um_kontrak k ON k.kontrak_id = u.kontrak_id WHERE u.id <> k.keep_id;

-- Total tagihan kontrak terdampak SEBELUM (untuk pemeriksaan akhir).
DROP TEMPORARY TABLE IF EXISTS tmp_um_total;
CREATE TEMPORARY TABLE tmp_um_total AS
SELECT t.kontrak_id, SUM(t.nilai) AS nilai, SUM(t.pelunasan) AS pelunasan
FROM fo_kontrak_tagihan t JOIN tmp_um_kontrak k ON k.kontrak_id = t.kontrak_id GROUP BY t.kontrak_id;

SELECT 'RENCANA' AS tahap, COUNT(*) AS kontrak, SUM(n_baris) AS baris_sekarang, SUM(n_baris - 1) AS baris_dihapus,
       ROUND(SUM(nilai)) AS nilai_uang_masuk
FROM tmp_um_kontrak;

-- ── Cadangan isi (INSERT IGNORE: salinan asli tidak tertimpa bila dijalankan ulang) ──
INSERT IGNORE INTO bak_gabung_um_tagihan SELECT t.* FROM fo_kontrak_tagihan t JOIN tmp_um u ON u.id = t.id;
INSERT IGNORE INTO bak_gabung_um_bkm SELECT b.id, b.kontrak_tagihan_id, NOW() FROM fo_bkm b JOIN tmp_um u ON u.id = b.kontrak_tagihan_id;
INSERT IGNORE INTO bak_gabung_um_notice SELECT c.id, c.kontrak_tagihan_id, NOW() FROM fo_collection_notices c JOIN tmp_um u ON u.id = c.kontrak_tagihan_id;

-- ── Gabung ───────────────────────────────────────────────────────────────────────────
UPDATE fo_bkm b JOIN tmp_um_buang x ON x.id = b.kontrak_tagihan_id SET b.kontrak_tagihan_id = x.keep_id, b.updated_at = b.updated_at;
SELECT ROW_COUNT() AS kwitansi_dipindah;
UPDATE fo_collection_notices c JOIN tmp_um_buang x ON x.id = c.kontrak_tagihan_id SET c.kontrak_tagihan_id = x.keep_id, c.updated_at = c.updated_at;
SELECT ROW_COUNT() AS notice_dipindah;

UPDATE fo_kontrak_tagihan t JOIN tmp_um_kontrak k ON k.keep_id = t.id
SET t.nilai = k.nilai, t.pelunasan = k.pelunasan, t.jatuh_tempo = k.jatuh_tempo, t.keterangan = k.keterangan,
    t.updated_at = t.updated_at;

DELETE t FROM fo_kontrak_tagihan t JOIN tmp_um_buang x ON x.id = t.id;
SELECT ROW_COUNT() AS baris_dihapus;

-- ── Pemeriksaan akhir ────────────────────────────────────────────────────────────────
SELECT COUNT(*) INTO @selisih FROM tmp_um_total s
LEFT JOIN (SELECT kontrak_id, SUM(nilai) nilai, SUM(pelunasan) pelunasan FROM fo_kontrak_tagihan GROUP BY kontrak_id) n
       ON n.kontrak_id = s.kontrak_id
WHERE n.kontrak_id IS NULL OR ABS(n.nilai - s.nilai) > 0.005 OR ABS(n.pelunasan - s.pelunasan) > 0.005;

SELECT COUNT(*) INTO @masih_ganda FROM (
    SELECT kontrak_id FROM fo_kontrak_tagihan
     WHERE tipe = 'ADM' AND nama_tagihan = 'UANG MASUK' AND keterangan LIKE 'Free DP eProperty:%'
     GROUP BY kontrak_id HAVING COUNT(*) > 1) x;

SELECT COUNT(*) INTO @tautan_putus FROM fo_bkm b JOIN tmp_um_buang x ON x.id = b.kontrak_tagihan_id;

SELECT @selisih AS kontrak_total_berubah, @masih_ganda AS kontrak_masih_ganda, @tautan_putus AS kwitansi_tertinggal,
       IF(@jalankan = 1 AND @selisih = 0 AND @masih_ganda = 0 AND @tautan_putus = 0, 'COMMIT', 'ROLLBACK') AS hasil;

SELECT k.no_kontrak, u.n_baris AS baris_lama, t.nilai, t.pelunasan, t.jatuh_tempo, t.keterangan
FROM tmp_um_kontrak u JOIN fo_kontrak k ON k.id = u.kontrak_id JOIN fo_kontrak_tagihan t ON t.id = u.keep_id
ORDER BY u.kontrak_id DESC LIMIT 10;

SET @akhir = IF(@jalankan = 1 AND @selisih = 0 AND @masih_ganda = 0 AND @tautan_putus = 0, 'COMMIT', 'ROLLBACK');
PREPARE akhir FROM @akhir;
EXECUTE akhir;
DEALLOCATE PREPARE akhir;
