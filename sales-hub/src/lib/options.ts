import { prisma } from "./prisma";

export interface FormOptions {
  agencies: { id: string; name: string }[];
  sales: { id: string; name: string }[];
  banks: { id: string; name: string }[];
  promos: { id: string; name: string }[];
  coordinators: { id: string; name: string }[];
  clusters: {
    id: string;
    name: string;
    phases: { id: string; name: string }[];
    types: { id: string; name: string }[];
  }[];
}

export async function getFormOptions(): Promise<FormOptions> {
  const [agencies, sales, banks, promos, coordinators, clusters] = await Promise.all([
    prisma.agency.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.sales.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.bank.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.promo.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.coordinator.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.cluster.findMany({
      orderBy: { name: "asc" },
      select: {
        id: true,
        name: true,
        phases: { orderBy: { name: "asc" }, select: { id: true, name: true } },
        types: { orderBy: { name: "asc" }, select: { id: true, name: true } },
      },
    }),
  ]);
  return { agencies, sales, banks, promos, coordinators, clusters };
}
