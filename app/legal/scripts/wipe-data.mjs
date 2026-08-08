// Wipe all DUMMY/sample content (documents, requests, timeline) — keeps user
// accounts (needed for login). Run against whatever DATABASE_URL points to.
//   Run: node scripts/wipe-data.mjs
import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

async function main() {
  const before = await prisma.document.count();
  await prisma.auditEntry.deleteMany();
  await prisma.approvalStep.deleteMany();
  await prisma.accessRequest.deleteMany();
  await prisma.timelineItem.deleteMany();
  await prisma.documentRelation.deleteMany();
  await prisma.documentFile.deleteMany();
  await prisma.document.deleteMany();
  const users = await prisma.user.count();
  console.log(`Wiped ${before} documents + all requests/timeline/files. Kept ${users} user accounts.`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
