import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../inbox/inbox_models.dart';
import '../inbox/inbox_store.dart';
import '../inbox/widgets.dart';

class DetailPage extends StatefulWidget {
  const DetailPage({super.key, required this.store, required this.id});
  final InboxStore store;
  final int id;

  @override
  State<DetailPage> createState() => _DetailPageState();
}

class _DetailPageState extends State<DetailPage> {
  late Future<InboxDetail> _detail = widget.store.detail(widget.id);
  bool _proses = false;

  Future<void> _putuskan(bool setuju) async {
    final catatan = await _mintaCatatan(setuju);
    if (catatan == null || !mounted) return;
    setState(() => _proses = true);
    try {
      final pesan = await widget.store.putuskan(widget.id, setuju: setuju, catatan: catatan);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(pesan)));
      Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _proses = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  /// Setujui: konfirmasi + catatan opsional. Tolak: alasan wajib. null = batal.
  Future<String?> _mintaCatatan(bool setuju) {
    final ctrl = TextEditingController();
    String? error;
    return showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setD) => AlertDialog(
          title: Text(setuju ? 'Setujui dokumen ini?' : 'Tolak dokumen ini?'),
          content: TextField(
            controller: ctrl,
            autofocus: !setuju,
            maxLines: 3,
            maxLength: 500,
            decoration: InputDecoration(
              labelText: setuju ? 'Catatan (opsional)' : 'Alasan penolakan',
              border: const OutlineInputBorder(),
              errorText: error,
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: setuju ? oranye : Theme.of(ctx).colorScheme.error),
              onPressed: () {
                if (!setuju && ctrl.text.trim().isEmpty) {
                  setD(() => error = 'Alasan penolakan wajib diisi');
                  return;
                }
                Navigator.pop(ctx, ctrl.text);
              },
              child: Text(setuju ? 'Setujui' : 'Tolak'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(backgroundColor: oranye, foregroundColor: Colors.white, title: const Text('Detail approval')),
      body: FutureBuilder<InboxDetail>(
        future: _detail,
        builder: (context, snap) {
          if (snap.hasError) {
            final pesan = snap.error is ApiException ? (snap.error as ApiException).message : 'Gagal memuat dokumen.';
            return PesanError(
              pesan: pesan,
              ulang: () => setState(() => _detail = widget.store.detail(widget.id)),
            );
          }
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          return _isi(context, snap.data!);
        },
      ),
    );
  }

  Widget _isi(BuildContext context, InboxDetail d) {
    final tema = Theme.of(context);
    final redup = tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant);
    final i = d.item;
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Row(children: [
            LabelKategori(urgent: i.urgent),
            const SizedBox(width: 8),
            Expanded(child: Text('${i.type} · ${i.number ?? '#${i.id}'}', style: redup)),
          ]),
          const SizedBox(height: 8),
          Text(i.judul, style: tema.textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(rupiah(i.amount), style: tema.textTheme.headlineSmall?.copyWith(color: const Color(0xFFC2410C), fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          Text('Diajukan ${i.submitter ?? '—'} · ${tanggal(i.submittedAt)}', style: redup),
          const Divider(height: 32),
          for (final f in d.fields)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(width: 120, child: Text(f.label, style: redup)),
                Expanded(child: Text(f.value ?? '—')),
              ]),
            ),
          if (d.body != null && d.body!.trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('Keterangan', style: tema.textTheme.titleSmall),
            const SizedBox(height: 4),
            Text(d.body!),
          ],
          if (d.jumlahFile > 0) ...[
            const SizedBox(height: 12),
            Text('${d.jumlahFile} lampiran — buka di J-ERP web untuk melihat file.', style: redup),
          ],
          const Divider(height: 32),
          Text('Alur persetujuan (${i.done}/${i.total})', style: tema.textTheme.titleSmall),
          const SizedBox(height: 8),
          for (final s in d.stages) _tahap(context, s),
        ]),
      ),
      if (d.canAct)
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Row(children: [
              Expanded(
                child: OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: tema.colorScheme.error,
                    minimumSize: const Size.fromHeight(48),
                  ),
                  onPressed: _proses ? null : () => _putuskan(false),
                  child: const Text('Tolak'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: oranye, minimumSize: const Size.fromHeight(48)),
                  onPressed: _proses ? null : () => _putuskan(true),
                  child: _proses
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Text('Setujui'),
                ),
              ),
            ]),
          ),
        ),
    ]);
  }

  Widget _tahap(BuildContext context, Tahap s) {
    final (ikon, warna) = switch (s.state) {
      'done' => (Icons.check_circle, Colors.green.shade700),
      'rejected' => (Icons.cancel, Theme.of(context).colorScheme.error),
      'current' => (Icons.radio_button_checked, oranye),
      'skipped' => (Icons.remove_circle_outline, Colors.grey),
      _ => (Icons.radio_button_unchecked, Colors.grey),
    };
    final ket = [
      if (s.by != null) s.by!,
      if (s.at != null) tanggal(s.at),
      if (s.state == 'current') 'Menunggu',
    ].join(' · ');
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: Icon(ikon, color: warna),
      title: Text(s.label),
      subtitle: Text([ket, if (s.notes != null && s.notes!.isNotEmpty) '"${s.notes}"'].where((x) => x.isNotEmpty).join('\n')),
    );
  }
}
