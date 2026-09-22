// Seed akun Sales Koor yang masuk dari J-ERP (cocok by email saat SSO).
//
// Naufal & Putri = supervisor (role MANAGEMENT → lihat SEMUA pipeline & leads).
// Sisanya = SALES (hanya lihat milik koordinatornya sendiri).
// Tiap akun ditautkan ke Coordinator via coordinatorId (dipakai untuk scoping).
//
// Aman diulang (upsert). Jalankan: node scripts/seed-koor-accounts.mjs
import bcrypt from "bcryptjs";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const PASSWORD = "12345678";

// email → { nama koordinator (cocok di tabel Coordinator), role }
const ACCOUNTS = [
  { email: "adam@saleskoor.com", coord: "Adam", role: "SALES" },
  { email: "ari@saleskoor.com", coord: "Ari", role: "SALES" },
  { email: "putri@saleskoor.com", coord: "Putri", role: "MANAGEMENT" }, // supervisor
  { email: "naufal@saleskoor.com", coord: "Naufal", role: "MANAGEMENT" }, // supervisor
  { email: "adi@saleskoor.com", coord: "Adi", role: "SALES" },
  { email: "sigit@saleskoor.com", coord: "Sigit", role: "SALES" },
  { email: "agung@saleskoor.com", coord: "Agung", role: "SALES" },
];

const passwordHash = await bcrypt.hash(PASSWORD, 10);

for (const a of ACCOUNTS) {
  const coord = await prisma.coordinator.findFirst({
    where: { name: { equals: a.coord, mode: "insensitive" } },
    select: { id: true, name: true },
  });
  if (!coord) {
    console.warn(`  ! Coordinator "${a.coord}" tidak ada — ${a.email} dibuat tanpa tautan koordinator`);
  }
  await prisma.user.upsert({
    where: { email: a.email },
    update: { role: a.role, coordinatorId: coord?.id ?? null, passwordHash, name: a.coord },
    create: { email: a.email, name: a.coord, role: a.role, coordinatorId: coord?.id ?? null, passwordHash },
  });
  const tag = a.role === "MANAGEMENT" ? "SUPERVISOR (lihat semua)" : "sales koor (lihat sendiri)";
  console.log(`  ✓ ${a.email.padEnd(24)} → ${coord?.name ?? "-"} · ${tag}`);
}

console.log(`\n  Selesai. Password semua akun: ${PASSWORD}`);
await prisma.$disconnect();
