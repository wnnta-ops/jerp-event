
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'dokumen_platform.dart';

/// Di luar browser (flutter test di VM): HTML tampil sebagai placeholder, unduh tidak melakukan apa-apa.
DokumenPlatform buat() => DokumenStub();

class DokumenStub implements DokumenPlatform {
  @override
  Widget html(String isi, String kunci, ValueListenable<double> skala) => Center(child: Text('Pratinjau HTML ($kunci)'));

  @override
  Future<List<Uint8List>?> htmlKeJpeg(String isi) async => null;

  @override
  Future<void> unduh(Uint8List isi, String nama, String mime) async {}
}
