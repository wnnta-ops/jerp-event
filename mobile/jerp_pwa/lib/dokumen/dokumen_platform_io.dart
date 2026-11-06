import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:webview_flutter/webview_flutter.dart';

import 'dokumen_platform.dart';
import 'dokumen_platform_stub.dart';

/// APK Android: lembar HTML di WebView, Unduh = simpan file lalu buka lembar bagikan
/// (Simpan ke Files / Drive / WhatsApp). Di VM (flutter test) tetap stub.
DokumenPlatform buat() => Platform.isAndroid ? _Android() : DokumenStub();

class _Android implements DokumenPlatform {
  @override
  Widget html(String isi, String kunci, ValueListenable<double> skala) => _LembarHtml(isi: isi, skala: skala);

  @override
  Future<List<Uint8List>?> htmlKeJpeg(String isi) async => null;

  @override
  Future<void> unduh(Uint8List isi, String nama, String mime) async {
    final dir = await getTemporaryDirectory();
    final aman = nama.replaceAll(RegExp(r'[\\/:*?"<>|]+'), '-');
    final file = File('${dir.path}/$aman');
    await file.writeAsBytes(isi, flush: true);
    await SharePlus.instance.share(ShareParams(files: [XFile(file.path, mimeType: mime, name: aman)], subject: aman));
  }
}

class _LembarHtml extends StatefulWidget {
  const _LembarHtml({required this.isi, required this.skala});
  final String isi;
  final ValueListenable<double> skala;

  @override
  State<_LembarHtml> createState() => _LembarHtmlState();
}

class _LembarHtmlState extends State<_LembarHtml> {
  late final WebViewController _web = WebViewController()
    // Isi dari server sudah tanpa <script>; JS hanya dipakai app untuk mengatur zoom.
    ..setJavaScriptMode(JavaScriptMode.unrestricted)
    ..setNavigationDelegate(NavigationDelegate(
      // Tautan di lembar tidak dibuka di dalam app.
      onNavigationRequest: (r) => r.url.startsWith('data:') || r.url == 'about:blank' ? NavigationDecision.navigate : NavigationDecision.prevent,
      onPageFinished: (_) => _terapkan(),
    ))
    ..loadHtmlString(widget.isi);

  @override
  void initState() {
    super.initState();
    widget.skala.addListener(_terapkan);
  }

  @override
  void dispose() {
    widget.skala.removeListener(_terapkan);
    super.dispose();
  }

  /// Lembar A4 dikecilkan agar pas selebar layar, lalu dikali skala dari tombol zoom.
  void _terapkan() {
    _web.runJavaScript('''
      (function(){var a=document.documentElement;a.style.zoom=1;
      var pas=a.scrollWidth>window.innerWidth?window.innerWidth/a.scrollWidth:1;
      a.style.zoom=pas*${widget.skala.value};})();''');
  }

  @override
  Widget build(BuildContext context) => WebViewWidget(controller: _web);
}
