import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/session.dart';
import '../config.dart';
import 'push_browser.dart';

/// Profil › Pengaturan › Notifikasi push. Pemicu: giliran saya, ajuan saya diputuskan,
/// pengingat harian — dikirim FO tiap menit (pwa:push).
class NotifikasiPage extends StatefulWidget {
  const NotifikasiPage({super.key, required this.session});
  final Session session;

  @override
  State<NotifikasiPage> createState() => _NotifikasiPageState();
}

class _NotifikasiPageState extends State<NotifikasiPage> {
  final PushBrowser push = PushBrowser.instance;
  ApiClient get api => widget.session.api;

  bool _memuat = true;
  bool _proses = false;
  String? _error;
  InfoPush? _info;
  bool _serverAktif = false;
  String? _publicKey;
  String? _endpoint;

  /// null = belum berlangganan di perangkat ini.
  Map<String, dynamic>? _pilihan;

  @override
  void initState() {
    super.initState();
    _muat();
  }

  Future<void> _muat() async {
    setState(() {
      _memuat = true;
      _error = null;
    });
    try {
      final info = await push.info();
      final cfg = await api.get('/push/config');
      final ep = await push.endpoint();
      Map<String, dynamic>? pilihan;
      if (ep != null) {
        pilihan = (await api.post('/push/status', {'endpoint': ep}))['data'] as Map<String, dynamic>?;
      }
      if (!mounted) return;
      setState(() {
        _info = info;
        // APK Android memakai FCM, PWA memakai Web Push (VAPID).
        _serverAktif = (push.pakaiFcm ? cfg['fcm_aktif'] : cfg['aktif']) == true;
        _publicKey = cfg['public_key'] as String?;
        _endpoint = ep;
        _pilihan = pilihan;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _memuat = false);
    }
  }

  void _info2(String pesan) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(pesan)));

  Future<void> _jalan(Future<void> Function() aksi) async {
    setState(() => _proses = true);
    try {
      await aksi();
    } on ApiException catch (e) {
      _info2(e.message);
    } finally {
      if (mounted) setState(() => _proses = false);
    }
  }

  Future<void> _nyalakan() => _jalan(() async {
        final h = await push.langganan(_publicKey ?? '');
        if (h.sub == null) {
          _info2(h.error == 'denied'
              ? 'Izin notifikasi ditolak. Izinkan di pengaturan browser/HP untuk situs ini.'
              : 'Notifikasi tidak bisa dinyalakan (${h.error}).');
          await _muat();
          return;
        }
        final res = await api.post('/push/subscribe', {...h.sub!, 'perangkat': Session.namaPerangkat()});
        setState(() {
          _endpoint = h.sub!['endpoint'] as String?;
          _pilihan = res['data'] as Map<String, dynamic>?;
        });
        _info2('Notifikasi aktif di perangkat ini.');
      });

  Future<void> _matikan() => _jalan(() async {
        final ep = await push.batal() ?? _endpoint;
        if (ep != null) await api.post('/push/unsubscribe', {'endpoint': ep});
        setState(() {
          _endpoint = null;
          _pilihan = null;
        });
      });

  Future<void> _ubah(Map<String, dynamic> perubahan) => _jalan(() async {
        final res = await api.put('/push/subscription', {'endpoint': _endpoint, ...perubahan});
        setState(() => _pilihan = res['data'] as Map<String, dynamic>?);
      });

  Future<void> _pilihJam() async {
    final jam = (_pilihan?['jam_harian'] as String? ?? '08:00').split(':');
    final t = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: int.parse(jam[0]), minute: int.parse(jam[1])),
      helpText: 'Jam pengingat harian',
    );
    if (t == null) return;
    await _ubah({'jam_harian': '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}'});
  }

  Future<void> _uji() => _jalan(() async {
        await api.post('/push/uji', {'endpoint': _endpoint});
        _info2('Notifikasi uji dikirim — cek panel notifikasi.');
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notifikasi push')),
      body: _memuat
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(onRefresh: _muat, child: ListView(children: _isi(context))),
    );
  }

  List<Widget> _isi(BuildContext context) {
    final tema = Theme.of(context);
    final info = _info;
    Widget catatan(IconData ikon, String teks) => Card(
          margin: const EdgeInsets.all(16),
          child: ListTile(leading: Icon(ikon, color: aksen(context)), title: Text(teks)),
        );

    if (_error != null) return [catatan(Icons.error_outline, _error!)];
    if (!_serverAktif) return [catatan(Icons.cloud_off, 'Notifikasi push belum dikonfigurasi di server J-ERP.')];
    if (info == null || !info.didukung) {
      return [
        catatan(
          Icons.notifications_off_outlined,
          info?.ios == true && info?.terpasang != true
              ? 'Di iPhone/iPad, pasang app dulu: Safari › Share › Add to Home Screen, lalu buka J-ERP dari ikon di layar utama.'
              : 'Browser ini tidak mendukung notifikasi push.',
        ),
      ];
    }

    final aktif = _pilihan != null;
    final p = _pilihan ?? const {};
    return [
      if (info.izin == 'denied')
        catatan(Icons.block, 'Notifikasi diblokir untuk situs ini. Izinkan di pengaturan browser/HP, lalu tarik ke bawah untuk memuat ulang.'),
      SwitchListTile(
        title: const Text('Notifikasi di perangkat ini'),
        subtitle: Text(aktif ? 'Aktif' : 'Mati'),
        value: aktif,
        onChanged: _proses || info.izin == 'denied' ? null : (v) => v ? _nyalakan() : _matikan(),
      ),
      if (aktif) ...[
        const Divider(),
        SwitchListTile(
          title: const Text('Giliran saya'),
          subtitle: const Text('Ada dokumen yang baru masuk ke tahap saya'),
          value: p['giliran'] == true,
          onChanged: _proses ? null : (v) => _ubah({'giliran': v}),
        ),
        SwitchListTile(
          title: const Text('Ajuan saya diputuskan'),
          subtitle: const Text('Dokumen yang saya ajukan disetujui / ditolak'),
          value: p['ajuan'] == true,
          onChanged: _proses ? null : (v) => _ubah({'ajuan': v}),
        ),
        SwitchListTile(
          title: const Text('Pengingat harian'),
          subtitle: const Text('Sekali sehari bila masih ada yang menunggu'),
          value: p['harian'] == true,
          onChanged: _proses ? null : (v) => _ubah({'harian': v}),
        ),
        ListTile(
          enabled: p['harian'] == true && !_proses,
          leading: const Icon(Icons.schedule),
          title: const Text('Jam pengingat'),
          trailing: Text('${p['jam_harian'] ?? '08:00'}', style: tema.textTheme.titleMedium),
          onTap: _pilihJam,
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: OutlinedButton.icon(
            onPressed: _proses ? null : _uji,
            icon: const Icon(Icons.send_outlined),
            label: const Text('Kirim notifikasi uji'),
          ),
        ),
      ],
      Padding(
        padding: const EdgeInsets.all(16),
        child: Text(
          'Pengaturan ini per perangkat. Notifikasi dicek tiap menit dan berhenti otomatis saat Anda keluar '
          'atau perangkat dikeluarkan dari Perangkat & sesi.',
          style: tema.textTheme.bodySmall,
        ),
      ),
    ];
  }
}
