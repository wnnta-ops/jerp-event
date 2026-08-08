// Set a login password on the seeded demo accounts so you can sign in as any
// role. Safe to re-run. Prints the credentials.
//   Run: node scripts/set-passwords.mjs [password]
import bcrypt from "bcryptjs";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const PASSWORD = process.argv[2] || "Legal#2026";

async function main() {
  const hash = await bcrypt.hash(PASSWORD, 10);
  const users = await prisma.user.findMany({ orderBy: [{ role: "asc" }, { name: "asc" }] });
  for (const u of users) {
    await prisma.user.update({ where: { id: u.id }, data: { passwordHash: hash } });
  }
  console.log(`Set password for ${users.length} accounts. Shared password: "${PASSWORD}"\n`);
  console.log("Sign in with any of these emails:");
  for (const u of users) console.log(`  ${u.email.padEnd(28)} ${u.role}`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
