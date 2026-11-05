import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'biometrik.dart';

/// Kunci aplikasi (opsional per user, bawaan mati): PIN 6 digit wajib, biometrik opsional.
/// Diminta saat app dibuka dan setelah di latar belakang > [batasLatar]. Disimpan di perangkat
/// ini saja (PIN sebagai hash bergaram) dan dihapus saat keluar akun.
class KunciStore extends ChangeNotifier {
  KunciStore({Biometrik? biometrik}) : bio = biometrik ?? Biometrik.bawaan();

  static const batasLatar = Duration(minutes: 5);
  static const maksSalah = 5;
  static const _kAktif = 'kunci_aktif';
  static const _kHash = 'kunci_pin_hash';
  static const _kGaram = 'kunci_pin_garam';
  static const _kBio = 'kunci_bio_cred';

  final Biometrik bio;

  bool aktif = false;
  bool terkunci = false;
  String? credBio;
  int salah = 0;
  DateTime? _keLatar;

  bool get pakaiBiometrik => credBio != null;
  int get sisaPercobaan => maksSalah - salah;

  /// Dipanggil sekali saat app dibuka: bila aktif, app langsung terkunci.
  Future<void> muat() async {
    final p = await SharedPreferences.getInstance();
    aktif = (p.getBool(_kAktif) ?? false) && p.getString(_kHash) != null;
    credBio = p.getString(_kBio);
    terkunci = aktif;
    notifyListeners();
  }

  /// Nyalakan dengan PIN baru (6 digit).
  Future<void> aktifkan(String pin) async {
    assert(RegExp(r'^\d{6}$').hasMatch(pin));
    final garam = base64Url.encode(List.generate(16, (_) => Random.secure().nextInt(256)));
    final p = await SharedPreferences.getInstance();
    await p.setString(_kGaram, garam);
    await p.setString(_kHash, _hash(pin, garam));
    await p.setBool(_kAktif, true);
    aktif = true;
    salah = 0;
    notifyListeners();
  }

  /// Tambah biometrik; false bila perangkat tidak mendukung / dibatalkan.
  Future<bool> pasangBiometrik(String userId, String nama) async {
    final id = await bio.daftar(userId, nama);
    if (id == null) return false;
    final p = await SharedPreferences.getInstance();
    await p.setString(_kBio, id);
    credBio = id;
    notifyListeners();
    return true;
  }

  Future<void> lepasBiometrik() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kBio);
    credBio = null;
    notifyListeners();
  }

  /// Matikan kunci & hapus PIN/biometrik (juga dipanggil saat keluar akun).
  Future<void> reset() async {
    final p = await SharedPreferences.getInstance();
    for (final k in [_kAktif, _kHash, _kGaram, _kBio]) {
      await p.remove(k);
    }
    aktif = false;
    terkunci = false;
    credBio = null;
    salah = 0;
    notifyListeners();
  }

  Future<bool> cocokPin(String pin) async {
    final p = await SharedPreferences.getInstance();
    final garam = p.getString(_kGaram);
    final hash = p.getString(_kHash);
    return garam != null && hash != null && _hash(pin, garam) == hash;
  }

  /// Coba buka dengan PIN. Mengembalikan false bila salah (dan menambah hitungan salah).
  Future<bool> bukaDenganPin(String pin) async {
    if (await cocokPin(pin)) {
      _buka();
      return true;
    }
    salah++;
    notifyListeners();
    return false;
  }

  Future<bool> bukaDenganBiometrik() async {
    final id = credBio;
    if (id == null || !await bio.verifikasi(id)) return false;
    _buka();
    return true;
  }

  /// Catat waktu app ke latar belakang; saat kembali, kunci bila sudah lewat [batasLatar].
  void keLatar([DateTime? kini]) => _keLatar = kini ?? DateTime.now();

  void kembali([DateTime? kini]) {
    final t = _keLatar;
    _keLatar = null;
    if (aktif && !terkunci && t != null && (kini ?? DateTime.now()).difference(t) >= batasLatar) {
      terkunci = true;
      notifyListeners();
    }
  }

  void _buka() {
    terkunci = false;
    salah = 0;
    notifyListeners();
  }

  /// SHA-256 berulang 10.000x dengan garam — cukup memperlambat tebakan PIN dari isi storage.
  static String _hash(String pin, String garam) {
    var d = utf8.encode('$garam:$pin');
    for (var i = 0; i < 10000; i++) {
      d = Uint8List.fromList(sha256.convert(d).bytes);
    }
    return base64Url.encode(d);
  }
}
