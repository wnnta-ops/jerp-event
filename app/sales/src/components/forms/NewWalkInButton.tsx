"use client";

import { useState } from "react";
import type { FormOptions } from "@/lib/options";
import { WalkInForm } from "@/components/forms/WalkInForm";

export function NewWalkInButton({
  options,
  label = "＋ Walk-In",
  ghost = false,
  disabled = false,
}: {
  options: FormOptions;
  label?: string;
  ghost?: boolean;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button className={`btn ${ghost ? "ghost" : ""}`} disabled={disabled} onClick={() => setOpen(true)}>
        {label}
      </button>
      <WalkInForm options={options} open={open} onClose={() => setOpen(false)} />
    </>
  );
}
