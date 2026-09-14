import { redirect } from "next/navigation";
import { getSession } from "@/lib/auth";
import { Shell } from "@/components/Shell";
import { formatIdDate, wibToday } from "@/lib/wib";

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const session = await getSession();
  if (!session?.user) redirect("/login");

  return (
    <Shell
      user={{ name: session.user.name ?? "User", role: session.user.role }}
      todayLabel={formatIdDate(wibToday())}
    >
      {children}
    </Shell>
  );
}
