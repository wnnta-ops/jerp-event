"use server";

import { redirect } from "next/navigation";
import { login, register, logout } from "@/lib/auth";

export type AuthState = { error?: string };

export async function loginAction(_prev: AuthState, formData: FormData): Promise<AuthState> {
  const res = await login(String(formData.get("email") ?? ""), String(formData.get("password") ?? ""));
  if (!res.ok) return { error: res.error };
  redirect("/");
}

export async function registerAction(_prev: AuthState, formData: FormData): Promise<AuthState> {
  const res = await register(
    String(formData.get("name") ?? ""),
    String(formData.get("email") ?? ""),
    String(formData.get("password") ?? ""),
    String(formData.get("department") ?? ""),
  );
  if (!res.ok) return { error: res.error };
  redirect("/");
}

export async function logoutAction(): Promise<void> {
  await logout();
  redirect("/login");
}
