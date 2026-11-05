import 'biometrik.dart';

/// Di luar browser (mis. flutter test di VM) biometrik tidak tersedia.
Biometrik buat() => _TidakAda();

class _TidakAda implements Biometrik {
  @override
  Future<bool> tersedia() async => false;

  @override
  Future<String?> daftar(String userId, String nama) async => null;

  @override
  Future<bool> verifikasi(String credId) async => false;
}
