# Issue: "Gagal menyimpan. Coba lagi." saat simpan/unggah dokumen di Vercel

Catatan untuk tim IT. Ringkasan gejala, akar masalah, lokasi kode, dan opsi
perbaikan. Repo: `wnnta-ops/mup-legal-module`, app aktif: `legal-app/`
(Next.js 14 App Router + Prisma + Postgres/Neon, di-deploy ke Vercel).

---

## Gejala

- Buka `…/documents/new`, isi form, lampirkan berkas, klik **Simpan dokumen**.
- Muncul banner merah **"Gagal menyimpan. Coba lagi."**
- Terjadi untuk **semua user** dan **semua tipe dokumen**.
- **Di lokal (`npm run dev`) berhasil**, **di Vercel gagal** → ini sinyal kuat
  masalah environment serverless, bukan logika form.

Pesan "Gagal menyimpan. Coba lagi." berasal dari blok `catch` di form (server
action melempar exception), BUKAN error validasi field.
Lihat `components/upload-form.tsx`:

```ts
try {
  res = isEdit && initial ? await updateDocument(initial.id, fd) : await createDocument(fd);
} catch {
  setErrors({ _form: "Gagal menyimpan. Coba lagi." }); // <- banner merah ini
  ...
}
```

---

## Akar masalah (root cause)

### 1) Penyimpanan berkas menulis ke filesystem lokal — Vercel read-only

Server action menyimpan berkas yang diunggah ke `public/uploads/<docId>/` lewat
`fs` (`mkdir` + `writeFile`). Di Vercel (serverless/Lambda) **filesystem bersifat
read-only** kecuali `/tmp` (yang pun bersifat sementara). Jadi `mkdir`/`writeFile`
melempar `EROFS: read-only file system` → exception → "Gagal menyimpan".

Lokasi kode:
- `app/documents/new/actions.ts` → fungsi `createDocument()`, blok
  *"write files to /public/uploads/<docId>"* (`mkdir(...)`, `writeFile(...)`).
- `app/documents/actions.ts` → fungsi `updateDocument()`, bagian *"replace files
  on disk"* (`rm`, `mkdir`, `writeFile`).

Catatan tambahan: meskipun penulisan berhasil, **menyajikan** berkasnya juga
tidak akan jalan di Vercel. `DocumentFile.url` berbentuk `/uploads/<docId>/<file>`
yang mengandalkan berkas fisik di disk — tidak ada di serverless. (`app/api/file/route.ts`
juga membaca dari disk lokal repo, hanya valid di mesin dev.)

### 2) (Sekunder) Batas ukuran body Server Action default 1 MB

Next.js Server Actions punya default `bodySizeLimit` **1 MB**. Form ini mengirim
berkas sebagai FormData melalui server action `createDocument`. Berkas > 1 MB akan
**ditolak sebelum** masuk handler → juga memunculkan "Gagal menyimpan".
Belum dikonfigurasi: `next.config.mjs` saat ini hanya `{ reactStrictMode: true }`.

---

## Kenapa di lokal berhasil

Di `npm run dev`, filesystem laptop bisa ditulis, jadi `public/uploads/<docId>/`
terbuat dan berkas tersajikan via path statis. Masalah ini hanya muncul di
lingkungan serverless (Vercel).

---

## Opsi perbaikan

Pilih salah satu strategi penyimpanan berkas yang kompatibel serverless:

1. **Vercel Blob** (paling pas untuk Vercel). Perlu enable Blob store + env
   `BLOB_READ_WRITE_TOKEN`. Ganti `writeFile` → `put()` dari `@vercel/blob`,
   simpan URL publiknya ke `DocumentFile.url`.
2. **Object storage S3 / Cloudflare R2 / Supabase Storage**. Perlu kredensial.
3. **Simpan byte ke Postgres** (paling self-contained, tanpa layanan eksternal):
   tambah kolom `data Bytes` di model `DocumentFile`, simpan buffer ke DB, lalu
   sajikan via route `app/api/file/[id]/route.ts` (streaming) dengan `Content-Type`
   sesuai `mimeType`. Cocok untuk volume kecil/internal; hati-hati ukuran DB
   (Neon) untuk berkas besar.

Selain itu, untuk semua opsi, naikkan batas body server action di `next.config`:

```js
// next.config.mjs
const nextConfig = {
  reactStrictMode: true,
  experimental: { serverActions: { bodySizeLimit: "25mb" } },
};
export default nextConfig;
```

(Sesuaikan dengan batas berkas form = 20 MB.)

### Bonus bug terkait

Di `createDocument`, baris dokumen dibuat di DB **sebelum** berkas ditulis. Jika
penulisan berkas gagal (Vercel), baris dokumen "yatim" (tanpa berkas) tetap
tersimpan. Saat memindahkan ke storage baru, idealnya: upload berkas dulu →
baru `prisma.document.create`, atau bungkus dalam urutan yang aman/rollback.

---

## Cara reproduksi cepat

1. Deploy `legal-app/` ke Vercel (sudah live di `legal-internal-system.vercel.app`).
2. Login, buka `/documents/new`, pilih kategori, lampirkan PDF/JPG, Simpan.
3. Cek **Vercel → Project → Logs (Runtime)** saat submit: akan terlihat error
   `EROFS: read-only file system, mkdir '/var/task/public/uploads/...'`
   (atau error batas body bila berkas > 1 MB).
