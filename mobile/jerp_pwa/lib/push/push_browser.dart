import 'push_stub.dart'
    if (dart.library.io) 'push_io.dart'
    if (dart.library.js_interop) 'push_web.dart' as impl;

/// Kemampuan perangkat untuk notifikasi push: Web Push di browser (lihat web/push.js), FCM di APK Android.
class InfoPush {
  const InfoPush({required this.didukung, required this.izin, required this.ios, required this.terpasang});

  factory InfoPush.fromJson(Map<String, dynamic> j) => InfoPush(
        didukung: j['didukung'] == true,
        izin: (j['izin'] ?? 'unsupported') as String,
        ios: j['ios'] == true,
        terpasang: j['terpasang'] == true,
      );

  final bool didukung;

  /// default | granted | denied | unsupported
  final String izin;
  final bool ios;

  /// Dibuka sebagai app terpasang (Home Screen) — wajib untuk push di iPhone/iPad.
  final bool terpasang;
}

/// Hasil langganan: `sub` = PushSubscription.toJSON() ({endpoint, keys{p256dh,auth}}) atau `error`.
typedef HasilLangganan = ({Map<String, dynamic>? sub, String? error});

abstract class PushBrowser {
  /// true di APK Android (FCM), false di browser (Web Push / VAPID).
  bool get pakaiFcm => false;

  Future<InfoPush> info();
  Future<HasilLangganan> langganan(String publicKey);
  Future<String?> endpoint();

  /// Berhenti berlangganan di browser; mengembalikan endpoint lama (untuk dihapus di server).
  Future<String?> batal();

  /// Bisa diganti di test.
  static PushBrowser instance = impl.buat();

  /// APK: notifikasi diketuk → buka detail approval ini (dipasang ShellPage; PWA memakai ?buka=ID).
  static void Function(int id)? bukaDokumen;

  /// APK: notifikasi masuk saat app sedang terbuka (Android tidak menampilkannya sendiri) →
  /// tampil sebagai banner di app (dipasang ShellPage). id = dokumen yang bisa dibuka, bila ada.
  static void Function(String judul, String isi, int? id)? pesanMasuk;
}
