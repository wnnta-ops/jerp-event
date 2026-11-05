import 'dart:js_interop';

import 'biometrik.dart';

@JS('jerpKunci.tersedia')
external JSPromise<JSBoolean> _tersedia();

@JS('jerpKunci.daftar')
external JSPromise<JSString?> _daftar(JSString userId, JSString nama);

@JS('jerpKunci.verifikasi')
external JSPromise<JSBoolean> _verifikasi(JSString credId);

Biometrik buat() => _Web();

class _Web implements Biometrik {
  @override
  Future<bool> tersedia() async {
    try {
      return (await _tersedia().toDart).toDart;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<String?> daftar(String userId, String nama) async {
    try {
      return (await _daftar(userId.toJS, nama.toJS).toDart)?.toDart;
    } catch (_) {
      return null;
    }
  }

  @override
  Future<bool> verifikasi(String credId) async {
    try {
      return (await _verifikasi(credId.toJS).toDart).toDart;
    } catch (_) {
      return false;
    }
  }
}
