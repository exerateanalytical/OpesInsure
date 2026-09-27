import type { ComponentType } from "react";
import type { NativePdfProps } from "./nativePdf.native";

export type { NativePdfProps };

/** Web: no native PDF module; the viewer uses the browser's PDF iframe. */
export function loadNativePdf(): ComponentType<NativePdfProps> | null {
  return null;
}
