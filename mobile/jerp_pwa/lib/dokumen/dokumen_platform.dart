
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'dokumen_platform_stub.dart'
    if (dart.library.io) 'dokumen_platform_io.dart'
    if (dart.library.js_interop) 'dokumen_platform_web.dart' as impl;

/// Bagian preview dokumen yang beda per platform: lembar HTML (iframe sandbox di web, WebView di
/// Android) & simpan file.
abstract class DokumenPlatform {
  /// Tampilkan HTML mandiri (tanpa skrip) — [kunci] unik per dokumen; [skala] 1 = pas selebar layar.
  Widget html(String isi, String kunci, ValueListenable<double> skala);

  /// Lembar HTML mandiri jadi gambar JPEG per halaman (dipecah di batas halaman cetak lembarnya, 2×)
  /// supaya bisa digabung ke PDF & di-zoom seperti file biasa. null = platform ini tidak bisa
  /// (tampil sebagai HTML saja).
  Future<List<Uint8List>?> htmlKeJpeg(String isi);

  /// Simpan / unduh file ke perangkat.
  Future<void> unduh(Uint8List isi, String nama, String mime);

  /// Bisa diganti di test.
  static DokumenPlatform instance = impl.buat();
}
