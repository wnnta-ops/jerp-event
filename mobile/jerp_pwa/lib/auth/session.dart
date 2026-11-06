import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';

/// Profil approver dari `/auth/login` & `/auth/me`.
class Approver {
  const Approver({required this.id, required this.name, required this.role, required this.init});

  factory Approver.fromJson(Map<String, dynamic> j) => Approver(
        id: j['id'] as int,
        name: (j['name'] ?? '') as String,
        role: (j['role'] ?? 'Approver') as String,
        init: (j['init'] ?? '?') as String,
      );

  final int id;
  final String name;
  final String role;
  final String init;
}

enum StatusSesi { memuat, keluar, masuk }

/// Menyimpan token di storage browser (localStorage) supaya tidak perlu login
/// ulang tiap buka PWA. Token Sanctum hanya ber-ability `approval:act`.
class Session extends ChangeNotifier {
  Session(this.api);

  static const _kunciToken = 'jerp_token';

  final ApiClient api;
  StatusSesi status = StatusSesi.memuat;
  Approver? user;

  /// Dipanggil sekali saat app dibuka: pakai token tersimpan kalau masih valid.
  Future<void> pulihkan() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_kunciToken);
    if (token == null) {
      _set(StatusSesi.keluar);
      return;
    }
    api.token = token;
    try {
      user = Approver.fromJson(await api.get('/auth/me'));
      _set(StatusSesi.masuk);
    } on ApiException catch (e) {
      // Offline / server mati: jangan buang token, cukup minta login lagi kalau 401.
      if (e.unauthorized) await _hapusToken();
      _set(StatusSesi.keluar);
    }
  }

  Future<void> masuk(String email, String password) async {
    final res = await api.post('/auth/login', {
      'email': email.trim(),
      'password': password,
      'device': namaPerangkat(),
    });
    api.token = res['token'] as String;
    user = Approver.fromJson(res['user'] as Map<String, dynamic>);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kunciToken, api.token!);
    _set(StatusSesi.masuk);
  }

  Future<void> keluar() async {
    try {
      await api.post('/auth/logout');
    } on ApiException {
      // Token mungkin sudah tidak berlaku; tetap hapus di sisi app.
    }
    await _hapusToken();
    _set(StatusSesi.keluar);
  }

  /// Cabut semua token user (semua perangkat), lalu keluar di sini.
  Future<void> keluarSemua() async {
    await api.post('/auth/logout-all');
    await _hapusToken();
    _set(StatusSesi.keluar);
  }

  /// Nama token = perangkat, tampil di Profil › Perangkat & sesi.
  static String namaPerangkat() {
    final os = switch (defaultTargetPlatform) {
      TargetPlatform.android => 'Android',
      TargetPlatform.iOS => 'iPhone/iPad',
      TargetPlatform.macOS => 'Mac',
      TargetPlatform.windows => 'Windows',
      TargetPlatform.linux => 'Linux',
      TargetPlatform.fuchsia => 'Fuchsia',
    };
    return '${kIsWeb ? 'PWA' : 'Aplikasi'} · $os';
  }

  Future<void> _hapusToken() async {
    api.token = null;
    user = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kunciToken);
  }

  void _set(StatusSesi s) {
    status = s;
    notifyListeners();
  }
}
