"use client";

import { SessionProvider } from "next-auth/react";
import type { ReactNode } from "react";

export function Providers({ children }: { children: ReactNode }) {
  // basePath WAJIB: app di-serve di /sales — tanpa ini signIn()/useSession()
  // menembak /api/auth di ROOT domain (jatuh ke Laravel → 404).
  return <SessionProvider basePath="/sales/api/auth">{children}</SessionProvider>;
}
