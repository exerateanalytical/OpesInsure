// Design tokens for the OpesInsure auth + onboarding brand pack.
// Scoped to the login/sign-up/splash screens only — the rest of the app
// keeps using `@/theme/tokens`, so this never touches other screens.
export const authColors = {
  navy950: "#031C44",
  navy900: "#042C5F",
  navy800: "#0B3875",
  blue500: "#0E6FE2",
  azure500: "#0080FF",
  gold500: "#D3934B",
  gold300: "#F1C071",
  white: "#FDFDFE",
  ice50: "#F4F8FC",
  ice100: "#DBE7F2",
  ice200: "#BCD3E9",
  slate500: "#5E7A9D",
  textSecondary: "#485366",
  nearBlack: "#080A0F",
} as const;

export const authGradients = {
  primaryButton: ["#0B3875", "#0E6FE2", "#0080FF"] as const,
  goldAccent: ["#C9862D", "#F3C66E", "#D89C3B"] as const,
  navySurface: ["#031C44", "#042C5F", "#0759A8"] as const,
};

export const authSpace = [4, 8, 12, 16, 24, 32, 40, 48, 64, 80, 96] as const;

export const authRadius = { sm: 8, md: 12, lg: 16, button: 16, pill: 999 } as const;

export const authIcon = { strokeWidth: 1.8, small: 16, normal: 20, feature: 28 } as const;

export const authType = {
  h1: { fontSize: 36, lineHeight: 42, fontFamily: "Manrope_800ExtraBold" },
  h2: { fontSize: 28, lineHeight: 34, fontFamily: "Manrope_700Bold" },
  body: { fontSize: 16, lineHeight: 24, fontFamily: "Manrope_400Regular" },
  label: { fontSize: 14, lineHeight: 20, fontFamily: "Manrope_600SemiBold" },
  button: { fontSize: 16, lineHeight: 22, fontFamily: "Manrope_700Bold" },
} as const;
