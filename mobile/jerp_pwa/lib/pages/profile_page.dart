import 'dart:convert';

import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../auth/session.dart';
import '../config.dart';
import '../inbox/widgets.dart';
import '../kunci/kunci_page.dart';
import '../kunci/kunci_store.dart';
import '../push/notifikasi_page.dart';
import '../prefs.dart';
import 'sesi_page.dart';
import 'tampilan_page.dart';

/// Chain aktif BO Approval Inbox yang melewati saya (dari `/inbox/profil`).
class ChainSaya {
  ChainSaya.fromJson(Map<String, dynamic> j)
      : name = (j['name'] ?? j['code'] ?? '') as String,
        type = (j['type'] ?? '') as String,
        urgent = j['kategori'] == 'urgent',
        steps = [
          for (final s in (j['steps'] as List? ?? const []))
            (level: (s['level'] as num).toInt(), label: (s['label'] ?? '') as String, saya: s['saya'] == true),
        ];

  final String name;
  final String type;
  final bool urgent;
  final List<({int level, String label, bool saya})> steps;
}

class ProfilBo {
  ProfilBo.fromJson(Map<String, dynamic> j)
      : email = (j['user']?['email'] ?? '') as String,
        roles = [for (final r in (j['user']?['roles'] as List? ?? const [])) r as String],
        superadmin = j['user']?['superadmin'] == true,
        ttd = j['user']?['ttd'] as String?,
        chains = [for (final c in (j['chains'] as List? ?? const [])) ChainSaya.fromJson(c as Map<String, dynamic>)],
        menunggu = (j['ringkasan']?['menunggu'] ?? 0) as int,
        disetujui = (j['ringkasan']?['disetujui'] ?? 0) as int,
        ditolak = (j['ringkasan']?['ditolak'] ?? 0) as int;

  final String email;
  final List<String> roles;
  final bool superadmin;

  /// TTD sendiri sebagai data URI PNG (null = belum diatur di BO).
  final String? ttd;
  final List<ChainSaya> chains;
  final int menunggu;
  final int disetujui;
  final int ditolak;
}

class ProfilePage extends StatefulWidget {
  const ProfilePage({super.key, required this.session, required this.prefs, required this.kunci, required this.periodeBerubah});
  final Session session;
  final Preferensi prefs;
  final KunciStore kunci;
  final VoidCallback periodeBerubah;

  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage> {
  ProfilBo? _profil;
  String? _error;

  @override
  void initState() {
    super.initState();
    _muat();
  }

  Future<void> _muat() async {
    try {
      final p = ProfilBo.fromJson(await widget.session.api.get('/inbox/profil'));
      if (mounted) {
        setState(() {
          _profil = p;
          _error = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _konfirmasiKeluar() async {
    final ya = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Keluar?'),
        content: const Text('Anda perlu masuk lagi untuk melihat approval.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Keluar')),
        ],
      ),
    );
    if (ya == true) await widget.session.keluar();
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.session.user!;
    final tema = Theme.of(context);
    final p = _profil;
    final redup = tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant);
    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: RefreshIndicator(
        onRefresh: _muat,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(radius: 24, backgroundColor: aksen(context), foregroundColor: Colors.white, child: Text(user.init)),
            title: Text(user.name, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(p == null ? user.role : [p.email, if (p.roles.isNotEmpty) p.roles.join(', ')].join('\n')),
          ),
          const SizedBox(height: 12),
          if (_error != null && p == null)
            PesanError(pesan: _error!, ulang: _muat)
          else if (p == null)
            const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
          else ...[
            Row(children: [
              Expanded(child: _ringkas(context, 'Menunggu saya', p.menunggu, aksen(context))),
              const SizedBox(width: 8),
              Expanded(child: _ringkas(context, 'Disetujui', p.disetujui, Colors.green.shade700)),
              const SizedBox(width: 8),
              Expanded(child: _ringkas(context, 'Ditolak', p.ditolak, tema.colorScheme.error)),
            ]),
            const SizedBox(height: 20),
            Text('Alur approval yang melewati saya', style: tema.textTheme.titleSmall),
            const SizedBox(height: 8),
            if (p.superadmin)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text('SuperAdmin BO: bisa menyetujui tahap mana pun.', style: redup),
              ),
            if (p.chains.isEmpty)
              const PesanKosong(ikon: Icons.account_tree_outlined, judul: 'Belum ada di alur mana pun', isi: 'Atur di BO: System › Approval Chain.')
            else
              for (final c in p.chains) _kartuChain(context, c),
            const SizedBox(height: 20),
            Text('Tanda tangan saya', style: tema.textTheme.titleSmall),
            const SizedBox(height: 8),
            _ttd(context, p),
          ],
          const SizedBox(height: 20),
          Text('Pengaturan', style: tema.textTheme.titleSmall),
          Card(
            child: Column(children: [
              ListTile(
                leading: const Icon(Icons.palette_outlined),
                title: const Text('Tampilan'),
                subtitle: const Text('Tab awal, tema, ukuran teks, periode dashboard'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => TampilanPage(prefs: widget.prefs, periodeBerubah: widget.periodeBerubah),
                )),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.devices),
                title: const Text('Perangkat & sesi'),
                subtitle: const Text('Perangkat yang sedang masuk'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => SesiPage(session: widget.session))),
              ),
              const Divider(height: 1),
              ListenableBuilder(
                listenable: widget.kunci,
                builder: (context, _) => ListTile(
                  leading: const Icon(Icons.fingerprint),
                  title: const Text('Kunci aplikasi'),
                  subtitle: Text(!widget.kunci.aktif
                      ? 'Mati'
                      : widget.kunci.pakaiBiometrik
                          ? 'Aktif · PIN + biometrik'
                          : 'Aktif · PIN'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => KunciPage(kunci: widget.kunci, session: widget.session),
                  )),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.notifications_outlined),
                title: const Text('Notifikasi push'),
                subtitle: const Text('Giliran saya, ajuan diputuskan, pengingat harian'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotifikasiPage(session: widget.session))),
              ),
            ]),
          ),
          Card(
            child: ListTile(
              leading: Icon(Icons.logout, color: tema.colorScheme.error),
              title: Text('Keluar', style: TextStyle(color: tema.colorScheme.error)),
              onTap: _konfirmasiKeluar,
            ),
          ),
          const SizedBox(height: 24),
          Text('API: $apiBaseUrl', textAlign: TextAlign.center, style: tema.textTheme.bodySmall),
        ]),
      ),
    );
  }

  Widget _ttd(BuildContext context, ProfilBo p) {
    final tema = Theme.of(context);
    final ttd = p.ttd;
    if (ttd == null || !ttd.contains(',')) {
      return Card(
        child: ListTile(
          leading: const Icon(Icons.draw_outlined),
          title: const Text('Belum ada tanda tangan'),
          subtitle: Text('Unggah di J-ERP web (BO): Pengaturan › Tanda Tangan.', style: tema.textTheme.bodySmall),
        ),
      );
    }
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            height: 120,
            width: double.infinity,
            // Latar putih: TTD PNG biasanya tinta gelap transparan, tak terbaca di tema gelap.
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
            padding: const EdgeInsets.all(8),
            child: Image.memory(base64Decode(ttd.split(',').last), fit: BoxFit.contain),
          ),
          const SizedBox(height: 6),
          Text('Dipakai di lembar Memo Urgent. Ubah lewat J-ERP web (BO).', style: tema.textTheme.bodySmall),
        ]),
      ),
    );
  }

  Widget _ringkas(BuildContext context, String label, int n, Color warna) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: Theme.of(context).textTheme.bodySmall, maxLines: 1, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 2),
          Text('$n', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600, color: warna)),
        ]),
      ),
    );
  }

  Widget _kartuChain(BuildContext context, ChainSaya c) {
    final tema = Theme.of(context);
    final saya = c.steps.where((s) => s.saya).toList();
    final posisi = saya.map((s) => 'tahap ${s.level} dari ${c.steps.length} (${s.label})').join(', ');
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            LabelKategori(urgent: c.urgent),
            const SizedBox(width: 8),
            Expanded(child: Text(c.type, style: tema.textTheme.titleSmall)),
          ]),
          const SizedBox(height: 2),
          Text('${c.name} · Saya di $posisi', style: tema.textTheme.bodySmall),
          const SizedBox(height: 8),
          Wrap(spacing: 4, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
            for (var i = 0; i < c.steps.length; i++) ...[
              if (i > 0) Icon(Icons.chevron_right, size: 14, color: tema.colorScheme.onSurfaceVariant),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: c.steps[i].saya ? aksen(context) : null,
                  border: Border.all(color: c.steps[i].saya ? aksen(context) : tema.colorScheme.outlineVariant),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  c.steps[i].label,
                  style: TextStyle(fontSize: 12, color: c.steps[i].saya ? Colors.white : null, fontWeight: c.steps[i].saya ? FontWeight.w600 : null),
                ),
              ),
            ],
          ]),
        ]),
      ),
    );
  }
}
