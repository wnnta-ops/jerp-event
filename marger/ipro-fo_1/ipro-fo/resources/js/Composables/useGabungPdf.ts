// Gabungkan beberapa file (PDF / JPG / PNG) jadi SATU PDF di browser — untuk "Lihat Semua" berkas.
// Dikerjakan di sisi klien (pdf-lib, dimuat saat dipakai saja) supaya server tak butuh library PDF.
// Halaman pertama = sampul berisi daftar isi; file yang gagal dibaca (mis. PDF terkunci) diganti
// halaman keterangan, tidak menggagalkan seluruh gabungan.

export type FileGabung = { name: string; url: string; mime: string; jenis: string | null };
export type Sampul = { judul: string; subjudul?: string; baris: Array<[string, string]> };

const A4: [number, number] = [595.28, 841.89];

export async function gabungPdf(files: FileGabung[], sampul: Sampul): Promise<Blob> {
    const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');
    const out = await PDFDocument.create();
    const font = await out.embedFont(StandardFonts.Helvetica);
    const tebal = await out.embedFont(StandardFonts.HelveticaBold);
    const abu = rgb(0.42, 0.42, 0.42);
    // Font standar hanya WinAnsi — buang karakter lain agar tidak melempar galat.
    const aman = (s: string) => s.replace(/[^\x20-\x7E -ÿ]/g, '?');

    const cover = out.addPage(A4);
    const daftar: Array<{ no: number; jenis: string; nama: string; hal: number; gagal: boolean }> = [];

    for (const [i, f] of files.entries()) {
        const mulai = out.getPageCount() + 1;
        let gagal = false;
        try {
            const bytes = new Uint8Array(await (await fetch(f.url, { credentials: 'same-origin' })).arrayBuffer());
            if (f.mime === 'application/pdf') {
                const src = await PDFDocument.load(bytes, { ignoreEncryption: true });
                for (const p of await out.copyPages(src, src.getPageIndices())) out.addPage(p);
            } else {
                const img = f.mime === 'image/png' ? await out.embedPng(bytes) : await out.embedJpg(bytes);
                const page = out.addPage(A4);
                const m = 36;
                const s = Math.min((A4[0] - 2 * m) / img.width, (A4[1] - 2 * m) / img.height, 1);
                const w = img.width * s, h = img.height * s;
                page.drawImage(img, { x: (A4[0] - w) / 2, y: (A4[1] - h) / 2, width: w, height: h });
            }
        } catch {
            gagal = true;
            const page = out.addPage(A4);
            page.drawText(aman(`File tidak bisa digabung: ${f.name}`), { x: 48, y: A4[1] - 80, size: 13, font: tebal });
            page.drawText('Buka file ini terpisah lewat tombol "Buka" di halaman berkas.', { x: 48, y: A4[1] - 102, size: 10, font, color: abu });
        }
        daftar.push({ no: i + 1, jenis: f.jenis ?? 'Jenis lain', nama: f.name, hal: mulai, gagal });
    }

    // Sampul + daftar isi.
    let y = A4[1] - 64;
    cover.drawText(aman(sampul.judul), { x: 48, y, size: 18, font: tebal });
    if (sampul.subjudul) cover.drawText(aman(sampul.subjudul), { x: 48, y: (y -= 22), size: 11, font, color: abu });
    y -= 30;
    for (const [k, v] of sampul.baris) {
        cover.drawText(aman(k), { x: 48, y, size: 9, font, color: abu });
        cover.drawText(aman(v).slice(0, 80), { x: 170, y, size: 10, font });
        y -= 16;
    }
    y -= 18;
    cover.drawText(`Daftar isi (${files.length} file)`, { x: 48, y, size: 12, font: tebal });
    y -= 8;
    cover.drawLine({ start: { x: 48, y }, end: { x: A4[0] - 48, y }, thickness: 0.6, color: abu });
    y -= 18;
    for (const d of daftar) {
        if (y < 60) break;
        cover.drawText(`${d.no}.`, { x: 48, y, size: 10, font });
        cover.drawText(aman(d.jenis).slice(0, 34), { x: 66, y, size: 10, font: tebal });
        cover.drawText(aman(d.nama + (d.gagal ? ' (tidak bisa digabung)' : '')).slice(0, 52), { x: 250, y, size: 10, font, color: d.gagal ? rgb(0.75, 0.2, 0.2) : rgb(0, 0, 0) });
        const hal = `hal. ${d.hal}`;
        cover.drawText(hal, { x: A4[0] - 48 - font.widthOfTextAtSize(hal, 10), y, size: 10, font, color: abu });
        y -= 18;
    }
    cover.drawText(aman(`Digabung ${new Date().toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })} - Java Core System`), { x: 48, y: 36, size: 8, font, color: abu });

    // Nomor halaman di pojok kanan bawah setiap halaman.
    const total = out.getPageCount();
    out.getPages().forEach((p, i) => {
        const t = `${i + 1} / ${total}`;
        p.drawText(t, { x: p.getWidth() - 36 - font.widthOfTextAtSize(t, 8), y: 16, size: 8, font, color: abu });
    });

    return new Blob([(await out.save()) as BlobPart], { type: 'application/pdf' });
}
