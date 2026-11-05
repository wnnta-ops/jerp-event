import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

enum PeriodeDashboard { delapanBulan, tahunBerjalan }

/// Pengaturan Tampilan — disimpan di perangkat ini saja (localStorage), tidak ke server.
class Preferensi extends ChangeNotifier {
  static const _kTab = 'pref_tab_awal';
  static const _kTema = 'pref_tema';
  static const _kTeks = 'pref_teks_besar';
  static const _kPeriode = 'pref_periode_dashboard';

  /// 0 = Home, 1 = Approval.
  int tabAwal = 0;
  ThemeMode tema = ThemeMode.system;
  bool teksBesar = false;
  PeriodeDashboard periode = PeriodeDashboard.delapanBulan;

  Future<void> muat() async {
    final p = await SharedPreferences.getInstance();
    tabAwal = p.getInt(_kTab) ?? 0;
    tema = ThemeMode.values.asNameMap()[p.getString(_kTema)] ?? ThemeMode.system;
    teksBesar = p.getBool(_kTeks) ?? false;
    periode = PeriodeDashboard.values.asNameMap()[p.getString(_kPeriode)] ?? PeriodeDashboard.delapanBulan;
    notifyListeners();
  }

  Future<void> setTabAwal(int v) => _simpan(() => tabAwal = v, (p) => p.setInt(_kTab, v));
  Future<void> setTema(ThemeMode v) => _simpan(() => tema = v, (p) => p.setString(_kTema, v.name));
  Future<void> setTeksBesar(bool v) => _simpan(() => teksBesar = v, (p) => p.setBool(_kTeks, v));
  Future<void> setPeriode(PeriodeDashboard v) => _simpan(() => periode = v, (p) => p.setString(_kPeriode, v.name));

  /// Parameter `date_from` untuk /dashboard; null = bawaan BO (8 bulan terakhir).
  String? get dashboardDari {
    if (periode == PeriodeDashboard.delapanBulan) return null;
    return '${DateTime.now().year}-01-01';
  }

  Future<void> _simpan(void Function() ubah, Future<bool> Function(SharedPreferences) tulis) async {
    ubah();
    notifyListeners();
    await tulis(await SharedPreferences.getInstance());
  }
}
