import type { Role } from "@prisma/client";
import "next-auth";

declare module "next-auth" {
  interface Session {
    user: {
      id: string;
      name?: string | null;
      email?: string | null;
      role: Role;
      salesId?: string | null;
    };
  }
  interface User {
    role: Role;
    salesId?: string | null;
  }
}

declare module "next-auth/jwt" {
  interface JWT {
    uid?: string;
    role?: Role;
    salesId?: string | null;
  }
}
