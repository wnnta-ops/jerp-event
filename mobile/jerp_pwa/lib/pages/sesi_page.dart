import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/session.dart';
import '../config.dart';
import '../inbox/inbox_models.dart';
import '../inbox/widgets.dart';

class SesiPage extends StatefulWidget {
  const SesiPage({super.key, required this.session});
  final Session session;

  @override
  State<SesiPage> createState() => _SesiPageState();
}

class _SesiPageState extends State<SesiPage> {
  List<Map<String, dynamic>>? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _muat();
  }

  Future<void> _muat() async {
    try {
      final res = await widget.session.api.get('/auth/sessions');
      if (mounted) {
        setState(() {
          _data = (res['data'] as List).cast<Map<String, dynamic>>();
          _error = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<bool> _yakin(String judul, String isi, String tombol) async =>
      await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(judul),
          content: Text(isi),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tombol)),
          ],
        ),
      ) ==
      true;

  Future<void> _keluarkan(Map<String, dynamic> s) async {
    if (!await _yakin('Keluarkan perangkat?', '${s['perangkat']} harus masuk lagi untuk memakai app.', 'Keluarkan')) return;
    try {
      await widget.session.api.delete('/auth/sessions/${s['id']}');
      await _muat();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _keluarSemua() async {
    if (!await _yakin('Keluar dari semua perangkat?', 'Semua perangkat, termasuk yang ini, harus masuk lagi.', 'Keluar semua')) return;
    try {
      await widget.session.keluarSemua();
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final data = _data;
    return Scaffold(
      appBar: AppBar(title: const Text('Perangkat & sesi')),
      body: RefreshIndicator(
        onRefresh: _muat,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          if (_error != null && data == null)
            PesanError(pesan: _error!, ulang: _muat)
          else if (data == null)
            const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
          else ...[
            for (final s in data)
              Card(
                child: ListTile(
                  leading: Icon(s['ini'] == true ? Icons.smartphone : Icons.devices_other, color: s['ini'] == true ? aksen(context) : null),
                  title: Text('${s['perangkat']}${s['ini'] == true ? ' (perangkat ini)' : ''}'),
                  subtitle: Text(
                    'Masuk ${tanggal(DateTime.tryParse(s['masuk_at'] ?? '')?.toLocal())}\n'
                    'Terakhir dipakai ${tanggal(DateTime.tryParse(s['terakhir_at'] ?? '')?.toLocal())}',
                  ),
                  isThreeLine: true,
                  trailing: s['ini'] == true
                      ? null
                      : IconButton(tooltip: 'Keluarkan', icon: const Icon(Icons.logout), onPressed: () => _keluarkan(s)),
                ),
              ),
            const SizedBox(height: 16),
            OutlinedButton.icon(
              style: OutlinedButton.styleFrom(foregroundColor: tema.colorScheme.error, minimumSize: const Size.fromHeight(48)),
              onPressed: _keluarSemua,
              icon: const Icon(Icons.logout),
              label: const Text('Keluar dari semua perangkat'),
            ),
          ],
        ]),
      ),
    );
  }
}
