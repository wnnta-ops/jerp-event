import { prisma } from "@/lib/prisma";
import { hasLibreOffice } from "@/lib/docx-to-pdf";
import { TemplatesView, type TemplateItem } from "@/components/templates-view";

export const dynamic = "force-dynamic";

export default async function TemplatesPage() {
  const rows = await prisma.documentTemplate.findMany({
    orderBy: { createdAt: "desc" },
    select: { id: true, name: true, fileName: true, fields: true, createdAt: true, createdBy: { select: { name: true } } },
  });

  const templates: TemplateItem[] = rows.map((r) => ({
    id: r.id,
    name: r.name,
    fileName: r.fileName,
    fields: safeFields(r.fields),
    createdByName: r.createdBy?.name ?? null,
    createdAt: r.createdAt.toISOString(),
  }));

  return <TemplatesView templates={templates} pdfExact={hasLibreOffice()} />;
}

function safeFields(json: string): { key: string; label: string }[] {
  try {
    const a = JSON.parse(json);
    return Array.isArray(a) ? a : [];
  } catch {
    return [];
  }
}
