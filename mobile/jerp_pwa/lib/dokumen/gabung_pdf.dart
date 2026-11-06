import 'dart:typed_data';

import 'package:image/image.dart' as img;
import 'package:pdfrx/pdfrx.dart';

import '../api/api_client.dart';

/// Lebar halaman foto dalam PDF gabungan (pt) — selebar A4.
const _lebarA4 = 595.0;

/// Gabungkan file PDF & foto satu pengajuan jadi satu PDF (urutan sesuai daftar).
/// Foto dijadikan halaman selebar A4; file selain PDF/foto dilewati.
Future<Uint8List> gabungJadiPdf(List<Unduhan> file) async {
  // PdfViewer menginisialisasi pdfrx sendiri; API dokumen langsung belum — wajib dipanggil dulu.
  await pdfrxFlutterInitialize();
  final sumber = <PdfDocument>[];
  try {
    final halaman = <PdfPage>[];
    var i = 0;
    for (final f in file) {
      i++;
      final tipe = f.tipe.toLowerCase();
      PdfDocument? doc;
      if (tipe.startsWith('application/pdf')) {
        doc = await PdfDocument.openData(f.isi, sourceName: 'gabung-$i');
      } else if (tipe.startsWith('image/')) {
        final foto = img.decodeImage(f.isi);
        if (foto == null) continue;
        final jpg = tipe.contains('jpeg') || tipe.contains('jpg') ? f.isi : img.encodeJpg(foto, quality: 90);
        doc = await PdfDocument.createFromJpegData(jpg,
            width: _lebarA4, height: _lebarA4 * foto.height / foto.width, sourceName: 'gabung-$i');
      }
      if (doc == null) continue;
      sumber.add(doc);
      halaman.addAll(doc.pages);
    }
    if (halaman.isEmpty) throw ApiException('Tidak ada file PDF / foto untuk digabung.');
    final hasil = await PdfDocument.createNew(sourceName: 'gabungan');
    sumber.add(hasil);
    hasil.pages = halaman;
    return await hasil.encodePdf();
  } finally {
    for (final d in sumber) {
      await d.dispose();
    }
  }
}
