import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import type { WalletPolicy } from "@/api/client";
import { InstitutionMark } from "@/components/InstitutionMark";
import { TintedIcon } from "@/components/design";
import { ripple, StatusChip } from "@/components/ui";
import { insuredLabel, policyLine, policyTitle, productIcon, productTint, providerName } from "@/components/claims/claimProduct";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export type ChoicePolicy = Partial<WalletPolicy> & { product?: { name?: string; line_code?: string } | null };

export type PolicyChoiceProps = {
  policy: ChoicePolicy;
  logoUrl?: string | null;
  selected?: boolean;
  /** Selectable radio card (step 1) when set; static summary card (steps 2-4) otherwise. */
  onPress?: () => void;
  /** White outlined variant of the static card. */
  plain?: boolean;
};

/** Policy row used by the radio card (design 33) and the static summary card (design 31/32). */
export function PolicyChoiceCard({ policy, logoUrl, selected = false, onPress, plain }: PolicyChoiceProps) {
  const { t, td } = useTranslation();
  const title = policyTitle(policy, t("claimPolicyLabel"));
  const line = policyLine(policy);
  const Icon = productIcon(title, line);
  const tint = productTint(title, line);
  const provider = providerName(policy);
  const asset = insuredLabel(policy);
  const status = (policy.status ?? "ACTIVE").toUpperCase();
  const body = (
    <View style={s.row}>
      <TintedIcon icon={Icon} tint={tint} size={onPress ? 64 : 56} />
      <View style={s.flex}>
        <View style={s.titleRow}>
          <Text style={[s.title, s.flex]} numberOfLines={1}>{title}</Text>
          <StatusChip label={td(`policyStatus_${status}`, status)} tone={status === "ACTIVE" ? "success" : "neutral"} />
        </View>
        {provider ? (
          <View style={s.line}>
            <InstitutionMark logoUrl={logoUrl} initials={provider.slice(0, 2).toUpperCase()} size={22} />
            <Text style={s.body} numberOfLines={1}>{provider}</Text>
          </View>
        ) : null}
        {policy.policy_number ? <Text style={s.body} numberOfLines={1}>{t("claimPolicyNo", { number: policy.policy_number })}</Text> : null}
        {asset ? (
          <View style={s.line}>
            <Icon size={16} color={colors.navy800} />
            <Text style={s.body} numberOfLines={1}>{asset}</Text>
          </View>
        ) : null}
      </View>
      {onPress ? <View style={[s.radio, selected && s.radioOn]}>{selected ? <View style={s.radioInner} /> : null}</View> : null}
    </View>
  );
  if (onPress)
    return (
      <Pressable
        accessibilityRole="radio"
        accessibilityState={{ selected }}
        accessibilityLabel={[title, provider, policy.policy_number, asset].filter(Boolean).join(". ")}
        onPress={onPress}
        android_ripple={ripple()}
        style={({ pressed }) => [s.card, selected && s.cardOn, pressed && s.pressed]}
      >
        {body}
      </Pressable>
    );
  return <View style={[s.card, plain ? null : s.cardOn]}>{body}</View>;
}

const s = StyleSheet.create({
  flex: { flex: 1, gap: 3 },
  card: { backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x3, overflow: "hidden" },
  cardOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  pressed: { opacity: 0.85 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  titleRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  line: { flexDirection: "row", alignItems: "center", gap: 6 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  radio: { width: 24, height: 24, borderRadius: 12, borderWidth: 2, borderColor: colors.neutral300, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  radioOn: { borderColor: colors.blue600 },
  radioInner: { width: 12, height: 12, borderRadius: 6, backgroundColor: colors.blue600 },
});
