"use client";

import { useEffect, useState } from "react";

// Imperative toast — call toast("...") from any client component.
export function toast(message: string) {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new CustomEvent("app:toast", { detail: message }));
}

export function ToastHost() {
  const [msg, setMsg] = useState("");
  const [show, setShow] = useState(false);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout>;
    const onToast = (e: Event) => {
      setMsg((e as CustomEvent<string>).detail);
      setShow(true);
      clearTimeout(timer);
      timer = setTimeout(() => setShow(false), 2600);
    };
    window.addEventListener("app:toast", onToast);
    return () => {
      window.removeEventListener("app:toast", onToast);
      clearTimeout(timer);
    };
  }, []);

  return (
    <div id="toast" className={show ? "show" : ""}>
      {msg}
    </div>
  );
}
