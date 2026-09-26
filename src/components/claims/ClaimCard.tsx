import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronRight } from "lucide-react-native";
import type { Claim, WalletPolicy } from "@/api/client";
import { ripple, StatusChip } from "@/components/ui";
import { ClaimRail } from "@/components/claims/ClaimRail";
import { claimPolicy, insuredLabel, policyLine, policyTitle, productIcon, productTint } from "@/components/claims/claimProduct";
import { useTranslation } from "@/i18n";
import { claimStatusKey, claimTone, normalizeClaimStatus } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

const TILE: Record<string, { bg: string; fg: string }> = {
  blue: { bg: colors.blue50, fg: colors.navy900 },
  gold: { bg: colors.gold50, fg: colors.gold600 },
  red: { bg: colors.dangerSoft, fg: colors.danger },
  green: { bg: colors.successSoft, fg: colors.successText },
  neutral: { bg: colors.neutral100, fg: colors.navy800 },
};

/** Dates for the rail nodes from the claim's embedded timeline when the API sends one. */
function railDates(claim: Claim): (string | null)[] {
  const events = Array.isArray(claim.timeline) ? claim.timeline : [];
  const at = (match: (type: string) => boolean) => events.find((e) => match((e.type ?? "").toUpperCase()))?.occurred_at ?? null;
  return [
    claim.created_at ?? claim.incident_at ?? null,
    at((x) => /ASSESS|ACKNOWLEDG|REVIEW/.test(x)),
    at((x) => /APPROV|DECLIN|REJECT|DECISION/.test(x)),
    at((x) => /PAID|SETTL|PAYMENT/.test(x)),
  ];
}

/** Claim summary card (design 29): product icon, title, number, date · asset, status chip and the 4-node rail. */
export function ClaimCard({ claim, policy, onPress }: { claim: Claim; policy?: WalletPolicy | null; onPress: () => void }) {
  const { t, td, date } = useTranslation();
  const p = policy ?? claimPolicy(claim);
  const title = policyTitle(p, t("claimGeneric"));
  const line = policyLine(p);
  const Icon = productIcon(title, line);
  const tile = TILE[productTint(title, line)] ?? TILE.blue!;
  const asset = insuredLabel(p);
  const status = td(claimStatusKey(claim.status), claim.status);
  const needsAction = normalizeClaimStatus(claim.status) === "EVIDENCE_PENDING";
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${title}. ${claim.claim_number}. ${status}`}
      onPress={onPress}
      android_ripple={ripple()}
      style={({ pressed }) => [s.card, pressed && s.pressed]}
    >
      <View style={s.top}>
        <View style={[s.tile, { backgroundColor: tile.bg }]}>
          <Icon size={30} color={tile.fg} />
        </View>
        <View style={s.flex}>
          <Text style={s.title} numberOfLines={1}>{title}</Text>
          <Text style={s.number}>{claim.claim_number}</Text>
          <Text style={s.meta} numberOfLines={1}>
            {date(claim.incident_at)}
            {asset ? ` • ${asset}` : claim.incident_location ? ` • ${claim.incident_location}` : ""}
          </Text>
        </View>
        <View style={s.right}>
          <StatusChip label={needsAction ? t("claimActionNeeded") : status} tone={claimTone(claim.status)} />
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
      </View>
      <ClaimRail status={claim.status} dates={railDates(claim)} />
    </Pressable>
  );
}

const s = StyleSheet.create({
  card: { backgroundColor: colors.white, borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  pressed: { opacity: 0.9 },
  top: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  tile: { width: 60, height: 60, borderRadius: radius.card, alignItems: "center", justifyContent: "center" },
  flex: { flex: 1, gap: 2 },
  right: { flexDirection: "row", alignItems: "center", gap: 4 },
  title: { ...type.cardTitle, color: colors.navy950 },
  number: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
