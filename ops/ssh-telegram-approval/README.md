# Persetujuan & Pemantauan SSH lewat Telegram

Tiga hal, memakai satu bot dan satu berkas konfigurasi:

| Berkas | Kegunaan | Menghalangi login? |
|---|---|---|
| `ssh-approval.sh` | Tombol **Izinkan / Tolak** sebelum login diterima | Ya — inilah gerbangnya |
| `ssh-notify.sh` | Kabar sesi **dibuka** & **ditutup** | Tidak, selalu lolos |
| `ssh-watch-failed.sh` | Ringkasan percobaan login **gagal** | Tidak, hanya memantau |

Setiap login SSH mengirim pesan Telegram berisi tombol **Izinkan / Tolak**. Sesi
baru terbuka setelah tombol Izinkan ditekan.

**Gagal = tolak.** Telegram tak terjangkau, bot mati, atau tidak ada jawaban
sampai batas waktu → login ditolak. Kalau kegagalan justru mengizinkan, penyerang
cukup membuat Telegram tak terjangkau untuk melewati seluruh pemeriksaan ini.

> **JANGAN pasang sebelum memastikan console/VNC dari panel penyedia VPS bisa
> dipakai.** Console tidak melewati sshd, jadi itulah satu-satunya jalan masuk
> bila Telegram bermasalah. Coba dulu sampai berhasil login lewat console.

---

## 1. Siapkan bot Telegram

1. Chat **@BotFather** → `/newbot` → simpan **token**-nya.
2. Kirim satu pesan apa pun ke bot yang baru dibuat (bot tidak bisa memulai chat).
3. Ambil chat id Anda:

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getUpdates" | jq '.result[].message.chat.id'
```

## 2. Pasang di server

```bash
sudo apt update && sudo apt install -y jq curl

sudo cp ops/ssh-telegram-approval/ssh-approval.sh     /usr/local/bin/ssh-approval.sh
sudo cp ops/ssh-telegram-approval/ssh-notify.sh       /usr/local/bin/ssh-notify.sh
sudo cp ops/ssh-telegram-approval/ssh-watch-failed.sh /usr/local/bin/ssh-watch-failed.sh
sudo chmod 700 /usr/local/bin/ssh-approval.sh /usr/local/bin/ssh-notify.sh /usr/local/bin/ssh-watch-failed.sh
sudo mkdir -p /var/lib/ssh-approval
```

Berkas konfigurasi (berisi token — **jangan** masuk git):

```bash
sudo tee /etc/ssh-approval.conf >/dev/null <<'EOF'
BOT_TOKEN="123456:ABC..."
# Boleh BEBERAPA tujuan, dipisah spasi atau koma — chat pribadi, grup, atau
# campuran. Pesan dikirim ke semuanya; jawaban dari SALAH SATU sudah menentukan.
# Semua orang di daftar ini bisa memberi akses SSH ke server.
CHAT_ID="123456789"
TIMEOUT_DETIK=60
# Akun otomatis yang TIDAK perlu persetujuan (rsync backup, pemantauan).
# Tanpa ini, tugas terjadwal ikut menunggu manusia lalu gagal diam-diam.
BYPASS_USERS=""
BYPASS_HOSTS="127.0.0.1 ::1"
# Pemberitahuan (tidak pernah menghalangi login) — set 0 untuk mematikan.
NOTIFY_SESSION=1
NOTIFY_FAILED=1
FAILED_WINDOW_DETIK=120
EOF
sudo chmod 600 /etc/ssh-approval.conf
```

## 3. Uji SEBELUM menyambungkannya ke PAM

```bash
sudo PAM_USER=uji PAM_RHOST=1.2.3.4 PAM_TYPE=account /usr/local/bin/ssh-approval.sh
echo "kode keluar: $?"
```

Pesan harus masuk ke Telegram. Tekan **Izinkan** → `kode keluar: 0`;
**Tolak** atau dibiarkan → `1`. Jangan lanjut sebelum ini benar.

## 4. Sambungkan ke PAM

Tahap **account** dipilih dengan sengaja: tahap itu dilewati semua login sshd,
baik memakai kunci maupun kata sandi. Tahap `auth` tidak dipakai karena login
berbasis kunci melewatinya — pemeriksaan bisa terlewati diam-diam.

```bash
sudo cp /etc/pam.d/sshd /root/pam-sshd.bak     # cadangan untuk pemulihan
echo 'account required pam_exec.so quiet /usr/local/bin/ssh-approval.sh' | sudo tee -a /etc/pam.d/sshd
```

Pemberitahuan sesi dibuka/ditutup — `optional`, BUKAN `required`, dengan sengaja:
gagal mengirim kabar tidak boleh menggagalkan sesi yang sah.

```bash
echo 'session optional pam_exec.so quiet /usr/local/bin/ssh-notify.sh' | sudo tee -a /etc/pam.d/sshd
```

## 4b. Pemantau percobaan gagal

Percobaan gagal tidak pernah sampai ke tahap session PAM, jadi butuh pemantau
journal tersendiri:

```bash
sudo cp ops/ssh-telegram-approval/ssh-watch-failed.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ssh-watch-failed
systemctl status ssh-watch-failed --no-pager | head -5
```

Uji tanpa mengganggu apa pun — coba login dengan kata sandi salah dari perangkat
lain, lalu tunggu sampai jendela pengumpulan (`FAILED_WINDOW_DETIK`) berakhir.

## 5. Uji dari terminal BARU — jangan tutup sesi yang sedang berjalan

```bash
ssh ptmup@<ip-server>
```

Telegram harus berbunyi, dan sesi baru terbuka hanya setelah Anda menekan Izinkan.

## Pemulihan bila terkunci

Lewat console/VNC panel VPS:

```bash
sudo cp /root/pam-sshd.bak /etc/pam.d/sshd
```

SSH kembali normal seketika — `sshd` membaca `/etc/pam.d/sshd` tiap koneksi baru,
jadi tidak perlu restart apa pun.

---

## Yang perlu diketahui

**Sesi yang sedang berjalan tidak terpengaruh.** Pemeriksaan hanya terjadi saat
koneksi baru, jadi memasang atau mencabutnya aman dilakukan sambil bekerja.

**scp, sftp, dan rsync ikut meminta persetujuan** karena semuanya lewat sshd.
Untuk pekerjaan otomatis, masukkan akunnya ke `BYPASS_USERS`.

**Satu permintaan pada satu waktu.** Telegram `getUpdates` bersifat merusak —
update yang sudah diambil hilang dari antrean — sehingga dua login bersamaan bisa
saling mencuri jawaban. Skrip memakai lock agar antre, bukan bentrok.

**Chat id grup didapat lewat `my_chat_member`** saat bot ditambahkan ke grup,
atau dengan menambahkan @RawDataBot sebentar lalu mengeluarkannya. Mengirim pesan
biasa ke grup tidak cukup bila mode privasi bot masih menyala.

**Jangan pasang webhook pada bot yang sama.** Webhook dan `getUpdates` tidak bisa
dipakai bersamaan; salah satunya akan berhenti menerima.

**Percobaan gagal DIRINGKAS, bukan dikirim satu per satu.** Satu serangan brute
force menghasilkan ribuan baris dalam hitungan menit; satu pesan per kejadian akan
menenggelamkan permintaan persetujuan yang justru penting, dan bot bisa kena batas
laju Telegram. Ringkasannya dikelompokkan per IP beserta jumlah percobaannya.

**Pemantau gagal hanya melapor, tidak memblokir.** Untuk benar-benar menutup pintu
bagi penyerang yang mencoba berulang, pasang `fail2ban` — keduanya saling
melengkapi, bukan menggantikan.

**Ini bukan pengganti dasar keamanan lain.** Yang berdampak lebih besar: matikan
login kata sandi (`PasswordAuthentication no`) setelah kunci SSH terbukti jalan,
dan pasang `fail2ban`.

---

## Bila suatu saat mengganti port SSH

Ubuntu 22.10+ menjalankan sshd lewat **socket activation**, sehingga mengubah
`Port` di `sshd_config` TIDAK berpengaruh sama sekali. Yang menentukan adalah
`ssh.socket`:

```bash
sudo mkdir -p /etc/systemd/system/ssh.socket.d
sudo nano /etc/systemd/system/ssh.socket.d/override.conf
```

```
[Socket]
ListenStream=
ListenStream=0.0.0.0:22
ListenStream=[::]:22
ListenStream=0.0.0.0:2392
ListenStream=[::]:2392
```

**Sebutkan alamat IPv4 secara eksplisit.** Menulis `ListenStream=2392` saja hanya
menghasilkan socket IPv6, dan pada server ini socket IPv6 TIDAK menerima koneksi
IPv4 — akibatnya port tampak "mendengarkan" tapi setiap sambungan ke alamat IPv4
ditolak. Kesalahan ini pernah terjadi dan sempat memutus akses SSH dari luar
(port 22 ikut hilang dari IPv4 karena baris `ListenStream=` mengosongkan daftar
bawaan lebih dulu).

Urutan amannya: tambahkan port baru **berdampingan** dengan 22 → `daemon-reload`
+ `restart ssh.socket` → pastikan `status` menampilkan `0.0.0.0` untuk KEDUA port
→ uji dari terminal baru → baru hapus baris port 22.
