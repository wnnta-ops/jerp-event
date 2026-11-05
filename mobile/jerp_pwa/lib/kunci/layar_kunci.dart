import 'package:flutter/material.dart';

import '../auth/session.dart';
import '../config.dart';
import 'kunci_store.dart';
import 'pin_pad.dart';

/// Layar penutup saat app terkunci. Salah PIN [KunciStore.maksSalah] kali → keluar akun.
class LayarKunci extends StatelessWidget {
  const LayarKunci({super.key, required this.kunci, required this.session});
  final KunciStore kunci;
  final Session session;

  Future<void> _lupa(BuildContext context) async {
    final ya = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Lupa PIN?'),
        content: const Text('Anda akan keluar dan perlu masuk lagi dengan akun J-ERP. Kunci aplikasi dimatikan.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ya == true) await session.keluar();
  }

  @override
  Widget build(BuildContext context) {
    final user = session.user;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(children: [
              const Icon(Icons.lock_outline, size: 40, color: oranye),
              const SizedBox(height: 12),
              PinPad(
                judul: 'Masukkan PIN',
                keterangan: user == null ? null : 'J-ERP Approver · ${user.name}',
                onSelesai: (pin) async {
                  if (await kunci.bukaDenganPin(pin)) return null;
                  if (kunci.sisaPercobaan <= 0) {
                    await session.keluar();
                    return 'Terlalu banyak salah — silakan masuk lagi.';
                  }
                  return 'PIN salah. Sisa ${kunci.sisaPercobaan} percobaan.';
                },
                aksiKiri: kunci.pakaiBiometrik
                    ? IconButton(
                        tooltip: 'Buka dengan biometrik',
                        iconSize: 32,
                        color: oranye,
                        onPressed: kunci.bukaDenganBiometrik,
                        icon: const Icon(Icons.fingerprint),
                      )
                    : null,
              ),
              const SizedBox(height: 12),
              TextButton(onPressed: () => _lupa(context), child: const Text('Lupa PIN?')),
            ]),
          ),
        ),
      ),
    );
  }
}
