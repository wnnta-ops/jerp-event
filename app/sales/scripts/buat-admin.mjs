// Buat / perbarui akun admin (SYSADMIN) portal Sales.
//
// Aman diulang: memakai upsert, jadi menjalankannya dua kali tidak menggandakan
// akun — hanya menyetel ulang peran dan kata sandinya.
//
//   node scripts/buat-admin.mjs <email> <kata-sandi> [nama]
//
// Kata sandi WAJIB diisi dan tidak punya nilai bawaan. Nilai bawaan berarti
// setiap pemasangan punya sandi admin yang sama dan tertulis di repo.
import bcrypt from "bcryptjs";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();

const [email, sandi, ...sisaNama] = process.argv.slice(2);
const nama = sisaNama.join(" ") || "IT Sysadmin";

function keluarDenganPetunjuk(pesan) {
  console.error("\n  " + pesan + "\n");
  console.error("  Pemakaian: node scripts/buat-admin.mjs <email> <kata-sandi> [nama]");
  console.error("  Contoh   : node scripts/buat-admin.mjs admin@saleshub.com 'RahasiaKuat#2026' 'IT Sysadmin'\n");
  process.exit(1);
}

if (!email || !email.includes("@")) keluarDenganPetunjuk("Email tidak diisi atau tidak valid.");
if (!sandi || sandi.length < 8) keluarDenganPetunjuk("Kata sandi wajib diisi, minimal 8 karakter.");

async function main() {
  const passwordHash = await bcrypt.hash(sandi, 10);

  const sebelum = await prisma.user.findUnique({ where: { email } });

  // salesId sengaja tidak diisi: akun ini kontrol IT, bukan salah satu sales.
  // Kolomnya unik, jadi menautkannya ke satu sales justru mengunci baris itu.
  const user = await prisma.user.upsert({
    where: { email },
    update: { role: "SYSADMIN", passwordHash },
    create: { name: nama, email, role: "SYSADMIN", passwordHash },
  });

  console.log(`\n  ${sebelum ? "Akun DIPERBARUI" : "Akun DIBUAT"}: ${user.email}`);
  console.log(`  Nama  : ${user.name}`);
  console.log(`  Peran : ${user.role}`);
  console.log(`\n  Masuk lewat /sales/login memakai email + kata sandi di atas.\n`);
}

main()
  .catch((e) => { console.error(e); process.exit(1); })
  .finally(() => prisma.$disconnect());
