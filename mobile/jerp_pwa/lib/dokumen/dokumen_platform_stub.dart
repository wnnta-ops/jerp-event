import 'dart:typed_data';

import 'package:flutter/widgets.dart';

import 'dokumen_platform.dart';

/// Di luar browser (flutter test di VM): HTML tampil sebagai placeholder, unduh tidak melakukan apa-apa.
DokumenPlatform buat() => _Stub();

class _Stub implements DokumenPlatform {
  @override
  Widget html(String isi, String kunci) => Center(child: Text('Pratinjau HTML ($kunci)'));

  @override
  Future<void> unduh(Uint8List isi, String nama, String mime) async {}
}
