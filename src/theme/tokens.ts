/**
 * OpesInsure visual identity v2 ("Ndop & Kente"): deep indigo inspired by
 * Cameroonian ndop cloth, warm ochre/gold and terracotta accents from kente
 * and West-African earth pigments, on a warm sand canvas. Token NAMES are
 * unchanged (navy*, blue*, gold* …) so every screen picks the palette up
 * without edits; the values moved. Contrast (WCAG 2.1, tested in
 * tests/navigation-continuity.test.mjs): every text pair used below is
 * AA (4.5:1) on white and on neutral50.
 */
export const colors = {
  // Indigo "ndop" family (primary surfaces, headings).
  navy950: '#0F1535', navy900: '#18204A', navy800: '#232E63',
  // Action indigo (buttons, links, focus). White on blue600 = 8.7:1.
  blue700: '#2B3787', blue600: '#35419F', blue500: '#4F5CB8', blue100: '#E2E5F6', blue50: '#F0F1FA',
  // Ochre / kente gold. gold600 on white = 5.4:1 (AA text).
  gold600: '#9A5B0B', gold500: '#D08A2A', gold100: '#F5E1BF', gold50: '#FBF3E4',
  // Terracotta (heritage accent: pattern, badges, highlights).
  terracotta700: '#8E3A20', terracotta500: '#B9522B', terracotta100: '#F5DCD0',
  // Warm neutrals (sand canvas instead of cold grey).
  neutral950: '#1C1A26', neutral800: '#2E2B3A', neutral700: '#423E4E', neutral600: '#57526A',
  // Contrast (WCAG): neutral500 on white / on neutral50 is AA text;
  // neutral400 >= 3:1 on white (placeholders, icons, borders).
  neutral500: '#635D72', neutral400: '#7A7488', neutral300: '#CBC3BA', neutral200: '#E6DFD5',
  neutral100: '#F2EDE6', neutral50: '#FAF7F2', white: '#FFFFFF',
  success: '#0B7A55', successText: '#07633F', successSoft: '#E5F4EC',
  warning: '#A8620C', warningText: '#784300', warningSoft: '#FCF1DC',
  danger: '#B83A32', dangerText: '#942B24', dangerSoft: '#FBEAE7',
} as const;

/** Heritage pattern palettes (src/components/HeritagePattern.tsx). */
export const heritage = {
  kente: [colors.gold500, colors.terracotta500, colors.navy800, colors.gold100] as const,
  ndopInk: colors.navy950,
  ndopLine: colors.white,
} as const;

/** Widest a page's content gets (tablets, foldables, landscape); Screen centres it. */
export const CONTENT_MAX_WIDTH = 720;

export const space = { x1: 4, x2: 8, x3: 12, x4: 16, x5: 20, x6: 24, x8: 32, x10: 40, x12: 48, x16: 64 } as const;
export const radius = { control: 10, card: 14, feature: 18, sheet: 20, pill: 999 } as const;
export const type = {
  display: { fontSize: 34, lineHeight: 40, fontFamily: 'Inter_700Bold', letterSpacing: -0.5 },
  pageTitle: { fontSize: 26, lineHeight: 32, fontFamily: 'Inter_700Bold', letterSpacing: -0.3 },
  sectionTitle: { fontSize: 22, lineHeight: 28, fontFamily: 'Inter_700Bold' },
  cardTitle: { fontSize: 18, lineHeight: 24, fontFamily: 'Inter_700Bold' },
  bodyLarge: { fontSize: 17, lineHeight: 26, fontFamily: 'Inter_400Regular' },
  body: { fontSize: 16, lineHeight: 24, fontFamily: 'Inter_400Regular' },
  label: { fontSize: 14, lineHeight: 19, fontFamily: 'Inter_600SemiBold' },
  meta: { fontSize: 13, lineHeight: 18, fontFamily: 'Inter_500Medium' },
  caption: { fontSize: 12, lineHeight: 16, fontFamily: 'Inter_600SemiBold' },
  /** Small uppercase kicker above titles (heritage headers). */
  eyebrow: { fontSize: 11, lineHeight: 14, fontFamily: 'Inter_700Bold', letterSpacing: 1.6 },
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
  // Accent on dark indigo surfaces (wordmark "Insure"): ochre, 7:1 on navy950.
  azure500: colors.gold500,
  gold500: colors.gold500,
  gold300: colors.gold100,
  terracotta500: colors.terracotta500,
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
  primaryButton: [colors.navy900, colors.blue600, colors.blue600] as const,
  goldAccent: [colors.gold600, colors.gold500, colors.gold600] as const,
  navySurface: [colors.navy950, colors.navy900, colors.navy800] as const,
};

export const authSpace = [4, 8, 12, 16, 24, 32, 40, 48, 64, 80, 96] as const;
export const authRadius = { sm: 8, md: 12, lg: 16, button: 16, pill: 999 } as const;
export const authIcon = { strokeWidth: 1.8, small: 16, normal: 20, feature: 28 } as const;

export const authType = {
  h1: { fontSize: 30, lineHeight: 36, fontFamily: "Inter_700Bold", letterSpacing: -0.4 },
  h2: { fontSize: 26, lineHeight: 32, fontFamily: "Inter_700Bold", letterSpacing: -0.3 },
  body: { fontSize: 16, lineHeight: 24, fontFamily: "Inter_400Regular" },
  label: { fontSize: 14, lineHeight: 20, fontFamily: "Inter_600SemiBold" },
  button: { fontSize: 16, lineHeight: 22, fontFamily: "Inter_600SemiBold" },
} as const;
