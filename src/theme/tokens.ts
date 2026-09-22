export const colors = {
  navy950: '#071A2B', navy900: '#0B263D', navy800: '#123652',
  blue700: '#1458BC', blue600: '#1769E0', blue500: '#3881EA', blue100: '#DCEAFF', blue50: '#EEF5FF',
  gold600: '#B97800', gold500: '#D99100', gold100: '#FCE8B2', gold50: '#FFF8E6',
  neutral950: '#101828', neutral800: '#243447', neutral700: '#344454', neutral600: '#526477',
  neutral500: '#687B8E', neutral400: '#8A98A6', neutral300: '#B8C3CD', neutral200: '#DCE3E8',
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
