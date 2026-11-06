import 'dart:io';

import 'package:local_auth/local_auth.dart';

import 'biometrik.dart';
import 'biometrik_stub.dart';

/// APK Android: sidik jari / wajah / kunci layar lewat local_auth. Tidak ada kredensial yang
/// didaftarkan — id-nya tetap, cukup penanda bahwa biometrik dipakai. Di VM (flutter test) stub.
Biometrik buat() => Platform.isAndroid ? _Android() : BiometrikTidakAda();

class _Android implements Biometrik {
  final _auth = LocalAuthentication();

  @override
  Future<bool> tersedia() async {
    try {
      return await _auth.isDeviceSupported() && await _auth.canCheckBiometrics;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<String?> daftar(String userId, String nama) async => await verifikasi('android') ? 'android' : null;

  @override
  Future<bool> verifikasi(String credId) async {
    try {
      return await _auth.authenticate(localizedReason: 'Buka J-ERP Approver');
    } catch (_) {
      return false;
    }
  }
}
