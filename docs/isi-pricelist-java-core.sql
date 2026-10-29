-- ═══════════════════════════════════════════════════════════════════════════
-- Isi Price List skema JAVA CORE dari Price List eProperty (server TEST).
--
-- Skema hasil migrasi eProperty (berharga) sudah dinonaktifkan; skema aktif JAVA CORE
-- belum punya harga → Price List, Pilih Unit & SPRS menemukan Rp 0. Skrip ini mengisi
-- harga skema JAVA CORE per unit dari skema eProperty SEJENIS yang terbaru — dicoba
-- berurutan, yang pertama berharga > 0 untuk unit itu yang dipakai.
--
--   KPR FREE DP (JAVA CORE) ← KPR FREE DP CLUSTER 3.4 → 3.3 → CLUSTER 5 → KPR FREE DP
--   KPR DP 3X (JAVA CORE)   ← KPR DP 3X CLUSTER 3.4 → 3.3 → CLUSTER 5
--   CASH 1X (JAVA CORE)     ← CASH CLUSTER 3.4 → 3.3 → CASH C5 → CASH CLUSTER 3 → CASH
--   INHOUSE (JAVA CORE)     ← INHOUSE CLUSTER 3.4 → 3.3 → INHOUSE C5 → INHOUSE CLUSTER 3 → INHOUSE
--   CASH 3X (JAVA CORE) & KPR FREE DP (tanpa JAVA CORE): DILEWATI (menunggu keputusan).
--
-- Harga eProperty < Rp 50 jt dianggap isian penanda (bukan harga) dan dilewati — ditampilkan di laporan.
-- Hanya MENAMBAH baris yang belum ada (unit × skema JAVA CORE). Harga yang sudah diisi
-- di J-ERP tidak ditimpa; Price List skema lama tetap ada sebagai riwayat.
--
-- Laporan saja (tidak menulis):
--   sudo docker exec -i jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" jerp' < docs/isi-pricelist-java-core.sql
-- Terapkan:
--   (echo "SET @terapkan=1;"; cat docs/isi-pricelist-java-core.sql) | sudo docker exec -i jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" jerp'
-- ═══════════════════════════════════════════════════════════════════════════

DROP TEMPORARY TABLE IF EXISTS peta_jc;
CREATE TEMPORARY TABLE peta_jc (
    tujuan VARCHAR(40) COLLATE utf8mb4_unicode_ci,
    sumber VARCHAR(40) COLLATE utf8mb4_unicode_ci,
    urut INT
);
-- kode skema hasil migrasi = NoSkema eProperty
INSERT INTO peta_jc VALUES
    ('KPR-FREE-DP-JAVA-CORE', '32', 1), ('KPR-FREE-DP-JAVA-CORE', '28', 2), ('KPR-FREE-DP-JAVA-CORE', '22', 3), ('KPR-FREE-DP-JAVA-CORE', '18', 4),
    ('KPR-DP-3X-JAVA-CORE',   '33', 1), ('KPR-DP-3X-JAVA-CORE',   '29', 2), ('KPR-DP-3X-JAVA-CORE',   '23', 3),
    ('CASH-1X-JAVA-CORE',     '34', 1), ('CASH-1X-JAVA-CORE',     '30', 2), ('CASH-1X-JAVA-CORE',     '24', 3), ('CASH-1X-JAVA-CORE', '17', 4), ('CASH-1X-JAVA-CORE', '2', 5),
    ('INHOUSE-JAVA-CORE',     '35', 1), ('INHOUSE-JAVA-CORE',     '31', 2), ('INHOUSE-JAVA-CORE',     '25', 3), ('INHOUSE-JAVA-CORE', '16', 4), ('INHOUSE-JAVA-CORE', '3', 5);

DROP TEMPORARY TABLE IF EXISTS calon_jc;
CREATE TEMPORARY TABLE calon_jc AS
SELECT * FROM (
    SELECT t.id AS skema_tujuan, t.code AS kode_tujuan, t.name AS nama_tujuan, t.tenant_id,
           p.unit_id, s.code AS kode_sumber, s.name AS nama_sumber,
           p.price_list, p.price_list_minimum, p.price_list_kavling, p.price_list_kavling_minimum,
           ROW_NUMBER() OVER (PARTITION BY t.id, p.unit_id ORDER BY m.urut) AS rn
    FROM peta_jc m
    JOIN fo_skema t ON t.code = m.tujuan AND t.deleted_at IS NULL
    JOIN fo_skema s ON s.code = m.sumber AND s.tenant_id = t.tenant_id
    -- < Rp 50 jt = isian penanda di eProperty (mis. Rp 3.000.000 pada unit TERJUAL), bukan harga → dilewati
    JOIN fo_unit_pricelists p ON p.skema_id = s.id AND p.price_list >= 50000000
) x
WHERE x.rn = 1
  AND NOT EXISTS (SELECT 1 FROM fo_unit_pricelists e WHERE e.unit_id = x.unit_id AND e.skema_id = x.skema_tujuan);

-- ── LAPORAN ─────────────────────────────────────────────────────────────────
SELECT 'Skema JAVA CORE yang ditemukan' AS laporan;
SELECT t.id, t.code, t.name, t.is_active,
       (SELECT COUNT(*) FROM fo_unit_pricelists p WHERE p.skema_id = t.id AND p.price_list > 0) AS sudah_berharga
FROM fo_skema t WHERE t.code IN (SELECT DISTINCT tujuan FROM peta_jc) AND t.deleted_at IS NULL;

SELECT 'Akan diisi — per skema JAVA CORE & sumber eProperty' AS laporan;
SELECT nama_tujuan, nama_sumber, COUNT(*) AS unit, MIN(price_list) AS harga_min, MAX(price_list) AS harga_max
FROM calon_jc GROUP BY nama_tujuan, nama_sumber ORDER BY nama_tujuan, MIN(kode_sumber + 0) DESC;

SELECT 'Total akan diisi per skema' AS laporan;
SELECT nama_tujuan, COUNT(*) AS unit FROM calon_jc GROUP BY nama_tujuan;

SELECT 'Harga eProperty tidak wajar (< Rp 50 jt) — DILEWATI, cek manual' AS laporan;
SELECT u.kode_unit, u.status, s.name AS skema, p.price_list
FROM peta_jc m
JOIN fo_skema s ON s.code = m.sumber
JOIN fo_unit_pricelists p ON p.skema_id = s.id AND p.price_list > 0 AND p.price_list < 50000000
JOIN fo_units u ON u.id = p.unit_id
ORDER BY u.kode_unit, s.name;

SELECT 'Contoh (10 unit pertama)' AS laporan;
SELECT u.kode_unit, c.nama_tujuan, c.nama_sumber, c.price_list
FROM calon_jc c JOIN fo_units u ON u.id = c.unit_id ORDER BY u.kode_unit, c.nama_tujuan LIMIT 10;

-- ── TERAPKAN (hanya bila @terapkan = 1) ─────────────────────────────────────
INSERT INTO fo_unit_pricelists (tenant_id, unit_id, skema_id, no_skema, price_list_minimum, price_list_kavling_minimum,
    price_list, price_list_kavling, created_at, updated_at)
SELECT tenant_id, unit_id, skema_tujuan, kode_tujuan, price_list_minimum, price_list_kavling_minimum,
    price_list, price_list_kavling, NOW(), NOW()
FROM calon_jc
WHERE @terapkan = 1;

SELECT IF(@terapkan = 1, CONCAT('DITERAPKAN: ', ROW_COUNT(), ' baris Price List ditambahkan'), 'UJI SAJA — tidak ada yang ditulis') AS hasil;
