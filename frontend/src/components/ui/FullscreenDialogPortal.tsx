'use client';

import { createPortal } from 'react-dom';
import type { ReactNode } from 'react';

/** Escapes the application shell so the backdrop covers sidebar and content. */
export default function FullscreenDialogPortal({ children }: { children: ReactNode }) {
  if (typeof document === 'undefined') return null;

  return createPortal(
    <div className="fixed inset-0 z-[1000] flex h-[100dvh] w-screen items-center justify-center overflow-y-auto bg-[#0d1411]/70 p-4 backdrop-blur-[7px] sm:p-6">
      {children}
    </div>,
    document.body,
  );
}
