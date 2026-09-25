export const colors = {
  navy950: '#071A2B', navy900: '#0B263D', navy800: '#123652',
  blue700: '#1458BC', blue600: '#1769E0', blue500: '#3881EA', blue100: '#DCEAFF', blue50: '#EEF5FF',
  gold600: '#B97800', gold500: '#D99100', gold100: '#FCE8B2', gold50: '#FFF8E6',
  neutral950: '#101828', neutral800: '#243447', neutral700: '#344454', neutral600: '#526477',
  // Contrast (WCAG): neutral500 5.0:1 on white / 4.7:1 on neutral50 (AA text);
  // neutral400 4.4:1 on white (placeholders, icons, borders; was 2.9:1).
  neutral500: '#5E7185', neutral400: '#687B8E', neutral300: '#B8C3CD', neutral200: '#DCE3E8',
  neutral100: '#EDF1F4', neutral50: '#F6F8FA', white: '#FFFFFF',
  success: '#07855B', successText: '#056B49', successSoft: '#E7F6F0',
  warning: '#B97800', warningText: '#7A4E00', warningSoft: '#FFF6DD',
  danger: '#C9363E', dangerText: '#9C2930', dangerSoft: '#FDEDEF',
} as const;

export const space = { x1: 4, x2: 8, x3: 12, x4: 16, x5: 20, x6: 24, x8: 32, x10: 40, x12: 48, x16: 64 } as const;
export const radius = { control: 10, card: 14, feature: 18, sheet: 20, pill: 999 } as const;
export const type = {
  display: { fontSize: 36, lineHeight: 39, fontFamily: 'Inter_700Bold' },
  pageTitle: { fontSize: 28, lineHeight: 34, fontFamily: 'Inter_700Bold' },
  sectionTitle: { fontSize: 22, lineHeight: 28, fontFamily: 'Inter_700Bold' },
  cardTitle: { fontSize: 18, lineHeight: 24, fontFamily: 'Inter_700Bold' },
  bodyLarge: { fontSize: 17, lineHeight: 26, fontFamily: 'Inter_400Regular' },
  body: { fontSize: 16, lineHeight: 24, fontFamily: 'Inter_400Regular' },
  label: { fontSize: 14, lineHeight: 19, fontFamily: 'Inter_600SemiBold' },
  meta: { fontSize: 13, lineHeight: 18, fontFamily: 'Inter_500Medium' },
  caption: { fontSize: 12, lineHeight: 16, fontFamily: 'Inter_600SemiBold' },
} as const;

// ---------------------------------------------------------------------------
// Auth + onboarding aliases (formerly src/theme/authTokens.ts).
// Merged into the single spec palette: navy #071A2B, blue #1769E0,
// gold #D99100, Inter. Kept as named aliases so the auth screens read the
// same tokens as the rest of the app instead of a parallel Manrope palette.
// ---------------------------------------------------------------------------
export const authColors = {
  navy950: colors.navy950,
  navy900: colors.navy900,
  navy800: colors.navy800,
  blue500: colors.blue600,
  azure500: colors.blue500,
  gold500: colors.gold500,
  gold300: colors.gold100,
  white: colors.white,
  ice50: colors.blue50,
  ice100: colors.blue100,
  ice200: colors.neutral300,
  // neutral600 on white is 5.9:1 (old #5E7A9D was ~4.3:1, below AA)
  slate500: colors.neutral600,
  textSecondary: colors.neutral700,
  nearBlack: colors.neutral950,
  danger: colors.danger,
  dangerText: colors.dangerText,
} as const;

export const authGradients = {
  primaryButton: [colors.navy800, colors.blue600, colors.blue500] as const,
  goldAccent: [colors.gold600, colors.gold500, colors.gold600] as const,
  navySurface: [colors.navy950, colors.navy900, colors.navy800] as const,
};

export const authSpace = [4, 8, 12, 16, 24, 32, 40, 48, 64, 80, 96] as const;
export const authRadius = { sm: 8, md: 12, lg: 16, button: 16, pill: 999 } as const;
export const authIcon = { strokeWidth: 1.8, small: 16, normal: 20, feature: 28 } as const;

export const authType = {
  h1: { fontSize: 34, lineHeight: 40, fontFamily: "Inter_700Bold" },
  h2: { fontSize: 28, lineHeight: 34, fontFamily: "Inter_700Bold" },
  body: { fontSize: 16, lineHeight: 24, fontFamily: "Inter_400Regular" },
  label: { fontSize: 14, lineHeight: 20, fontFamily: "Inter_600SemiBold" },
  button: { fontSize: 16, lineHeight: 22, fontFamily: "Inter_600SemiBold" },
} as const;
