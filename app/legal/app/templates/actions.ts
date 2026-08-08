"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { parseFields, templateErrorMessage } from "@/lib/templates";

export type TemplateResult = { ok: true; id: string } | { ok: false; error: string };

const MAX = 15 * 1024 * 1024;

export async function createTemplate(formData: FormData): Promise<TemplateResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const file = formData.get("file");
  const name = String(formData.get("name") ?? "").trim();
  if (!(file instanceof File) || file.size === 0) return { ok: false, error: "Lampirkan file Word (.docx)." };
  if (!file.name.toLowerCase().endsWith(".docx")) return { ok: false, error: "Format harus .docx (Word)." };
  if (file.size > MAX) return { ok: false, error: "Ukuran file melebihi 15 MB." };

  const buf = Buffer.from(await file.arrayBuffer());
  let fields;
  try {
    fields = parseFields(buf);
  } catch (e) {
    return { ok: false, error: templateErrorMessage(e) };
  }
  if (fields.length === 0) {
    return { ok: false, error: "Tidak ada placeholder ditemukan. Tandai bagian yang berubah dengan {{nama_field}}." };
  }

  const tpl = await prisma.documentTemplate.create({
    data: {
      name: name || file.name.replace(/\.docx$/i, ""),
      fileName: file.name,
      data: buf,
      fields: JSON.stringify(fields),
      createdById: acting.id,
    },
  });
  revalidatePath("/templates");
  return { ok: true, id: tpl.id };
}

export async function deleteTemplate(id: string): Promise<{ ok: boolean; error?: string }> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  await prisma.documentTemplate.delete({ where: { id } });
  revalidatePath("/templates");
  return { ok: true };
}
