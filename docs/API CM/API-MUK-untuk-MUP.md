# API MUK untuk MUP

**Kontrak Owner → Lokasi (unit) → Progres per lokasi → COP Owner.**
Dokumen untuk tim pengembang MUP · versi 1 · 16 September 2026 · disiapkan tim MUK ERP.

> **Ringkas:** API ini **baca-saja** (hanya `GET`). Tujuannya supaya MUP tidak perlu mengetik ulang progres
> konstruksi: angka per lokasi diambil langsung dari dokumen MUK (opname owner terposting dan COP Owner),
> sehingga koma-nya pasti sama di kedua sistem.

---

## 1. Alamat & autentikasi

| | |
|---|---|
| **Base URL** | `https://app.mukjavacoresystem.com/api/mup` |
| **Header wajib** | `X-API-Key: <kunci>` (boleh juga `Authorization: Bearer <kunci>`)<br>`Accept: application/json` |
| **Kunci API** | Dikirim terpisah oleh pihak MUK. **Jangan** menaruh kunci di front-end, repositori, atau URL. |
| **Batas laju** | 120 permintaan / menit. Melebihi → `429`. |
| **Metode** | Hanya `GET`. Tidak ada endpoint tulis. |

### Bentuk jawaban

```text
Sukses  : { "data": … }                 // daftar kontrak juga membawa "meta"
Gagal   : { "error": { "message": "…", "code": "…" } }
```

| Kode | Arti & tindakan |
|---|---|
| `200` | OK. |
| `401` `invalid_api_key` | Kunci salah/kosong — periksa header `X-API-Key`. |
| `403` `ip_not_allowed` | IP pemanggil di luar daftar yang diizinkan (bila daftar IP dipakai). Kabari tim MUK. |
| `404` | Kontrak tidak ada. |
| `422` | Parameter tak sah (mis. tanggal salah format, `per_halaman` > 200). |
| `429` | Kena batas laju — perlambat. |
| `503` `key_not_configured` / `integration_disabled` | Kunci belum dipasang / integrasi dimatikan di sisi MUK. Kabari tim MUK. |

---

## 2. Endpoint

### 2.1 `GET /ping` — cek kunci

```bash
curl -H "X-API-Key: <kunci>" https://app.mukjavacoresystem.com/api/mup/ping
```

```json
{ "data": { "ok": true, "layanan": "MUK · API MUP", "versi": 1, "waktu": "2026-09-16T11:49:06+07:00" } }
```

### 2.2 `GET /contracts` — daftar kontrak owner

Parameter (semua opsional): `proyek` (kode proyek) · `diperbarui_sejak` (tanggal/waktu ISO — untuk sinkron
berkala) · `halaman` · `per_halaman` (maks 200, bawaan 50).

```json
{
  "data": [
    {
      "id": 12,
      "no_kontrak": "NK2024050001",
      "no_spk": "…",
      "jenis": "kontrak",
      "proyek":    { "kode": "…", "nama": "…" },
      "pelanggan": { "kode": "…", "nama": "…" },
      "tanggal": "2024-05-01", "mulai": "2024-05-01", "selesai": "2024-12-31",
      "nilai_kontrak": 1000000000.00,
      "retensi_persen": 5.0,
      "status": "approved",
      "kumulatif_hari_ini": {
        "diopname": 680000000.00, "ditagih_neto": 342000000.00,
        "diterima": 0.00, "retensi_ditahan": 34000000.00
      },
      "diperbarui": "2026-09-14T08:00:00+07:00"
    }
  ],
  "meta": { "halaman": 1, "per_halaman": 50, "total": 101, "halaman_terakhir": 3 }
}
```

* `jenis`: `kontrak` atau `si` (Site Instruction).
* `nilai_kontrak`: DPP (sebelum PPN).
* `kumulatif_hari_ini`: keadaan **hari ini**, tidak bisa disaring tanggal — untuk angka bertanggal pakai `/progress`.

### 2.3 `GET /contracts/{id}` dan `GET /contracts/{id}/locations`

Kontrak beserta daftar **lokasi (unit)** dari rincian kontraknya:

```json
"lokasi": [
  { "lokasi_id": 4821, "kode": "L-A1", "nama": "A1 — CACA JRWC6 I NO.1",
    "tipe_rumah": "CACA", "ukuran": "60/90", "blok": "A", "no_rumah": "1",
    "baris_kontrak": 1, "nilai_kontrak": 600000000.00 }
]
```

### 2.4 `GET /contracts/{id}/progress?sd_tanggal=YYYY-MM-DD` — *inti untuk input tagihan*

Per lokasi: seluruh kolom lokasi di atas, ditambah:

```json
"opname":  { "nilai": 480000000.00, "persen": 80.00,
             "opname_terakhir": "2026-09-02", "jumlah_opname": 2 },
"ditagih": { "nilai": 360000000.00, "persen": 60.00, "belum_ditagih": 120000000.00 }
```

```json
"total": { "nilai_kontrak": 1000000000.00, "opname": 680000000.00,
           "ditagih": 360000000.00, "belum_ditagih": 320000000.00, "persen_opname": 68.00 }
```

**Arti angkanya:**

* `opname` — jumlah rincian opname owner **yang sudah diposting** s.d. tanggal tersebut untuk lokasi itu.
* `ditagih` — bagian dari opname itu yang **sudah tertaut ke COP Owner**.
* `belum_ditagih` — selisihnya; inilah angka yang biasanya dipakai MUP saat menyiapkan tagihan berikutnya.

Opname yang masih **draf** atau sudah **dihapus** tidak pernah ikut. Tanpa `sd_tanggal`, seluruh opname
terposting dihitung.

### 2.5 `GET /contracts/{id}/bills?sd_tanggal=` — COP Owner

```json
{ "no_tagihan": "INV-2026/08-0007", "jenis": "progress", "tanggal": "2026-08-10",
  "jatuh_tempo": "2026-09-09", "dpp": 342000000.00, "ppn": 37620000.00, "pph": 0.00,
  "total": 379620000.00, "diterima": 0.00, "status": "unpaid",
  "opname": [ { "no_opname": "OPO-2026/08-0011", "tanggal": "2026-08-05", "bruto": 360000000.00 } ] }
```

---

## 3. Alur yang disarankan untuk MUP

1. `GET /ping` sekali saat konfigurasi, memastikan kunci berlaku.
2. Tarik daftar kontrak: `GET /contracts?per_halaman=200`, simpan `id` + `no_kontrak`.
   Untuk sinkron berkala cukup `?diperbarui_sejak=<waktu tarikan terakhir>`.
3. Saat pengguna MUP membuat tagihan: `GET /contracts/{id}/progress?sd_tanggal=<tanggal tagihan>`,
   tampilkan per lokasi, dan pakai `ditagih.belum_ditagih` sebagai usulan nilai.
4. Bila perlu riwayat penagihan: `GET /contracts/{id}/bills`.

### Contoh pemanggilan

```bash
# curl
curl -s -H "X-API-Key: $KUNCI" -H "Accept: application/json" \
  "https://app.mukjavacoresystem.com/api/mup/contracts/12/progress?sd_tanggal=2026-09-30"
```

```php
// PHP (Laravel)
$r = Http::withHeaders(['X-API-Key' => config('services.muk.key')])
        ->get('https://app.mukjavacoresystem.com/api/mup/contracts/12/progress', ['sd_tanggal' => '2026-09-30']);
$lokasi = $r->json('data.lokasi');
```

```js
// JavaScript (server-side, JANGAN di browser)
const r = await fetch(url, { headers: { 'X-API-Key': process.env.MUK_API_KEY } });
const { data } = await r.json();
```

---

## 4. Catatan penting

* **Angka bertanggal vs kumulatif** — gunakan `/progress?sd_tanggal=` untuk angka per tanggal.
  Bagian `kumulatif_hari_ini` pada daftar kontrak adalah keadaan hari ini dan tidak bisa disaring tanggal.
* **Desimal** — seluruh nilai uang dikirim sebagai angka dengan 2 desimal (bukan teks berformat), supaya
  pembulatan kedua sistem sama.
* **Belum ada webhook** — sinkron dilakukan dengan polling `diperbarui_sejak`; interval 5–15 menit cukup.
* **Kunci identitas lokasi** — mohon disepakati: MUP memakai `lokasi_id` (angka, paling stabil), `kode`,
  atau pasangan `blok` + `no_rumah`? Ketiganya dikirim.
* Bila butuh tambahan (rincian per baris kontrak, lampiran/foto opname, atau webhook), sampaikan ke tim
  MUK — bisa ditambahkan.

---

*Pertanyaan teknis: hubungi tim MUK ERP. Kunci API dikirim melalui kanal terpisah dari dokumen ini.*
