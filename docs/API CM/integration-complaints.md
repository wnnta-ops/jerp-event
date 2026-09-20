# API Komplain (integrasi)

Narik data komplain garansi Java Residence dari sistem luar. Read-only, auth pakai API key.

Base URL:

```
https://erpmuk.java-sistem.com/api/integration
```

## Auth

Semua request wajib bawa header:

```
X-API-KEY: <keynya>
```

Kalau salah atau kosong dapat `401`. Kalau dapat `503`, berarti key belum dipasang di server — kabari kami.

Pakai HTTPS. Datanya ada PII (nama, no HP, unit), jadi simpan key-nya baik-baik dan jangan taruh di repo/front-end.

## Endpoint

### List komplain

```
GET /complaints
```

Query param, semua opsional:

- `status` — filter status. Bisa CSV, mis. `verified,assigned`. Default: semua status.
- `cluster` — kode cluster, mis. `JRK`.
- `q` — cari di kode komplain, nama pelapor, atau kode unit.
- `updated_since` — ISO8601. Cuma ambil komplain yang berubah sejak waktu itu. Ini buat sync inkremental (lihat catatan di bawah).
- `per_page` — 1–100, default 25.

Response:

```json
{
  "data": [ /* array objek komplain, lihat di bawah */ ],
  "meta": {
    "pagination": { "total": 143, "per_page": 25, "current_page": 1, "last_page": 6 },
    "server_time": "2026-09-15T09:30:00+07:00"
  }
}
```

Halaman berikutnya: `?page=2`.

### Detail komplain

```
GET /complaints/{id}
```

Response: `{ "data": { ...objek komplain... } }`.

## Objek komplain

```json
{
  "id": 123,
  "code": "KOMP/2026/09/0007",
  "tracking_code": "ABC123",
  "status": "assigned",
  "status_label": "Ditugaskan",
  "unit": { "id": 45, "code": "A1", "name": "Blok A1", "cluster_code": "JRK", "cluster_name": "Java Residence Krian" },
  "customer_name": "Budi",
  "customer_phone": "081234567890",
  "description": "…",
  "complaint_date": "2026-09-13",
  "assigned_to": "Tim A",
  "assigned_at": "2026-09-14T08:00:00+07:00",
  "started_at": null,
  "resolved_at": null,
  "created_at": "2026-09-13T10:00:00+07:00",
  "updated_at": "2026-09-14T08:00:00+07:00",
  "progress_done": 1,
  "progress_total": 3,
  "progress_pct": 33,
  "rejection_reason": null,
  "warranty": { "status": "active", "label": "…" },
  "items": [
    {
      "id": 1, "line_no": 1, "description": "Pintu macet",
      "area_label": "Kamar Mandi", "damage_type_label": "Pintu", "damage_detail_label": "…", "severity_label": "Sedang",
      "status": "pending", "is_rejected": false, "is_done": false, "rejection_reason": null, "completed_at": null,
      "photos": [
        { "id": 10, "type": "customer_report", "type_label": "Foto Pelapor", "url": "https://…signed…", "caption": null, "complaint_item_id": 1, "is_image": true }
      ]
    }
  ],
  "attachments": [ /* foto level komplain (tanpa baris), bentuk sama seperti items[].photos */ ],
  "timeline": [
    { "status_from": "pending", "status_to": "verified", "notes": "…", "entry_type": "status", "is_internal": false, "created_at": "…" }
  ]
}
```

Yang gampang kelewat:

- `photos[].url` itu signed URL dan ada masa berlakunya. Download pas nerima, jangan simpan URL-nya buat dipakai nanti.
- `timeline[].is_internal = true` itu catatan internal tim. Filter kalau nggak mau kelihatan sama end-user.
- Nggak ada webhook. Datanya selalu dibaca langsung dari DB, jadi buat "real-time" cukup polling: simpan `meta.server_time` dari response terakhir, lalu request lagi pakai `updated_since` = nilai itu. Interval 15–60 detik biasanya cukup.

## Inbox verifikasi komplain

Bukan endpoint terpisah — ini cuma komplain yang lagi nunggu diverifikasi
(status `pending_verification`):

```
GET /complaints?status=pending_verification
```

## Cluster

```
GET /clusters          # list
GET /clusters/{id}     # detail
```

Param list (opsional): `q` (kode/nama), `updated_since`, `per_page` (1–100).

Field: `id, code, name, description, sort_order, is_active, units_count, created_at, updated_at`.

## Unit

```
GET /units             # list
GET /units/{id}        # detail
```

Param list (opsional): `cluster` (kode cluster), `q` (kode/nama/pemilik), `is_active` (`true`/`false`), `updated_since`, `per_page`.

Field (lengkap):

```json
{
  "id": 45,
  "cluster_code": "JRK", "cluster_name": "Java Residence Krian",
  "code": "A1", "access_code": "XXXXXX", "name": "Blok A1", "note": null, "is_active": true,
  "owner_name": "Pak Test", "owner_phone": "0812…", "owner_email": null,
  "bast_date": "2026-08-01", "bast_no": "…", "bast_signed_at": "…",
  "bast_handover_name": "…", "bast_handover_role": "…",
  "bast_signature_owner_url": "https://…signed…", "bast_signature_handover_url": null,
  "warranty_general_until": "2026-10-30", "warranty_leak_until": "2027-01-28",
  "renovation_date": null, "renovation_note": null,
  "luas_bangunan": "60.00", "luas_tanah": "72.00",
  "plan_x": null, "plan_y": null,
  "warranty": { "status": "active", "label": "…" },
  "created_at": "…", "updated_at": "…"
}
```

Penting:

- `access_code` itu **kredensial login APP Warga**. Perlakukan sebagai rahasia — jangan tampilkan/teruskan sembarangan.
- `bast_signature_*_url` signed URL sementara (sama seperti foto komplain) — download pas nerima.

## Status code

- `200` ok
- `401` key salah / kosong
- `422` param nggak valid (`status` atau `updated_since`)
- `429` kena rate limit (120 req/menit)
- `503` key belum dipasang di server
