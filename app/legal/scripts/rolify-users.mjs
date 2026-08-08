// Collapse the seeded named accounts into ONE role-based account per role
// (role name + role email, no personal names). Reassigns every reference
// (documents, requests, steps, audit, timeline) from extra users to the
// canonical one, then deletes the extras. Sets the shared demo password.
//   Run: node scripts/rolify-users.mjs [password]
import bcrypt from "bcryptjs";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const PASSWORD = process.argv[2] || "Legal#2026";

const ROLE_DEF = {
  LEGAL_PIC:       { name: "Legal PIC",       email: "legal.pic@mup.co.id",   initials: "LP" },
  HEAD_OF_LEGAL:   { name: "Head of Legal",   email: "head.legal@mup.co.id",  initials: "HL" },
  GENERAL_MANAGER: { name: "General Manager", email: "gm@mup.co.id",          initials: "GM" },
  DIRECTOR:        { name: "Direktur",        email: "direktur@mup.co.id",    initials: "DR" },
  REQUESTER:       { name: "Pemohon",         email: "pemohon@mup.co.id",     initials: "PM" },
};

async function reassign(tx, oldId, newId) {
  await tx.document.updateMany({ where: { picId: oldId }, data: { picId: newId } });
  await tx.document.updateMany({ where: { uploadedById: oldId }, data: { uploadedById: newId } });
  await tx.timelineItem.updateMany({ where: { picId: oldId }, data: { picId: newId } });
  await tx.accessRequest.updateMany({ where: { requesterId: oldId }, data: { requesterId: newId } });
  await tx.accessRequest.updateMany({ where: { fulfilledById: oldId }, data: { fulfilledById: newId } });
  await tx.approvalStep.updateMany({ where: { approverId: oldId }, data: { approverId: newId } });
  await tx.auditEntry.updateMany({ where: { actorId: oldId }, data: { actorId: newId } });
}

async function main() {
  const hash = await bcrypt.hash(PASSWORD, 10);
  for (const [role, def] of Object.entries(ROLE_DEF)) {
    const users = await prisma.user.findMany({ where: { role }, orderBy: { name: "asc" } });
    if (!users.length) { console.log(`! no user for role ${role}`); continue; }
    const [canonical, ...extras] = users;
    await prisma.$transaction(async (tx) => {
      for (const ex of extras) { await reassign(tx, ex.id, canonical.id); await tx.user.delete({ where: { id: ex.id } }); }
      await tx.user.update({ where: { id: canonical.id }, data: { name: def.name, email: def.email, initials: def.initials, passwordHash: hash, isActive: true } });
    });
    console.log(`${role.padEnd(16)} → ${def.email} (merged ${extras.length} extra)`);
  }
  console.log(`\nDone. Shared password: "${PASSWORD}". Self-registered users keep their own email + Pemohon role.`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
