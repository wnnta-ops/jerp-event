import 'package:flutter/material.dart';

import '../auth/session.dart';
import 'kunci_store.dart';
import 'pin_pad.dart';

/// Profil › Pengaturan › Kunci aplikasi.
class KunciPage extends StatefulWidget {
  const KunciPage({super.key, required this.kunci, required this.session});
  final KunciStore kunci;
  final Session session;

  @override
  State<KunciPage> createState() => _KunciPageState();
}

class _KunciPageState extends State<KunciPage> {
  bool? _bioTersedia;

  @override
  void initState() {
    super.initState();
    widget.kunci.bio.tersedia().then((v) {
      if (mounted) setState(() => _bioTersedia = v);
    });
  }

  KunciStore get k => widget.kunci;

  /// Buka layar PIN penuh; mengembalikan PIN yang dimasukkan atau null bila dibatalkan.
  Future<String?> _mintaPin(String judul, {String? keterangan, Future<String?> Function(String)? cek}) {
    return Navigator.of(context).push<String>(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (ctx) => Scaffold(
        appBar: AppBar(title: const Text('Kunci aplikasi')),
        body: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: PinPad(
              judul: judul,
              keterangan: keterangan,
              onSelesai: (pin) async {
                final err = await cek?.call(pin);
                if (err == null && ctx.mounted) Navigator.of(ctx).pop(pin);
                return err;
              },
            ),
          ),
        ),
      ),
    ));
  }

  /// PIN baru dua kali (buat + ulangi).
  Future<String?> _pinBaru() async {
    final pin1 = await _mintaPin('Buat PIN 6 digit', keterangan: 'Diminta setiap app dibuka.');
    if (pin1 == null || !mounted) return null;
    return _mintaPin('Ulangi PIN', cek: (p) async => p == pin1 ? null : 'PIN tidak sama, ulangi.');
  }

  Future<bool> _pinLama() async =>
      await _mintaPin('Masukkan PIN saat ini', cek: (p) async => await k.cocokPin(p) ? null : 'PIN salah.') != null;

  void _info(String pesan) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(pesan)));

  Future<void> _ubahAktif(bool nyala) async {
    if (nyala) {
      final pin = await _pinBaru();
      if (pin == null) return;
      await k.aktifkan(pin);
      _info('Kunci aplikasi aktif.');
    } else {
      if (!await _pinLama()) return;
      await k.reset();
      _info('Kunci aplikasi dimatikan.');
    }
  }

  Future<void> _gantiPin() async {
    if (!await _pinLama()) return;
    final pin = await _pinBaru();
    if (pin == null) return;
    await k.aktifkan(pin);
    _info('PIN diganti.');
  }

  Future<void> _ubahBio(bool nyala) async {
    if (!nyala) {
      await k.lepasBiometrik();
      return;
    }
    final u = widget.session.user!;
    final ok = await k.pasangBiometrik('${u.id}', u.name);
    _info(ok ? 'Biometrik aktif.' : 'Biometrik dibatalkan atau tidak didukung perangkat ini.');
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Kunci aplikasi')),
      body: ListenableBuilder(
        listenable: k,
        builder: (context, _) => ListView(children: [
          SwitchListTile(
            title: const Text('Kunci aplikasi'),
            subtitle: const Text('Minta PIN saat app dibuka dan setelah 5 menit di latar belakang'),
            value: k.aktif,
            onChanged: _ubahAktif,
          ),
          if (k.aktif) ...[
            ListTile(
              leading: const Icon(Icons.pin_outlined),
              title: const Text('Ganti PIN'),
              trailing: const Icon(Icons.chevron_right),
              onTap: _gantiPin,
            ),
            SwitchListTile(
              secondary: const Icon(Icons.fingerprint),
              title: const Text('Buka dengan biometrik'),
              subtitle: Text(switch (_bioTersedia) {
                null => 'Memeriksa perangkat…',
                false => 'Tidak didukung di perangkat/browser ini',
                true => 'Face ID / sidik jari; PIN tetap bisa dipakai',
              }),
              value: k.pakaiBiometrik,
              onChanged: _bioTersedia == true || k.pakaiBiometrik ? _ubahBio : null,
            ),
          ],
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              'Disimpan di perangkat ini saja. Salah PIN ${KunciStore.maksSalah}x atau "Lupa PIN" = keluar akun '
              'dan masuk lagi. Kunci dihapus saat Anda keluar.',
              style: tema.textTheme.bodySmall,
            ),
          ),
        ]),
      ),
    );
  }
}
