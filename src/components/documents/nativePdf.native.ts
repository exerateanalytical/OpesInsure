import type { ComponentType } from "react";
import { NativeModules, TurboModuleRegistry } from "react-native";

/**
 * Lazy, guarded access to react-native-pdf. APKs built before the native
 * module was added receive this JS over the air: there the module is absent
 * and requiring react-native-pdf (which calls getEnforcing on
 * react-native-blob-util) would throw, so callers fall back to the WebView.
 */
export type NativePdfProps = {
  source: { uri: string; cache?: boolean };
  style?: unknown;
  fitPolicy?: 0 | 1 | 2;
  spacing?: number;
  minScale?: number;
  maxScale?: number;
  enablePaging?: boolean;
  enableAntialiasing?: boolean;
  trustAllCerts?: boolean;
  onLoadComplete?: (numberOfPages: number) => void;
  onPageChanged?: (page: number, numberOfPages: number) => void;
  onError?: (error: unknown) => void;
  renderActivityIndicator?: () => React.ReactElement;
};

let cached: ComponentType<NativePdfProps> | null | undefined;

function blobUtilPresent(): boolean {
  try {
    return !!(TurboModuleRegistry.get("ReactNativeBlobUtil") ?? NativeModules.ReactNativeBlobUtil);
  } catch {
    return false;
  }
}

export function loadNativePdf(): ComponentType<NativePdfProps> | null {
  if (cached !== undefined) return cached;
  cached = null;
  if (!blobUtilPresent()) return cached;
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require("react-native-pdf");
    cached = (mod?.default ?? mod) as ComponentType<NativePdfProps>;
  } catch {
    cached = null;
  }
  return cached;
}
