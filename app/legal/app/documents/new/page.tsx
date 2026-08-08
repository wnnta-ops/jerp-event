import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { UploadForm } from "@/components/upload-form";

export const dynamic = "force-dynamic";

const DEFAULT_CLUSTERS = ["Cluster Melati", "Cluster Anggrek", "Cluster Bougenville"];

export default async function NewDocumentPage() {
  const [users, acting, clusterRows] = await Promise.all([
    prisma.user.findMany({ orderBy: [{ role: "asc" }, { name: "asc" }] }),
    getActingUser(),
    prisma.document.findMany({ where: { cluster: { not: null } }, select: { cluster: true }, distinct: ["cluster"] }),
  ]);

  const clusters = Array.from(
    new Set([...DEFAULT_CLUSTERS, ...clusterRows.map((c) => c.cluster!).filter(Boolean)]),
  ).sort();

  return (
    <div className="mx-auto max-w-[820px]">
      <div className="mb-4 flex items-center gap-2 text-xs" style={{ color: "var(--color-text-tertiary)" }}>
        <Link href="/" className="inline-flex items-center gap-1" style={{ color: "var(--color-text-info)" }}>
          <i className="ti ti-arrow-left" />
          Direktori
        </Link>
        <span>/</span>
        <span>Unggah dokumen</span>
      </div>

      <UploadForm
        users={users.map((u) => ({ id: u.id, name: u.name, role: u.role, department: u.department }))}
        defaultPicId={acting?.role === "LEGAL_PIC" || acting?.role === "HEAD_OF_LEGAL" ? acting.id : undefined}
        clusters={clusters}
      />
    </div>
  );
}
