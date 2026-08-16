#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# Persetujuan login SSH lewat Telegram.
#
# Dipanggil PAM (pam_exec) pada tahap ACCOUNT — tahap ini dilewati SEMUA login
# sshd, baik memakai kunci maupun kata sandi. Tahap auth TIDAK dipakai karena
# login berbasis kunci melewatinya, sehingga pemeriksaan bisa terlewati diam-diam.
#
# GAGAL = TOLAK. Bila Telegram tak terjangkau, bot mati, atau tak ada yang
# menekan tombol sampai batas waktu, login DITOLAK. Itu pilihan sadar: kalau
# gagal berarti izinkan, penyerang cukup membuat Telegram tak terjangkau untuk
# melewati seluruh pemeriksaan ini.
#
# JALAN DARURAT: console/VNC dari panel penyedia VPS tidak melewati sshd, jadi
# tetap bisa dipakai masuk bila Telegram bermasalah. Pastikan Anda bisa
# memakainya SEBELUM memasang berkas ini.
#
# Keluar 0 = izinkan, selain 0 = tolak.
# ═══════════════════════════════════════════════════════════════════════════
set -uo pipefail

CONF=/etc/ssh-approval.conf
LOCK=/run/ssh-approval.lock
OFFSET_FILE=/var/lib/ssh-approval/offset
LOG_TAG=ssh-approval

log() { logger -t "$LOG_TAG" -- "$*"; }

# Hanya bertindak di tahap account. PAM memanggil skrip ini sekali per tahap;
# tanpa penjaga ini, satu login bisa memicu beberapa pesan Telegram.
[ "${PAM_TYPE:-account}" = "account" ] || exit 0

if [ ! -r "$CONF" ]; then
    log "TOLAK ${PAM_USER:-?}: $CONF tidak terbaca"
    exit 1
fi
# shellcheck source=/dev/null
. "$CONF"

: "${BOT_TOKEN:=}"
: "${CHAT_ID:=}"
: "${TIMEOUT_DETIK:=60}"
: "${BYPASS_USERS:=}"   # dipisah spasi, mis. "deploy backup"
: "${BYPASS_HOSTS:=127.0.0.1 ::1}"

USER_LOGIN="${PAM_USER:-?}"
FROM_HOST="${PAM_RHOST:-lokal}"

# Pengecualian: akun otomatis (rsync backup, pemantauan) dan koneksi dari dalam
# server sendiri. Tanpa ini, tugas terjadwal ikut menunggu persetujuan manusia
# lalu gagal diam-diam saat tidak ada yang menekan tombol.
for u in $BYPASS_USERS; do
    [ "$USER_LOGIN" = "$u" ] && { log "LEWAT $USER_LOGIN dari $FROM_HOST (user dikecualikan)"; exit 0; }
done
for h in $BYPASS_HOSTS; do
    [ "$FROM_HOST" = "$h" ] && { log "LEWAT $USER_LOGIN dari $FROM_HOST (host dikecualikan)"; exit 0; }
done

if [ -z "$BOT_TOKEN" ] || [ -z "$CHAT_ID" ]; then
    log "TOLAK $USER_LOGIN: BOT_TOKEN/CHAT_ID kosong di $CONF"
    exit 1
fi

command -v curl >/dev/null 2>&1 || { log "TOLAK $USER_LOGIN: curl tidak ada"; exit 1; }
command -v jq   >/dev/null 2>&1 || { log "TOLAK $USER_LOGIN: jq tidak ada"; exit 1; }

API="https://api.telegram.org/bot${BOT_TOKEN}"
NONCE="$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')"
WAKTU="$(date '+%d %b %Y %H:%M:%S %Z')"

TEKS="🔐 *Permintaan login SSH*
Pengguna : \`${USER_LOGIN}\`
Dari IP  : \`${FROM_HOST}\`
Waktu    : ${WAKTU}
Server   : \`$(hostname)\`

Izinkan login ini?
_Tanpa jawaban dalam ${TIMEOUT_DETIK} detik, login ditolak._"

KEYBOARD=$(jq -nc --arg ok "ok:$NONCE" --arg no "no:$NONCE" \
  '{inline_keyboard:[[{text:"✅ Izinkan",callback_data:$ok},{text:"⛔ Tolak",callback_data:$no}]]}')

KIRIM=$(curl -sS --max-time 15 -X POST "${API}/sendMessage" \
  --data-urlencode "chat_id=${CHAT_ID}" \
  --data-urlencode "text=${TEKS}" \
  --data-urlencode "parse_mode=Markdown" \
  --data-urlencode "reply_markup=${KEYBOARD}" 2>/dev/null)

if [ "$(printf '%s' "$KIRIM" | jq -r '.ok // false')" != "true" ]; then
    log "TOLAK $USER_LOGIN dari $FROM_HOST: gagal kirim Telegram"
    exit 1
fi
MSG_ID=$(printf '%s' "$KIRIM" | jq -r '.result.message_id')

# Satu penunggu pada satu waktu: getUpdates bersifat merusak (update yang sudah
# diambil hilang dari antrean), jadi dua login bersamaan bisa saling mencuri
# jawaban. Lock membuat mereka antre, bukan bentrok.
mkdir -p "$(dirname "$OFFSET_FILE")" 2>/dev/null
exec 9>"$LOCK"
flock -w "$TIMEOUT_DETIK" 9 || { log "TOLAK $USER_LOGIN: menunggu giliran terlalu lama"; exit 1; }

OFFSET=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)
BATAS=$(( $(date +%s) + TIMEOUT_DETIK ))
HASIL=""

while [ "$(date +%s)" -lt "$BATAS" ]; do
    SISA=$(( BATAS - $(date +%s) ))
    [ "$SISA" -gt 25 ] && SISA=25

    UP=$(curl -sS --max-time $((SISA + 5)) \
        "${API}/getUpdates?offset=${OFFSET}&timeout=${SISA}&allowed_updates=%5B%22callback_query%22%5D" 2>/dev/null)
    [ "$(printf '%s' "$UP" | jq -r '.ok // false')" = "true" ] || continue

    BARU=$(printf '%s' "$UP" | jq -r '[.result[].update_id] | max // empty')
    [ -n "$BARU" ] && { OFFSET=$((BARU + 1)); echo "$OFFSET" > "$OFFSET_FILE"; }

    DATA=$(printf '%s' "$UP" | jq -r --arg n "$NONCE" \
        '.result[].callback_query | select(.data == "ok:\($n)" or .data == "no:\($n)") | .data' | head -1)
    CBID=$(printf '%s' "$UP" | jq -r --arg n "$NONCE" \
        '.result[].callback_query | select(.data == "ok:\($n)" or .data == "no:\($n)") | .id' | head -1)

    if [ -n "$DATA" ]; then
        [ -n "$CBID" ] && curl -sS --max-time 10 -X POST "${API}/answerCallbackQuery" \
            --data-urlencode "callback_query_id=${CBID}" >/dev/null 2>&1
        HASIL="${DATA%%:*}"
        break
    fi
done

flock -u 9

if [ "$HASIL" = "ok" ]; then
    AKHIR="✅ *Login DIIZINKAN*"
    KODE=0
elif [ "$HASIL" = "no" ]; then
    AKHIR="⛔ *Login DITOLAK*"
    KODE=1
else
    AKHIR="⏱ *Login DITOLAK* — tidak ada jawaban dalam ${TIMEOUT_DETIK} detik"
    KODE=1
fi

curl -sS --max-time 10 -X POST "${API}/editMessageText" \
  --data-urlencode "chat_id=${CHAT_ID}" \
  --data-urlencode "message_id=${MSG_ID}" \
  --data-urlencode "parse_mode=Markdown" \
  --data-urlencode "text=${TEKS}

${AKHIR}" >/dev/null 2>&1

log "$([ $KODE -eq 0 ] && echo IZINKAN || echo TOLAK) $USER_LOGIN dari $FROM_HOST"
exit $KODE
