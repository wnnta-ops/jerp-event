#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Pemantau percobaan login SSH yang GAGAL → ringkasan ke Telegram.
#
# Percobaan gagal tidak pernah sampai ke tahap session PAM, jadi tidak terlihat
# oleh ssh-notify.sh. Berkas ini membaca journal sshd secara langsung.
#
# DIRINGKAS, BUKAN PER KEJADIAN. Satu serangan brute force menghasilkan ribuan
# baris gagal dalam hitungan menit; mengirim satu pesan per kejadian akan
# membanjiri Telegram sampai pesan penting (permintaan persetujuan) tenggelam,
# dan bot bisa kena batas laju. Karena itu dikumpulkan lalu dikirim satu
# ringkasan per jendela waktu.
#
# Dijalankan sebagai layanan systemd (lihat ssh-watch-failed.service).
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

CONF=/etc/ssh-approval.conf
[ -r "$CONF" ] || { echo "konfigurasi tidak terbaca: $CONF" >&2; exit 1; }
# shellcheck source=/dev/null
. "$CONF"

: "${BOT_TOKEN:=}"
: "${CHAT_ID:=}"
: "${NOTIFY_FAILED:=1}"        # set 0 di conf untuk mematikan
: "${FAILED_WINDOW_DETIK:=120}" # rentang pengumpulan sebelum dikirim

[ "$NOTIFY_FAILED" = "1" ] || { echo "NOTIFY_FAILED=0 — pemantau berhenti"; exit 0; }
[ -n "$BOT_TOKEN" ] && [ -n "$CHAT_ID" ] || { echo "BOT_TOKEN/CHAT_ID kosong" >&2; exit 1; }

API="https://api.telegram.org/bot${BOT_TOKEN}"
HOSTNAME_SRV="$(hostname)"

kirim() {
    curl -sS --max-time 15 -X POST "${API}/sendMessage" \
        --data-urlencode "chat_id=${CHAT_ID}" \
        --data-urlencode "parse_mode=Markdown" \
        --data-urlencode "text=$1" >/dev/null 2>&1
}

TMP=$(mktemp); trap 'rm -f "$TMP"' EXIT
BATAS=$(( $(date +%s) + FAILED_WINDOW_DETIK ))

flush() {
    local jml
    jml=$(wc -l < "$TMP" 2>/dev/null || echo 0)
    [ "$jml" -gt 0 ] || { BATAS=$(( $(date +%s) + FAILED_WINDOW_DETIK )); return; }

    # Kelompokkan per IP: yang penting "siapa mencoba berapa kali", bukan daftar
    # baris log mentah.
    local rincian
    rincian=$(grep -oE 'from [0-9a-fA-F.:]+' "$TMP" | awk '{print $2}' \
        | sort | uniq -c | sort -rn | head -8 \
        | awk '{printf "  `%s` — %s kali\n", $2, $1}')
    [ -n "$rincian" ] || rincian="  (sumber tidak terbaca)"

    kirim "⚠️ *Percobaan login SSH GAGAL*
Server : \`${HOSTNAME_SRV}\`
Jumlah : *${jml}* percobaan dalam ${FAILED_WINDOW_DETIK} detik terakhir

${rincian}"

    : > "$TMP"
    BATAS=$(( $(date +%s) + FAILED_WINDOW_DETIK ))
}

# -n0: mulai dari sekarang, jangan membanjiri dengan riwayat lama saat layanan
# baru dinyalakan atau di-restart.
journalctl -u ssh -u sshd -n0 -f -o cat 2>/dev/null | \
while IFS= read -r -t 5 baris || true; do
    case "$baris" in
        *"Failed password"*|*"Invalid user"*|*"Connection closed by authenticating user"*|*"maximum authentication attempts exceeded"*)
            printf '%s\n' "$baris" >> "$TMP"
            ;;
    esac
    [ "$(date +%s)" -ge "$BATAS" ] && flush
done
