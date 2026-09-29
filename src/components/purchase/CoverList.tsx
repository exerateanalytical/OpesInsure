import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Check, ShieldOff } from "lucide-react-native";
import type { CoverageItem } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * Covers with their own limit and deductible, then the key exclusions: the one
 * read-only cover list of the contract summary (terms, application hub,
 * checkout) and the policy detail. Amounts come from the server's coverage
 * snapshot (normalizeCoverage); a missing amount is left out, never invented.
 */
export function CoverList({ covers, exclusions = [] }: { covers: CoverageItem[]; exclusions?: { code: string; name: string }[] }) {
  const f = useFormatters();
  const { t } = useTranslation();
  if (!covers.length && !exclusions.length) return null;
  return (
    <View style={s.wrap}>
      {covers.map((c) => {
        const detail = [c.limitMinor !== null ? `${t("cdLimit")} ${f.xaf(c.limitMinor)}` : null, c.deductibleMinor !== null ? `${t("cdDeductible")} ${f.xaf(c.deductibleMinor)}` : null].filter(Boolean).join(" · ");
        return (
          <View key={c.code} style={s.row} accessible accessibilityLabel={detail ? `${c.name}. ${detail}` : c.name}>
            <View style={s.dot}>
              <Check size={12} color={colors.white} strokeWidth={3} />
            </View>
            <View style={s.flex}>
              <Text style={s.name}>{c.name}</Text>
              {detail ? <Text style={s.detail}>{detail}</Text> : null}
            </View>
          </View>
        );
      })}
      {exclusions.length ? (
        <View style={[s.exclusions, covers.length > 0 && s.divider]}>
          <View style={s.row}>
            <ShieldOff size={20} color={colors.dangerText} />
            <Text accessibilityRole="header" style={[s.name, s.flex]}>{t("sumKeyExclusions")}</Text>
          </View>
          {exclusions.map((e) => (
            <Text key={e.code} style={s.exclusion}>• {e.name}</Text>
          ))}
        </View>
      ) : null}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x3 },
  flex: { flex: 1 },
  row: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  dot: { width: 20, height: 20, borderRadius: 10, marginTop: 1, alignItems: "center", justifyContent: "center", backgroundColor: colors.gold600 },
  name: { ...type.label, color: colors.navy950 },
  detail: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  exclusions: { gap: space.x1 },
  divider: { borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x3 },
  exclusion: { ...type.meta, color: colors.neutral600, paddingLeft: space.x8 },
});
