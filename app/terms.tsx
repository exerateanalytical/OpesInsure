import React from "react";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { colors, type } from "@/theme/tokens";

// Placeholder legal copy. Must be reviewed and replaced by counsel before a
// public production release. Keep TERMS_VERSION in sign-up.tsx in sync.
const sections: [string, string][] = [
  [
    "1. Who we are",
    "OpesInsure is a digital insurance marketplace operated in the Republic of Cameroon. It lets customers compare, buy and manage insurance from licensed insurers, brokers and agents. OpesInsure is not itself an insurer: cover is provided by the insurer named on your policy, under the CIMA Insurance Code and the rules of the Ministry of Finance.",
  ],
  [
    "2. Your account",
    "You sign in with your Cameroon mobile number and a one-time code. Keep your phone secure and never share codes; OpesInsure staff will never ask for them. You must give accurate information, as insurers rely on it to price and pay claims. Misrepresentation can void cover.",
  ],
  [
    "3. Quotes, policies and payments",
    "Quotes are indicative until an insurer accepts the risk and payment is confirmed. Premiums paid by Mobile Money or card are collected for the insurer. Your policy wording, certificate and schedule set out the cover, exclusions and cancellation rights, and prevail over any summary in the app.",
  ],
  [
    "4. Claims",
    "Claims are assessed and decided by the insurer. OpesInsure helps you submit evidence and track progress. Report incidents promptly and do not submit false or altered documents.",
  ],
  [
    "5. Partners",
    "Insurers, brokers and agents join by invitation after licence verification and are bound by separate partner agreements.",
  ],
  [
    "6. Privacy",
    "We process your identity, contact, policy, payment and claim data to provide the service, meet regulatory and anti-fraud obligations, and keep your account secure, in line with Law No. 2024/017 on personal data protection in Cameroon. We share data only with the insurers, brokers, agents and payment providers involved in your policy, and with authorities where the law requires. You can ask to access or correct your data from Account settings or by contacting support. Public certificate verification shows only validity, insurer, product class and cover dates.",
  ],
  [
    "7. Contact",
    "Questions or complaints: support@opesinsure.cm. Unresolved complaints may be referred to the insurer and to the competent insurance regulator.",
  ],
];

export default function Terms() {
  return (
    <Screen>
      <AppHeader title="Terms & Privacy" subtitle="Version 2026-01-01" back />
      <Card>
        <StatusChip label="DRAFT — PENDING LEGAL REVIEW" tone="warning" />
        <Text style={styles.meta}>
          This text is a placeholder and has not yet been reviewed by legal
          counsel. The final terms will be published before general release.
        </Text>
      </Card>
      {sections.map(([title, body]) => (
        <Card key={title}>
          <Text style={styles.title}>{title}</Text>
          <Text style={styles.body}>{body}</Text>
        </Card>
      ))}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
