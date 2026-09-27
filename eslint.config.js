// https://docs.expo.dev/guides/using-eslint/
const { defineConfig } = require('eslint/config');
const expoConfig = require("eslint-config-expo/flat");

/**
 * BTN-002 / ARCH-003: transactional CTAs use the shared Button / Card(onPress) /
 * ActionTile / IconTile / Chip. A raw <Pressable> in app/ warns unless the file
 * is on this audited list of true custom controls (segments, rows, links,
 * checkboxes). Brackets/parentheses are escaped (they are glob syntax). Shrink the list as screens migrate; never grow it for a CTA.
 */
const PRESSABLE_ALLOWLIST = [
  "app/\\(auth\\)/role.tsx",
  "app/\\(auth\\)/sign-in.tsx",
  "app/\\(auth\\)/sign-up.tsx",
  "app/\\(customer\\)/\\(tabs\\)/claims.tsx",
  "app/\\(customer\\)/\\(tabs\\)/explore.tsx",
  "app/\\(customer\\)/\\(tabs\\)/index.tsx",
  "app/\\(customer\\)/\\(tabs\\)/profile.tsx",
  "app/account/data-usage.tsx",
  "app/account/language.tsx",
  "app/account/privacy.tsx",
  "app/account/security.tsx",
  "app/agent/sales/new.tsx",
  "app/checkout.tsx",
  "app/claim/\\[id\\]/appeal.tsx",
  "app/claim/\\[id\\]/evidence.tsx",
  "app/claim/\\[id\\]/incident.tsx",
  "app/claim/\\[id\\]/information.tsx",
  "app/claim/emergency.tsx",
  "app/claim/new/review.tsx",
  "app/confirmation.tsx",
  "app/institutions/brokers.tsx",
  "app/institutions/insurer/\\[id\\].tsx",
  "app/institutions/insurers.tsx",
  "app/notifications/index.tsx",
  "app/payments/\\[id\\].tsx",
  "app/payments/\\[id\\]/receipt.tsx",
  "app/policy/\\[id\\]/documents.tsx",
  "app/policy/\\[id\\]/renewal-offers.tsx",
  "app/policy/\\[id\\]/renewal-quote.tsx",
  "app/policy/\\[id\\]/renewal-review.tsx",
  "app/policy/\\[id\\]/service.tsx",
  "app/proposals/index.tsx",
  "app/quote/compare.tsx",
  "app/quote/offers.tsx",
  "app/quote/product.tsx",
  "app/quote/product/\\[id\\].tsx",
  "app/quotes/index.tsx",
  "app/search.tsx",
  "app/support/\\[id\\].tsx",
  "app/support/faq.tsx",
  "app/welcome.tsx",
  "app/workspace/\\[role\\].tsx",
];

module.exports = defineConfig([
  expoConfig,
  {
    files: ["app/**/*.tsx"],
    ignores: PRESSABLE_ALLOWLIST,
    rules: {
      "no-restricted-syntax": [
        "warn",
        {
          selector: "JSXOpeningElement[name.name='Pressable']",
          message: "Use Button, Card(onPress), ActionTile, IconTile or Chip from the design system (BTN-002). Custom controls need accessibilityRole/Label/State, a 48dp target and an entry in PRESSABLE_ALLOWLIST.",
        },
      ],
    },
  },
  {
    ignores: ["dist/*"],
  }
]);
