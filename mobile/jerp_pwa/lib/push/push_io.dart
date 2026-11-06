import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import 'push_browser.dart';
import 'push_stub.dart';

/// APK Android: notifikasi lewat Firebase Cloud Messaging. Server menyimpan `fcm:TOKEN` sebagai
/// endpoint; notifikasi ditampilkan sistem Android (saat app di latar), ketukannya membuka detail.
/// Di VM (flutter test) tetap stub.
PushBrowser buat() => Platform.isAndroid ? _Fcm() : PushTidakAda();

class _Fcm implements PushBrowser {
  @override
  bool get pakaiFcm => true;

  _Fcm() {
    _siap = _mulai();
  }

  late final Future<void> _siap;

  Future<void> _mulai() async {
    await Firebase.initializeApp();
    final fm = FirebaseMessaging.instance;
    FirebaseMessaging.onMessageOpenedApp.listen(_buka);
    // App sedang di depan: Android tidak memunculkan notifikasinya → tampilkan sebagai banner di app.
    FirebaseMessaging.onMessage.listen((m) {
      final n = m.notification;
      if (n == null) return;
      PushBrowser.pesanMasuk?.call(n.title ?? 'J-ERP Approver', n.body ?? '', _id(m));
    });
    final awal = await fm.getInitialMessage();
    if (awal != null) Future<void>.delayed(const Duration(seconds: 1), () => _buka(awal));
  }

  /// url dari server: "./?buka=ID" (sama dengan PWA).
  void _buka(RemoteMessage m) {
    final id = _id(m);
    if (id != null) PushBrowser.bukaDokumen?.call(id);
  }

  int? _id(RemoteMessage m) => int.tryParse(Uri.tryParse(m.data['url'] ?? '')?.queryParameters['buka'] ?? '');

  @override
  Future<InfoPush> info() async {
    await _siap;
    final s = await FirebaseMessaging.instance.getNotificationSettings();
    final izin = switch (s.authorizationStatus) {
      AuthorizationStatus.authorized || AuthorizationStatus.provisional => 'granted',
      AuthorizationStatus.denied => 'denied',
      _ => 'default',
    };
    return InfoPush(didukung: true, izin: izin, ios: false, terpasang: true);
  }

  @override
  Future<HasilLangganan> langganan(String publicKey) async {
    await _siap;
    final s = await FirebaseMessaging.instance.requestPermission();
    if (s.authorizationStatus == AuthorizationStatus.denied) return (sub: null, error: 'denied');
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) return (sub: null, error: 'token FCM tidak tersedia');
    return (sub: <String, dynamic>{'endpoint': 'fcm:$token'}, error: null);
  }

  @override
  Future<String?> endpoint() async {
    await _siap;
    final s = await FirebaseMessaging.instance.getNotificationSettings();
    if (s.authorizationStatus != AuthorizationStatus.authorized) return null;
    final token = await FirebaseMessaging.instance.getToken();
    return token == null ? null : 'fcm:$token';
  }

  @override
  Future<String?> batal() async {
    await _siap;
    final lama = await endpoint();
    await FirebaseMessaging.instance.deleteToken();
    return lama;
  }
}
