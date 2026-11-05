import 'push_stub.dart' if (dart.library.js_interop) 'push_web.dart' as impl;

/// Kemampuan browser untuk notifikasi push (lihat web/push.js).
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
  Future<InfoPush> info();
  Future<HasilLangganan> langganan(String publicKey);
  Future<String?> endpoint();

  /// Berhenti berlangganan di browser; mengembalikan endpoint lama (untuk dihapus di server).
  Future<String?> batal();

  /// Bisa diganti di test.
  static PushBrowser instance = impl.buat();
}
