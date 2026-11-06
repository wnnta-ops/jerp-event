import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../inbox/inbox_models.dart';
import 'dokumen_platform.dart';
import 'gabung_pdf.dart';

/// Penampil PDF — bisa diganti di test (pdfium tidak tersedia di VM).
Widget Function(Uint8List isi, String kunci, PdfViewerController ctrl) penampilPdf =
    (isi, kunci, ctrl) => PdfViewer.data(isi, sourceName: kunci, controller: ctrl);

/// Penggabung file jadi satu PDF — bisa diganti di test.
Future<Uint8List> Function(List<Unduhan> file) penggabung = gabungJadiPdf;

/// Buka dokumen dalam popup (hampir layar penuh) dengan tombol Zoom & Unduh. Lembar HTML
/// (memo / lampiran) dijadikan PDF dulu bila platform bisa — supaya bisa di-pinch zoom.
Future<void> bukaDokumen(BuildContext context, ApiClient api, int approvalId, DokumenItem dok) {
  return showDialog<void>(
    context: context,
    builder: (_) => DokumenPopup(
      judul: dok.nama,
      kunci: dok.kunci,
      muat: () async => keHalaman(await api.getBytes('/inbox/$approvalId/dokumen/${dok.kunci}'), dok.nama),
    ),
  );
}

/// Memo + dokumen pendukung sekaligus: semua jadi satu PDF berurutan (memo dulu), satu klik.
Future<void> bukaGabungan(BuildContext context, ApiClient api, int approvalId, String judul, String nomor, List<DokumenItem> dok) {
  return showDialog<void>(
    context: context,
    builder: (_) => DokumenPopup(
      judul: judul,
      kunci: 'semua-$approvalId',
      muat: () async {
        // Satu file gagal dimuat tidak menggagalkan semuanya — dilewati; semua gagal → tampilkan galatnya.
        Object? galat;
        final isi = await Future.wait([
          for (final d in dok)
            api.getBytes('/inbox/$approvalId/dokumen/${d.kunci}').then<Unduhan?>((u) => u, onError: (Object e) {
              galat ??= e;
              return null;
            }),
        ]);
        final halaman = <Unduhan>[];
        for (final u in isi.whereType<Unduhan>()) {
          halaman.addAll(await keGambar(u));
        }
        if (halaman.isEmpty && galat != null) throw galat!;
        final pdf = await penggabung(halaman);
        return Unduhan(isi: pdf, tipe: 'application/pdf', nama: '$judul $nomor.pdf'.replaceAll('/', '-'));
      },
    ),
  );
}

/// HTML → JPEG per halaman (bisa digabung ke PDF); PDF / foto apa adanya; lainnya (atau HTML yang gagal) kosong.
Future<List<Unduhan>> keGambar(Unduhan u) async {
  final tipe = u.tipe.toLowerCase();
  if (tipe.startsWith('application/pdf') || tipe.startsWith('image/')) return [u];
  if (!tipe.startsWith('text/html')) return const [];
  final jpg = await DokumenPlatform.instance.htmlKeJpeg(utf8.decode(u.isi, allowMalformed: true));
  return [for (final j in jpg ?? const <Uint8List>[]) Unduhan(isi: j, tipe: 'image/jpeg', nama: u.nama)];
}

/// Satu lembar HTML jadi PDF (bisa di-zoom & disimpan sebagai PDF); selain itu / bila gagal apa adanya.
Future<Unduhan> keHalaman(Unduhan u, String nama) async {
  if (!u.tipe.toLowerCase().startsWith('text/html')) return u;
  final jpg = await keGambar(u);
  if (jpg.isEmpty) return u;
  try {
    final pdf = await penggabung(jpg);
    return Unduhan(isi: pdf, tipe: 'application/pdf', nama: '${(u.nama ?? nama).replaceAll(RegExp(r'\.html?$'), '')}.pdf');
  } catch (_) {
    return u;
  }
}

class DokumenPopup extends StatefulWidget {
  const DokumenPopup({super.key, required this.judul, required this.kunci, required this.muat});
  final String judul;
  final String kunci;
  final Future<Unduhan> Function() muat;

  @override
  State<DokumenPopup> createState() => _DokumenPopupState();
}

class _DokumenPopupState extends State<DokumenPopup> {
  late final Future<Unduhan> _isi = widget.muat();
  final _pdf = PdfViewerController();
  final _gambar = TransformationController();

  /// Skala lembar HTML (1 = pas selebar layar).
  final _skalaHtml = ValueNotifier<double>(1);
  bool _mengunduh = false;

  @override
  void dispose() {
    _gambar.dispose();
    _skalaHtml.dispose();
    super.dispose();
  }

  Future<void> _unduh(Unduhan u) async {
    setState(() => _mengunduh = true);
    try {
      await DokumenPlatform.instance.unduh(u.isi, u.nama ?? widget.judul, u.tipe.split(';').first);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Dokumen diunduh.')));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Gagal menyimpan dokumen.')));
    } finally {
      if (mounted) setState(() => _mengunduh = false);
    }
  }

  void _zoom(Unduhan u, bool masuk) {
    final tipe = u.tipe.toLowerCase();
    if (tipe.startsWith('application/pdf')) {
      if (_pdf.isReady) masuk ? _pdf.zoomUp() : _pdf.zoomDown();
    } else if (tipe.startsWith('image/')) {
      final s = (_gambar.value.getMaxScaleOnAxis() * (masuk ? 1.25 : 0.8)).clamp(1.0, 6.0);
      final ukuran = context.size ?? const Size(400, 600);
      final c = Offset(ukuran.width / 2, ukuran.height / 2);
      _gambar.value = Matrix4.identity()
        ..translateByDouble(c.dx * (1 - s), c.dy * (1 - s), 0, 1)
        ..scaleByDouble(s, s, 1, 1);
    } else {
      _skalaHtml.value = (_skalaHtml.value * (masuk ? 1.25 : 0.8)).clamp(0.5, 4.0);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Dialog(
      insetPadding: const EdgeInsets.all(12),
      clipBehavior: Clip.antiAlias,
      child: FutureBuilder<Unduhan>(
        future: _isi,
        builder: (context, snap) {
          final siap = snap.hasData;
          return Column(children: [
            Material(
              color: warnaHeader(context),
              child: SafeArea(
                bottom: false,
                child: Row(children: [
                  const SizedBox(width: 16),
                  Expanded(
                    child: Text(
                      widget.judul,
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  IconButton(
                    tooltip: 'Perkecil',
                    color: Colors.white,
                    onPressed: siap ? () => _zoom(snap.data!, false) : null,
                    icon: const Icon(Icons.zoom_out),
                  ),
                  IconButton(
                    tooltip: 'Perbesar',
                    color: Colors.white,
                    onPressed: siap ? () => _zoom(snap.data!, true) : null,
                    icon: const Icon(Icons.zoom_in),
                  ),
                  IconButton(
                    tooltip: 'Unduh',
                    color: Colors.white,
                    onPressed: siap && !_mengunduh ? () => _unduh(snap.data!) : null,
                    icon: const Icon(Icons.download),
                  ),
                  IconButton(
                    tooltip: 'Tutup',
                    color: Colors.white,
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(Icons.close),
                  ),
                ]),
              ),
            ),
            Expanded(child: _isiDokumen(context, snap, tema)),
          ]);
        },
      ),
    );
  }

  Widget _isiDokumen(BuildContext context, AsyncSnapshot<Unduhan> snap, ThemeData tema) {
    if (snap.hasError) {
      final pesan = snap.error is ApiException ? (snap.error as ApiException).message : 'Gagal memuat dokumen.';
      return Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(pesan, textAlign: TextAlign.center)));
    }
    if (!snap.hasData) return const Center(child: CircularProgressIndicator());

    final u = snap.data!;
    final tipe = u.tipe.toLowerCase();
    if (tipe.startsWith('application/pdf')) return penampilPdf(u.isi, widget.kunci, _pdf);
    if (tipe.startsWith('image/')) {
      return InteractiveViewer(transformationController: _gambar, maxScale: 6, child: Center(child: Image.memory(u.isi)));
    }
    if (tipe.startsWith('text/html')) {
      return Container(
        color: Colors.white,
        child: DokumenPlatform.instance.html(utf8.decode(u.isi, allowMalformed: true), widget.kunci, _skalaHtml),
      );
    }
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text('Jenis file ini (${u.tipe}) tidak bisa dipratinjau — tekan Unduh.', textAlign: TextAlign.center),
      ),
    );
  }
}
