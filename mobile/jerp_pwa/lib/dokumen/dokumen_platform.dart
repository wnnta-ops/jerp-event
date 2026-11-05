import 'dart:typed_data';

import 'package:flutter/widgets.dart';

import 'dokumen_platform_stub.dart' if (dart.library.js_interop) 'dokumen_platform_web.dart' as impl;

/// Bagian preview dokumen yang butuh browser: lembar HTML di iframe sandbox & simpan file.
abstract class DokumenPlatform {
  /// Tampilkan HTML mandiri (tanpa skrip) — [kunci] unik per dokumen.
  Widget html(String isi, String kunci);

  /// Simpan / unduh file ke perangkat.
  Future<void> unduh(Uint8List isi, String nama, String mime);

  /// Bisa diganti di test.
  static DokumenPlatform instance = impl.buat();
}
