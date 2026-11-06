import 'package:flutter/material.dart';

import '../config.dart';

/// Isian PIN 6 digit: titik + papan angka. [onSelesai] dipanggil saat digit ke-6 diisi;
/// kembalikan pesan error (PIN dikosongkan & pesan tampil) atau null bila diterima.
class PinPad extends StatefulWidget {
  const PinPad({super.key, required this.judul, required this.onSelesai, this.keterangan, this.aksiKiri});
  final String judul;
  final String? keterangan;
  final Future<String?> Function(String pin) onSelesai;

  /// Tombol di pojok kiri bawah papan (mis. biometrik).
  final Widget? aksiKiri;

  @override
  State<PinPad> createState() => _PinPadState();
}

class _PinPadState extends State<PinPad> {
  String _pin = '';
  String? _error;
  bool _proses = false;

  Future<void> _tekan(String d) async {
    if (_proses || _pin.length >= 6) return;
    setState(() {
      _pin += d;
      _error = null;
    });
    if (_pin.length == 6) {
      setState(() => _proses = true);
      final err = await widget.onSelesai(_pin);
      if (!mounted) return;
      setState(() {
        _proses = false;
        _error = err;
        if (err != null) _pin = '';
      });
    }
  }

  void _hapus() {
    if (_proses || _pin.isEmpty) return;
    setState(() => _pin = _pin.substring(0, _pin.length - 1));
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    Widget tombol(String d) => _Tombol(label: d, onTap: () => _tekan(d));
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text(widget.judul, style: tema.textTheme.titleLarge, textAlign: TextAlign.center),
      if (widget.keterangan != null) ...[
        const SizedBox(height: 6),
        Text(widget.keterangan!, style: tema.textTheme.bodySmall, textAlign: TextAlign.center),
      ],
      const SizedBox(height: 20),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        for (var i = 0; i < 6; i++)
          Container(
            key: ValueKey('titik$i'),
            margin: const EdgeInsets.symmetric(horizontal: 6),
            width: 14,
            height: 14,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: i < _pin.length ? aksen(context) : null,
              border: Border.all(color: aksen(context), width: 2),
            ),
          ),
      ]),
      SizedBox(
        height: 36,
        child: Center(
          child: _proses
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : Text(_error ?? '', style: TextStyle(color: tema.colorScheme.error), textAlign: TextAlign.center),
        ),
      ),
      for (final baris in const [
        ['1', '2', '3'],
        ['4', '5', '6'],
        ['7', '8', '9'],
      ])
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [for (final d in baris) tombol(d)]),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        SizedBox(width: 80, height: 64, child: Center(child: widget.aksiKiri)),
        tombol('0'),
        SizedBox(
          width: 80,
          height: 64,
          child: IconButton(tooltip: 'Hapus', onPressed: _hapus, icon: const Icon(Icons.backspace_outlined)),
        ),
      ]),
    ]);
  }
}

class _Tombol extends StatelessWidget {
  const _Tombol({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(4),
      child: SizedBox(
        width: 72,
        height: 56,
        child: OutlinedButton(
          style: OutlinedButton.styleFrom(shape: const StadiumBorder()),
          onPressed: onTap,
          child: Text(label, style: const TextStyle(fontSize: 22)),
        ),
      ),
    );
  }
}
