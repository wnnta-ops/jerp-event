import 'dart:js_interop';
import 'dart:typed_data';
import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

import 'dokumen_platform.dart';

DokumenPlatform buat() => _Web();

class _Web implements DokumenPlatform {
  final _terdaftar = <String>{};

  @override
  Widget html(String isi, String kunci) {
    final tipe = 'jerp-dokumen-$kunci-${isi.hashCode}';
    if (_terdaftar.add(tipe)) {
      ui_web.platformViewRegistry.registerViewFactory(tipe, (int _) {
        final f = web.HTMLIFrameElement()
          // Tanpa allow-scripts: skrip, form, popup tetap mati (BO juga sudah membuang <script>).
          // allow-same-origin hanya supaya app bisa mengecilkan lembar A4 agar muat selebar HP.
          ..setAttribute('sandbox', 'allow-same-origin')
          ..srcdoc = isi.toJS;
        f.onload = ((web.Event _) {
          final akar = f.contentDocument?.documentElement;
          final lebar = akar?.scrollWidth ?? 0;
          if (akar != null && lebar > f.clientWidth && f.clientWidth > 0) {
            (akar as web.HTMLElement).style.setProperty('zoom', '${f.clientWidth / lebar}');
          }
        }).toJS;
        f.style
          ..border = '0'
          ..width = '100%'
          ..height = '100%'
          ..backgroundColor = 'white';
        return f;
      });
    }
    return HtmlElementView(viewType: tipe);
  }

  @override
  Future<void> unduh(Uint8List isi, String nama, String mime) async {
    final blob = web.Blob([isi.toJS].toJS, web.BlobPropertyBag(type: mime));
    final url = web.URL.createObjectURL(blob);
    final a = web.HTMLAnchorElement()
      ..href = url
      ..download = nama;
    web.document.body?.append(a);
    a.click();
    a.remove();
    // Beri waktu browser mulai mengunduh sebelum URL blob dicabut.
    Future<void>.delayed(const Duration(seconds: 30), () => web.URL.revokeObjectURL(url));
  }
}
