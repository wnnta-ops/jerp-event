-- =====================================================================
-- KOREKSI SKEMA DP 3X (DP DISEBAR)  (jerp) — subsidi 20% developer aktif kembali.
--
-- Masalah (2 cacat DATA hasil migrasi, pada 3 skema "KPR DP 3X"):
--  1. bf_dipotong_di = 'DPDisebar' (nilai legacy) padahal kode memakai konstanta
--     kanonik 'DP_DISEBAR' (pakai underscore). Akibatnya applyDpDisebarSubsidi()
--     short-circuit (KontrakGeneratorService.php:275) — subsidi & pengurangan UTJ
--     TIDAK PERNAH jalan; DP dihitung gross (mis. unit 619jt: DP 3x10.337.300 &
--     plafond sisa 584.988.100).
--  2. subsidi_persen = 0 (migrasi tak mengisinya). DP 3X = Free 20% dari developer
--     (sumber: docs/PERHITUNGAN SKEMA BAYAR.csv). Recovery: aturan bisnis, bukan .bak.
--
-- Setelah fix + generate ulang, unit 619jt menghasilkan: Subsidi 6.190.000,
-- DP 1-3 @7.253.333 (Σ 21.760.000), PLAFOND KPR 588.050.000 — cocok Excel manual.
-- Jalur subsidi di kode sudah aljabar-benar → CUKUP perbaikan DATA, tanpa ubah kode.
-- Teruji di lokal (tinker breakdown skema 33).
--
--   sudo docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" jerp fo_skema' > backup_skema_$(date +%F).sql
--   sudo docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" jerp' < docs/koreksi-skema-dp3x-subsidi.sql
--
-- PENTING — cakupan SENGAJA dibatasi ke 3 skema DP 3X saja:
--  * Skema DPDisebar lain (KPR biasa/RUHA/CASH/INHOUSE) TIDAK disentuh — enum legacy
--    mereka biarkan apa adanya. Membenahi enum mereka akan MENGAKTIFKAN logika dorman
--    (applyAngsuranDisebarUtj / applyFreeDpAngsuran1) dan MENGUBAH output SPR mereka —
--    itu proyek terpisah dengan validasi per-skema, bukan bagian koreksi ini.
--  * fo_skema_lines TIDAK diubah (DP sudah 5,00% via koreksi-skema-dp-persen.sql).
--  * SPR lama tak berubah sendiri (nilai tersimpan). Hanya SPR BARU otomatis benar;
--    kontrak lama DP 3X mayoritas historis legacy (settled) — JANGAN regenerate massal.
-- =====================================================================

UPDATE fo_skema
SET bf_dipotong_di = 'DP_DISEBAR',
    subsidi_persen = 20
WHERE id IN (23, 29, 33)             -- KPR DP 3X CLUSTER 5 / 3.3 / 3.4
  AND name LIKE 'KPR DP 3X%'         -- guard nama
  AND bf_dipotong_di = 'DPDisebar';  -- idempoten: sekali jalan sudah 'DP_DISEBAR'

-- VERIFIKASI (harus 3 baris: DP_DISEBAR / 20):
--   SELECT id, name, bf_dipotong_di, subsidi_persen FROM fo_skema WHERE id IN (23,29,33);
