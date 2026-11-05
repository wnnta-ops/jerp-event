import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../config.dart';

/// Error dari API dengan pesan yang siap ditampilkan ke user.
class ApiException implements Exception {
  ApiException(this.message, {this.status});
  final String message;
  final int? status;

  bool get unauthorized => status == 401;

  @override
  String toString() => message;
}

/// Isi file mentah dari API (dokumen approval).
class Unduhan {
  const Unduhan({required this.isi, required this.tipe, this.nama});
  final Uint8List isi;
  final String tipe;
  final String? nama;
}

/// Klien HTTP tipis ke `/api/mobile` FO (Sanctum Bearer token).
class ApiClient {
  ApiClient({http.Client? client}) : _http = client ?? http.Client();

  final http.Client _http;
  String? token;

  Future<Map<String, dynamic>> get(String path) =>
      _kirim(() => _http.get(_uri(path), headers: _headers()));

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) =>
      _kirim(() => _http.post(_uri(path), headers: _headers(), body: jsonEncode(body ?? {})));

  Future<Map<String, dynamic>> put(String path, [Map<String, dynamic>? body]) =>
      _kirim(() => _http.put(_uri(path), headers: _headers(), body: jsonEncode(body ?? {})));

  Future<Map<String, dynamic>> delete(String path) =>
      _kirim(() => _http.delete(_uri(path), headers: _headers()));

  /// Ambil file mentah (PDF/foto/HTML). Error tetap jadi ApiException berpesan.
  Future<Unduhan> getBytes(String path) async {
    final http.Response res;
    try {
      res = await _http.get(_uri(path), headers: {..._headers(), 'Accept': '*/*'});
    } catch (_) {
      throw ApiException('Tidak bisa terhubung ke server J-ERP. Cek koneksi internet.');
    }
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw ApiException(_pesanError(res.statusCode, _decode(res.body)), status: res.statusCode);
    }
    final cd = res.headers['content-disposition'] ?? '';
    return Unduhan(
      isi: res.bodyBytes,
      tipe: res.headers['content-type'] ?? 'application/octet-stream',
      nama: RegExp(r'filename="([^"]+)"').firstMatch(cd)?.group(1),
    );
  }

  Uri _uri(String path) => Uri.parse('$apiBaseUrl$path');

  Map<String, String> _headers() => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> _kirim(Future<http.Response> Function() req) async {
    final http.Response res;
    try {
      res = await req();
    } catch (_) {
      throw ApiException('Tidak bisa terhubung ke server J-ERP. Cek koneksi internet.');
    }

    final dynamic data = res.body.isEmpty ? <String, dynamic>{} : _decode(res.body);
    if (res.statusCode >= 200 && res.statusCode < 300) {
      return data is Map<String, dynamic> ? data : {'data': data};
    }
    throw ApiException(_pesanError(res.statusCode, data), status: res.statusCode);
  }

  dynamic _decode(String body) {
    try {
      return jsonDecode(body);
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  /// Ambil pesan pertama dari format error Laravel (`errors.field[0]` / `message`).
  String _pesanError(int status, dynamic data) {
    if (status == 429) return 'Terlalu banyak percobaan. Coba lagi sebentar lagi.';
    if (status == 401) return 'Sesi habis. Silakan masuk lagi.';
    if (data is Map) {
      final errors = data['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first.toString();
      }
      if (data['message'] is String && (data['message'] as String).isNotEmpty) {
        return data['message'] as String;
      }
    }
    return 'Terjadi kesalahan di server ($status).';
  }
}
