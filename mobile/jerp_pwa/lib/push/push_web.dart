import 'dart:convert';
import 'dart:js_interop';

import 'push_browser.dart';

@JS('jerpPush.info')
external JSPromise<JSString> _info();

@JS('jerpPush.langganan')
external JSPromise<JSString> _langganan(JSString publicKey);

@JS('jerpPush.endpoint')
external JSPromise<JSString?> _endpoint();

@JS('jerpPush.batal')
external JSPromise<JSString?> _batal();

PushBrowser buat() => _Web();

class _Web implements PushBrowser {
  @override
  Future<InfoPush> info() async {
    try {
      return InfoPush.fromJson(jsonDecode((await _info().toDart).toDart) as Map<String, dynamic>);
    } catch (_) {
      return const InfoPush(didukung: false, izin: 'unsupported', ios: false, terpasang: false);
    }
  }

  @override
  Future<HasilLangganan> langganan(String publicKey) async {
    try {
      final j = jsonDecode((await _langganan(publicKey.toJS).toDart).toDart) as Map<String, dynamic>;
      return j['error'] != null ? (sub: null, error: '${j['error']}') : (sub: j, error: null);
    } catch (e) {
      return (sub: null, error: '$e');
    }
  }

  @override
  Future<String?> endpoint() async {
    try {
      return (await _endpoint().toDart)?.toDart;
    } catch (_) {
      return null;
    }
  }

  @override
  Future<String?> batal() async {
    try {
      return (await _batal().toDart)?.toDart;
    } catch (_) {
      return null;
    }
  }
}
