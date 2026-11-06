import 'push_browser.dart';

/// Di luar browser (mis. flutter test di VM) push tidak tersedia.
PushBrowser buat() => PushTidakAda();

class PushTidakAda implements PushBrowser {
  @override
  bool get pakaiFcm => false;

  @override
  Future<InfoPush> info() async => const InfoPush(didukung: false, izin: 'unsupported', ios: false, terpasang: false);

  @override
  Future<HasilLangganan> langganan(String publicKey) async => (sub: null, error: 'unsupported');

  @override
  Future<String?> endpoint() async => null;

  @override
  Future<String?> batal() async => null;
}
