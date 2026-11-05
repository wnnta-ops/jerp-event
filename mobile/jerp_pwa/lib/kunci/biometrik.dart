import 'biometrik_stub.dart' if (dart.library.js_interop) 'biometrik_web.dart' as impl;

/// Face ID / sidik jari lewat passkey bawaan perangkat (WebAuthn, lihat web/kunci.js).
/// Hanya pemeriksaan lokal "pemilik HP ada di depan layar" — bukan pengganti login.
abstract class Biometrik {
  /// Perangkat punya autentikator biometrik/PIN layar yang bisa dipakai.
  Future<bool> tersedia();

  /// Buat passkey baru; mengembalikan id kredensial (base64url) atau null bila dibatalkan.
  Future<String?> daftar(String userId, String nama);

  /// true bila pemilik berhasil memverifikasi dengan passkey [credId].
  Future<bool> verifikasi(String credId);

  static Biometrik bawaan() => impl.buat();
}
