import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../inbox/inbox_models.dart';

/// Judul & satuan tiap grafik — sama dengan Pages/Dashboard/Index.vue di BO.
const _judul = {
  'inisiasi_kpr': ('1 · Inisiasi KPR', _Satuan.berkas),
  'proses_kpr': ('2 · Proses KPR', _Satuan.berkas),
  'penolakan': ('3 · Penolakan BI Checking & Proses KPR', _Satuan.berkas),
  'rekening': ('4 · Summary Rekening Ledger', _Satuan.rp),
  'kasbon': ('5 · Bon Sementara / Kasbon', _Satuan.rp),
  'aging': ('6 · Aging Hutang', _Satuan.rp),
  'neraca': ('7 · Summary Neraca', _Satuan.rp),
  'laba_rugi': ('8 · Summary Laba Rugi', _Satuan.rp),
  'aset_tetap': ('9 · Overview Aset Tetap', _Satuan.rp),
  'budget': ('10 · Realisasi Budget', _Satuan.rp),
  'spk': ('11 · SPK per Cluster', _Satuan.rp),
  'progress': ('12 · Progress Lapangan', _Satuan.unit),
  'retensi': ('13 · Summary Retensi', _Satuan.rp),
  'bast_ajb_br': ('14–16 · BAST → AJB → Booked Revenue', _Satuan.unit),
  'piutang': ('17 · Rekonsiliasi Piutang', _Satuan.rp),
  'hpp': ('18 · Summary HPP', _Satuan.rp),
};

enum _Satuan { rp, berkas, unit }

const _warna = [
  Color(0xFFF97316),
  Color(0xFF0EA5E9),
  Color(0xFF22C55E),
  Color(0xFFA855F7),
  Color(0xFFEAB308),
  Color(0xFF64748B),
];

Color _w(Object? i) => _warna[((i as num?)?.toInt() ?? 0) % _warna.length];

/// Angka ringkas: Rp 13,8 M / Rp 450 jt / 1.089 berkas.
String _ringkas(num v, _Satuan s) {
  if (s != _Satuan.rp) return '${_ribuan(v)} ${s == _Satuan.berkas ? 'berkas' : 'unit'}';
  final a = v.abs();
  final tanda = v < 0 ? '−' : '';
  String f(double x) => x.toStringAsFixed(x >= 100 ? 0 : 1).replaceAll('.', ',');
  if (a >= 1e12) return '${tanda}Rp ${f(a / 1e12)} T';
  if (a >= 1e9) return '${tanda}Rp ${f(a / 1e9)} M';
  if (a >= 1e6) return '${tanda}Rp ${f(a / 1e6)} jt';
  return '$tanda${rupiah(a.toDouble())}';
}

String _ribuan(num v) => v.round().toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => '.');

double _n(Object? v) => (v as num?)?.toDouble() ?? 0;

/// Satu kartu grafik; bentuk data dikenali dari isinya (rows / labels+series / keys).
class KartuGrafik extends StatelessWidget {
  const KartuGrafik({super.key, required this.kunci, required this.data});
  final String kunci;
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final (judul, satuan) = _judul[kunci] ?? (kunci, _Satuan.rp);
    final isi = _isi(context, satuan);
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(judul, style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 10),
          isi ?? Text('Belum ada data di periode ini.', style: Theme.of(context).textTheme.bodySmall),
        ]),
      ),
    );
  }

  Widget? _isi(BuildContext context, _Satuan s) {
    final rows = (data['rows'] as List?)?.cast<Map<String, dynamic>>();
    final labels = (data['labels'] as List?)?.cast<String>();

    if (kunci == 'budget' && rows != null) return rows.isEmpty ? null : _Budget(rows: rows);
    if (kunci == 'spk' && rows != null) return rows.isEmpty ? null : _Spk(rows: rows);
    if (kunci == 'bast_ajb_br') return _Bast(series: (data['series'] as List? ?? const []).cast<Map<String, dynamic>>());
    if (data['keys'] is List && rows != null) {
      return rows.isEmpty ? null : _Tumpuk(rows: rows, keys: (data['keys'] as List).cast<Map<String, dynamic>>(), satuan: s);
    }
    if (labels != null && data['vals'] is List) {
      final vals = [for (final v in data['vals'] as List) _n(v)];
      return _Bulanan(labels: labels, series: [(n: 'Laba/rugi', c: 0, v: vals)], satuan: s, total: 'Kumulatif');
    }
    if (labels != null && data['series'] is List) {
      final series = [
        for (final x in (data['series'] as List).cast<Map<String, dynamic>>())
          (n: x['n'] as String, c: (x['c'] as num?)?.toInt() ?? 0, v: [for (final v in (x['v'] as List? ?? const [])) _n(v)]),
      ];
      return series.isEmpty ? null : _Bulanan(labels: labels, series: series, satuan: s);
    }
    if (rows != null) return rows.isEmpty ? null : _Batang(rows: rows, satuan: s);
    return null;
  }
}

/// rows {n, v}: batang horizontal, terbesar dulu (maks 8), plus total.
class _Batang extends StatelessWidget {
  const _Batang({required this.rows, required this.satuan});
  final List<Map<String, dynamic>> rows;
  final _Satuan satuan;

  @override
  Widget build(BuildContext context) {
    final tampil = rows.take(8).toList();
    final maks = tampil.map((r) => _n(r['v']).abs()).fold<double>(0, math.max);
    final total = rows.fold<double>(0, (a, r) => a + _n(r['v']));
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      for (final r in tampil)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(r['n'] as String, style: kecil, overflow: TextOverflow.ellipsis)),
              Text(_ringkas(_n(r['v']), satuan), style: kecil?.copyWith(fontWeight: FontWeight.w600)),
            ]),
            const SizedBox(height: 3),
            _Bar(frac: maks == 0 ? 0 : _n(r['v']).abs() / maks, warna: _n(r['v']) < 0 ? Colors.red.shade400 : _warna[0]),
          ]),
        ),
      if (rows.length > tampil.length) Text('+${rows.length - tampil.length} lainnya', style: kecil),
      if (satuan == _Satuan.rp) _Total(label: 'Total', nilai: _ringkas(total, satuan)),
    ]);
  }
}

/// labels + series[{n,c,v[]}]: kolom per bulan, satu batang per seri, plus total per seri.
class _Bulanan extends StatelessWidget {
  const _Bulanan({required this.labels, required this.series, required this.satuan, this.total});
  final List<String> labels;
  final List<({String n, int c, List<double> v})> series;
  final _Satuan satuan;
  final String? total;

  @override
  Widget build(BuildContext context) {
    final maks = series.expand((s) => s.v).map((v) => v.abs()).fold<double>(0, math.max);
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(
        height: 110,
        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          for (var i = 0; i < labels.length; i++)
            Expanded(
              child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                Expanded(
                  child: Row(crossAxisAlignment: CrossAxisAlignment.end, mainAxisAlignment: MainAxisAlignment.center, children: [
                    for (final s in series)
                      Flexible(
                        child: FractionallySizedBox(
                          heightFactor: maks == 0 || i >= s.v.length ? 0.01 : math.max(0.01, s.v[i].abs() / maks),
                          child: Container(
                            margin: const EdgeInsets.symmetric(horizontal: 1),
                            constraints: const BoxConstraints(maxWidth: 12),
                            decoration: BoxDecoration(
                              color: i < s.v.length && s.v[i] < 0 ? Colors.red.shade400 : _w(s.c),
                              borderRadius: const BorderRadius.vertical(top: Radius.circular(2)),
                            ),
                          ),
                        ),
                      ),
                  ]),
                ),
                const SizedBox(height: 4),
                Text(labels[i], style: kecil?.copyWith(fontSize: 10)),
              ]),
            ),
        ]),
      ),
      const SizedBox(height: 8),
      for (final s in series)
        _Total(
          label: total ?? s.n,
          nilai: _ringkas(s.v.fold<double>(0, (a, b) => a + b), satuan),
          warna: series.length > 1 ? _w(s.c) : null,
        ),
    ]);
  }
}

/// rows {n, a, b, ...} + keys [{k, n, c}]: batang bertumpuk per baris + legenda.
class _Tumpuk extends StatelessWidget {
  const _Tumpuk({required this.rows, required this.keys, required this.satuan});
  final List<Map<String, dynamic>> rows;
  final List<Map<String, dynamic>> keys;
  final _Satuan satuan;

  @override
  Widget build(BuildContext context) {
    double jumlah(Map<String, dynamic> r) => keys.fold(0, (a, k) => a + _n(r[k['k']]).abs());
    final maks = rows.map(jumlah).fold<double>(0, math.max);
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Wrap(spacing: 12, runSpacing: 4, children: [
        for (final k in keys)
          Row(mainAxisSize: MainAxisSize.min, children: [
            Container(width: 10, height: 10, color: _w(k['c'])),
            const SizedBox(width: 4),
            Text(k['n'] as String, style: kecil),
          ]),
      ]),
      const SizedBox(height: 10),
      for (final r in rows.take(8))
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(r['n'] as String, style: kecil, overflow: TextOverflow.ellipsis)),
              Text(_ringkas(jumlah(r), satuan), style: kecil?.copyWith(fontWeight: FontWeight.w600)),
            ]),
            const SizedBox(height: 3),
            ClipRRect(
              borderRadius: BorderRadius.circular(3),
              child: SizedBox(
                height: 10,
                child: FractionallySizedBox(
                  alignment: Alignment.centerLeft,
                  widthFactor: maks == 0 ? 0 : jumlah(r) / maks,
                  child: Row(children: [
                    for (final k in keys)
                      if (_n(r[k['k']]) != 0)
                        Expanded(flex: math.max(1, (_n(r[k['k']]).abs() / math.max(jumlah(r), 1) * 1000).round()), child: Container(color: _w(k['c']))),
                  ]),
                ),
              ),
            ),
          ]),
        ),
    ]);
  }
}

class _Budget extends StatelessWidget {
  const _Budget({required this.rows});
  final List<Map<String, dynamic>> rows;

  @override
  Widget build(BuildContext context) {
    final kecil = Theme.of(context).textTheme.bodySmall;
    final real = rows.fold<double>(0, (a, r) => a + _n(r['real']));
    final plafon = rows.fold<double>(0, (a, r) => a + _n(r['plafon']));
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      for (final r in rows.take(8))
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(r['n'] as String, style: kecil, overflow: TextOverflow.ellipsis)),
              Text(
                _n(r['plafon']) == 0 ? '—' : '${(_n(r['real']) / _n(r['plafon']) * 100).toStringAsFixed(0)}%',
                style: kecil?.copyWith(fontWeight: FontWeight.w600),
              ),
            ]),
            const SizedBox(height: 3),
            _Bar(frac: _n(r['plafon']) == 0 ? 0 : math.min(1, _n(r['real']) / _n(r['plafon'])), warna: _n(r['real']) > _n(r['plafon']) ? Colors.red.shade400 : _warna[0], latar: true),
            Text('${_ringkas(_n(r['real']), _Satuan.rp)} dari ${_ringkas(_n(r['plafon']), _Satuan.rp)}', style: kecil?.copyWith(fontSize: 11)),
          ]),
        ),
      _Total(label: 'Total realisasi', nilai: _ringkas(real, _Satuan.rp)),
      _Total(label: 'Sisa plafon', nilai: _ringkas(plafon - real, _Satuan.rp)),
    ]);
  }
}

class _Spk extends StatelessWidget {
  const _Spk({required this.rows});
  final List<Map<String, dynamic>> rows;

  @override
  Widget build(BuildContext context) {
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Column(children: [
      for (final r in rows)
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Row(children: [
            Expanded(child: Text(r['n'] as String, style: kecil, overflow: TextOverflow.ellipsis)),
            Text('${_ribuan(_n(r['jml']))} SPK · ${_ringkas(_n(r['nilai']), _Satuan.rp)}', style: kecil?.copyWith(fontWeight: FontWeight.w600)),
          ]),
        ),
      _Total(label: 'Total SPK', nilai: _ribuan(rows.fold<double>(0, (a, r) => a + _n(r['jml'])))),
    ]);
  }
}

class _Bast extends StatelessWidget {
  const _Bast({required this.series});
  final List<Map<String, dynamic>> series;

  @override
  Widget build(BuildContext context) {
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Row(children: [
      for (final s in series)
        Expanded(
          child: Container(
            margin: const EdgeInsets.symmetric(horizontal: 3),
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(border: Border.all(color: _w(s['c'])), borderRadius: BorderRadius.circular(8)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(s['n'] as String, style: kecil),
              Text(
                '${_ribuan((s['unit'] as List? ?? const []).fold<double>(0, (a, v) => a + _n(v)))} unit',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              Text(_ringkas(_n(s['nilai']), _Satuan.rp), style: kecil?.copyWith(fontSize: 11)),
            ]),
          ),
        ),
    ]);
  }
}

class _Bar extends StatelessWidget {
  const _Bar({required this.frac, required this.warna, this.latar = false});
  final double frac;
  final Color warna;
  final bool latar;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(3),
      child: Container(
        height: 8,
        color: latar ? Theme.of(context).colorScheme.surfaceContainerHighest : null,
        alignment: Alignment.centerLeft,
        child: FractionallySizedBox(widthFactor: frac.clamp(0, 1).toDouble(), child: Container(color: warna)),
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.label, required this.nilai, this.warna});
  final String label;
  final String nilai;
  final Color? warna;

  @override
  Widget build(BuildContext context) {
    final kecil = Theme.of(context).textTheme.bodySmall;
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(children: [
        if (warna != null) ...[Container(width: 8, height: 8, color: warna), const SizedBox(width: 6)],
        Expanded(child: Text(label, style: kecil)),
        Text(nilai, style: const TextStyle(fontWeight: FontWeight.w600)),
      ]),
    );
  }
}
