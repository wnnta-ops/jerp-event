import 'package:flutter/material.dart';

import '../config.dart';
import '../prefs.dart';

class TampilanPage extends StatelessWidget {
  const TampilanPage({super.key, required this.prefs, required this.periodeBerubah});
  final Preferensi prefs;

  /// Dipanggil saat periode dashboard diganti, supaya Home memuat ulang.
  final VoidCallback periodeBerubah;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    Widget judul(String t) => Padding(
          padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
          child: Text(t, style: tema.textTheme.titleSmall),
        );
    return Scaffold(
      appBar: AppBar(backgroundColor: oranye, foregroundColor: Colors.white, title: const Text('Tampilan')),
      body: ListenableBuilder(
        listenable: prefs,
        builder: (context, _) => ListView(children: [
          judul('Tab saat app dibuka'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<int>(
              segments: const [
                ButtonSegment(value: 0, label: Text('Home'), icon: Icon(Icons.home_outlined)),
                ButtonSegment(value: 1, label: Text('Approval'), icon: Icon(Icons.inbox_outlined)),
              ],
              selected: {prefs.tabAwal},
              onSelectionChanged: (s) => prefs.setTabAwal(s.first),
            ),
          ),
          judul('Tema'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<ThemeMode>(
              segments: const [
                ButtonSegment(value: ThemeMode.system, label: Text('Ikuti HP')),
                ButtonSegment(value: ThemeMode.light, label: Text('Terang')),
                ButtonSegment(value: ThemeMode.dark, label: Text('Gelap')),
              ],
              selected: {prefs.tema},
              onSelectionChanged: (s) => prefs.setTema(s.first),
            ),
          ),
          const SizedBox(height: 8),
          SwitchListTile(
            title: const Text('Teks lebih besar'),
            value: prefs.teksBesar,
            onChanged: prefs.setTeksBesar,
          ),
          judul('Periode dashboard'),
          RadioGroup<PeriodeDashboard>(
            groupValue: prefs.periode,
            onChanged: (v) async {
              if (v == null) return;
              await prefs.setPeriode(v);
              periodeBerubah();
            },
            child: const Column(children: [
              RadioListTile(value: PeriodeDashboard.delapanBulan, title: Text('8 bulan terakhir'), subtitle: Text('Sama dengan bawaan dashboard BO')),
              RadioListTile(value: PeriodeDashboard.tahunBerjalan, title: Text('Tahun berjalan'), subtitle: Text('Januari s.d. hari ini')),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text('Disimpan di perangkat ini saja.', style: tema.textTheme.bodySmall),
          ),
        ]),
      ),
    );
  }
}
