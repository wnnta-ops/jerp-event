import 'dart:async';
import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

import 'dokumen_platform.dart';

DokumenPlatform buat() => _Web();

class _Web implements DokumenPlatform {
  final _terdaftar = <String>{};

  /// Skala aktif per view (didaftarkan ulang tiap popup dibuka).
  final _skala = <String, ValueListenable<double>>{};

  @override
  Widget html(String isi, String kunci, ValueListenable<double> skala) {
    final tipe = 'jerp-dokumen-$kunci-${isi.hashCode}';
    _skala[tipe] = skala;
    if (_terdaftar.add(tipe)) {
      ui_web.platformViewRegistry.registerViewFactory(tipe, (int _) {
        final f = web.HTMLIFrameElement()
          // Tanpa allow-scripts: skrip, form, popup tetap mati (BO juga sudah membuang <script>).
          // allow-same-origin hanya supaya app bisa mengecilkan lembar A4 agar muat selebar HP.
          ..setAttribute('sandbox', 'allow-same-origin')
          ..srcdoc = isi.toJS;
        // Lembar A4 dikecilkan agar muat selebar HP (pas), lalu dikali skala dari tombol zoom.
        var pas = 1.0;
        void terapkan() {
          final akar = f.contentDocument?.documentElement;
          if (akar != null) (akar as web.HTMLElement).style.setProperty('zoom', '${pas * (_skala[tipe]?.value ?? 1)}');
        }
        f.onload = ((web.Event _) {
          final akar = f.contentDocument?.documentElement;
          final lebar = akar?.scrollWidth ?? 0;
          if (lebar > f.clientWidth && f.clientWidth > 0) pas = f.clientWidth / lebar;
          terapkan();
          _skala[tipe]?.addListener(terapkan);
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

  /// HTML → iframe tersembunyi (ukur lembar & batas halaman cetaknya) → SVG foreignObject → kanvas 2×
  /// → satu JPEG per halaman. Semua gambar di lembar BO sudah data URI, jadi kanvas bisa diekspor.
  @override
  Future<List<Uint8List>?> htmlKeJpeg(String isi) async {
    final f = web.HTMLIFrameElement()
      ..setAttribute('sandbox', 'allow-same-origin')
      ..srcdoc = isi.toJS;
    f.style
      ..position = 'fixed'
      ..left = '-10000px'
      ..top = '0'
      ..width = '900px'
      ..height = '1200px'
      ..border = '0';
    final dimuat = Completer<void>();
    f.onload = ((web.Event _) => dimuat.complete()).toJS;
    web.document.body?.append(f);
    try {
      await dimuat.future.timeout(const Duration(seconds: 15));
      final doc = f.contentDocument;
      final akar = doc?.documentElement;
      final jendela = f.contentWindow;
      if (doc == null || akar == null || jendela == null) return null;
      final lebar = akar.scrollWidth.clamp(320, 2000);
      f.style.width = '${lebar}px';
      final tinggi = akar.scrollHeight.clamp(200, 7800);

      // Batas halaman = elemen dengan page-break / break before-after "always" / "page".
      final batas = <int>{0, tinggi};
      final semua = doc.querySelectorAll('*');
      for (var i = 0; i < semua.length; i++) {
        final el = semua.item(i) as web.Element;
        final gaya = jendela.getComputedStyle(el);
        bool putus(String v) => v == 'page' || v == 'always';
        final kotak = el.getBoundingClientRect();
        if (putus(gaya.breakBefore) || putus(gaya.getPropertyValue('page-break-before'))) batas.add(kotak.top.round());
        if (putus(gaya.breakAfter) || putus(gaya.getPropertyValue('page-break-after'))) {
          batas.add((kotak.bottom + (double.tryParse(gaya.marginBottom.replaceAll('px', '')) ?? 0)).round());
        }
      }
      final potong = batas.where((y) => y >= 0 && y <= tinggi).toList()..sort();

      final xhtml = web.XMLSerializer().serializeToString(akar);
      final svg = '<svg xmlns="http://www.w3.org/2000/svg" width="$lebar" height="$tinggi">'
          '<foreignObject width="100%" height="100%">$xhtml</foreignObject></svg>';
      final gambar = web.HTMLImageElement()..src = 'data:image/svg+xml;charset=utf-8,${Uri.encodeComponent(svg)}';
      await gambar.decode().toDart;

      const skala = 2;
      final hasil = <Uint8List>[];
      for (var i = 0; i + 1 < potong.length; i++) {
        final y = potong[i], t = potong[i + 1] - potong[i];
        if (t < 40) continue; // sisa margin kosong
        final kanvas = web.HTMLCanvasElement()
          ..width = lebar * skala
          ..height = t * skala;
        final ctx = kanvas.getContext('2d') as web.CanvasRenderingContext2D;
        ctx
          ..fillStyle = 'white'.toJS
          ..fillRect(0, 0, kanvas.width, kanvas.height)
          ..scale(skala, skala)
          ..drawImage(gambar, 0, -y);
        final jadi = Completer<web.Blob?>();
        kanvas.toBlob(((web.Blob? b) => jadi.complete(b)).toJS, 'image/jpeg', 0.9.toJS);
        final blob = await jadi.future;
        if (blob != null) hasil.add((await blob.arrayBuffer().toDart).toDart.asUint8List());
      }
      return hasil.isEmpty ? null : hasil;
    } catch (_) {
      return null;
    } finally {
      f.remove();
    }
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
