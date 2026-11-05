import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../inbox/inbox_models.dart';
import 'dokumen_platform.dart';

/// Penampil PDF — bisa diganti di test (pdfium tidak tersedia di VM).
Widget Function(Uint8List isi, String kunci) penampilPdf = (isi, kunci) => PdfViewer.data(isi, sourceName: kunci);

/// Buka dokumen dalam popup (hampir layar penuh) dengan tombol Unduh.
Future<void> bukaDokumen(BuildContext context, ApiClient api, int approvalId, DokumenItem dok) {
  return showDialog<void>(
    context: context,
    builder: (_) => DokumenPopup(api: api, approvalId: approvalId, dok: dok),
  );
}

class DokumenPopup extends StatefulWidget {
  const DokumenPopup({super.key, required this.api, required this.approvalId, required this.dok});
  final ApiClient api;
  final int approvalId;
  final DokumenItem dok;

  @override
  State<DokumenPopup> createState() => _DokumenPopupState();
}

class _DokumenPopupState extends State<DokumenPopup> {
  late final Future<Unduhan> _isi = widget.api.getBytes('/inbox/${widget.approvalId}/dokumen/${widget.dok.kunci}');
  bool _mengunduh = false;

  Future<void> _unduh(Unduhan u) async {
    setState(() => _mengunduh = true);
    try {
      await DokumenPlatform.instance.unduh(u.isi, u.nama ?? widget.dok.nama, u.tipe.split(';').first);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Dokumen diunduh.')));
    } finally {
      if (mounted) setState(() => _mengunduh = false);
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
        builder: (context, snap) => Column(children: [
          Material(
            color: oranye,
            child: SafeArea(
              bottom: false,
              child: Row(children: [
                const SizedBox(width: 16),
                Expanded(
                  child: Text(
                    widget.dok.nama,
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                IconButton(
                  tooltip: 'Unduh',
                  color: Colors.white,
                  onPressed: snap.hasData && !_mengunduh ? () => _unduh(snap.data!) : null,
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
        ]),
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
    if (tipe.startsWith('application/pdf')) return penampilPdf(u.isi, widget.dok.kunci);
    if (tipe.startsWith('image/')) {
      return InteractiveViewer(maxScale: 6, child: Center(child: Image.memory(u.isi)));
    }
    if (tipe.startsWith('text/html')) {
      return Container(color: Colors.white, child: DokumenPlatform.instance.html(utf8.decode(u.isi, allowMalformed: true), widget.dok.kunci));
    }
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text('Jenis file ini (${u.tipe}) tidak bisa dipratinjau — tekan Unduh.', textAlign: TextAlign.center),
      ),
    );
  }
}
