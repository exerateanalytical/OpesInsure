/**
 * Commercial Agent portal design system — locked master specification v2.0
 * (visual master: the approved Agent Profile screen). Every agent-portal
 * screen takes its colours, type, spacing and icon sizes from here so the
 * portal stays consistent. Spec: docs/AGENT_UI_SPEC_V2.md.
 */
export const agentColors = {
  navy: "#073656",
  deepNavy: "#063454",
  midBlue: "#0F5A8D",
  actionBlue: "#1664D4",
  lightBlue: "#75A8E6",
  gold: "#D89209",
  softGold: "#FFF5DD",

  page: "#F8F9FC",
  surface: "#FFFFFF",
  surfaceSoft: "#F5F7FA",
  blueTint: "#EEF5FD",

  text: "#111113",
  heading: "#073656",
  secondary: "#64748B",
  muted: "#98A2B3",

  border: "#E9EAEB",
  borderStrong: "#D7DFEA",

  success: "#159455",
  successBg: "#EAF8EF",
  warning: "#C77B00",
  warningBg: "#FFF6DE",
  danger: "#D92D20",
  dangerBg: "#FFF3F2",
  dangerBorder: "#F97066",
  info: "#1664D4",
  infoBg: "#EEF5FD",
} as const;

export const agentType = {
  screenTitle: { fontSize: 28, lineHeight: 34, fontFamily: "Inter_700Bold" },
  heroAmount: { fontSize: 30, lineHeight: 36, fontFamily: "Inter_700Bold" },
  sectionTitle: { fontSize: 18, lineHeight: 24, fontFamily: "Inter_700Bold" },
  cardTitle: { fontSize: 15, lineHeight: 21, fontFamily: "Inter_600SemiBold" },
  body: { fontSize: 14, lineHeight: 20, fontFamily: "Inter_400Regular" },
  secondary: { fontSize: 13, lineHeight: 18, fontFamily: "Inter_400Regular" },
  caption: { fontSize: 12, lineHeight: 16, fontFamily: "Inter_500Medium" },
  button: { fontSize: 15, lineHeight: 20, fontFamily: "Inter_600SemiBold" },
} as const;

export const agentLayout = {
  screenPadding: 20,
  minSafeArea: 16,
  sectionGap: 24,
  subsectionGap: 16,
  rowGap: 8,
  cardPadding: 16,
  cardRadius: 18,
  inputRadius: 14,
  buttonRadius: 14,
  primaryButtonHeight: 52,
  rowMinHeight: 62,
  touchTarget: 48,
  bottomNavHeight: 72,
  grid: 8,
} as const;

export const agentIcon = { stroke: 1.9, color: agentColors.navy, nav: 22, row: 22, action: 20, small: 18 } as const;

/** Locked status vocabulary — use these keys, never ad-hoc wording. */
export const agentStatus = {
  agent: ["Active", "Suspended", "Inactive", "Pending Verification"],
  commission: ["Accrued", "Pending", "Available", "Paid", "Reversed", "Disputed"],
  session: ["Current", "Recognized", "New Device", "Suspicious", "Expired", "Signed Out"],
  verification: ["Verified", "Pending", "Action Required", "Rejected"],
  withdrawal: ["Requested", "Under Review", "Processing", "Paid", "Failed", "Rejected", "Reversed", "Cancelled"],
} as const;
