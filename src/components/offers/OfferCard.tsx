import React, { useEffect, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CheckSquare, ChevronDown, ChevronUp, Clock3, ExternalLink, FileText, Square } from "lucide-react-native";
import { Button, Card, StatusChip } from "@/components/ui";
import { InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { QuoteOffer } from "@/api/client";
import { carrierClaimsDays, carrierRating, localized, normalizeCoverage, providerName, validityLeft } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Re-renders every 30s so the validity countdown stays honest. */
export function useNow(intervalMs = 30000) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), intervalMs);
    return () => clearInterval(t);
  }, [intervalMs]);
  return now;
}

export function OfferCard({
  offer,
  badge,
  compareSelected,
  onToggleCompare,
  onSelect,
  selecting,
  disabled,
  width,
  now,
}: {
  offer: QuoteOffer;
  badge?: { label: string; tone: "success" | "info" | "warning" };
  compareSelected?: boolean;
  onToggleCompare?: () => void;
  onSelect: () => void;
  selecting?: boolean;
  disabled?: boolean;
  width?: number;
  now: number;
}) {
  const f = useFormatters();
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const cover = normalizeCoverage(offer.coverage_snapshot, f.language);
  const validity = validityLeft(offer.valid_until, now, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  const optional = cover.coverages.filter((c) => c.optional);
  const carrierId = offer.carrier?.id ?? offer.carrier_id;
  return (
    <Card feature style={width ? { width } : undefined}>
      <View style={ps.between}>
        {badge ? <StatusChip label={badge.label} tone={badge.tone} /> : <View />}
        {onToggleCompare ? (
          <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: !!compareSelected }} accessibilityLabel={t("offerAddCompare")} hitSlop={8} onPress={onToggleCompare} style={st.compare}>
            {compareSelected ? <CheckSquare size={20} color={colors.blue600} /> : <Square size={20} color={colors.neutral500} />}
            <Text style={st.compareText}>{t("offerCompare")}</Text>
          </Pressable>
        ) : null}
      </View>
      <Pressable accessibilityRole="link" onPress={() => carrierId && router.push({ pathname: "/institutions/insurer/[id]", params: { id: carrierId } })}>
        <Text style={st.provider}>{providerName(offer, f.language)} ›</Text>
      </Pressable>
      {carrierRating(offer) || carrierClaimsDays(offer) !== null ? (
        <Text style={ps.meta}>
          {[
            carrierRating(offer) ? t("offerRating", { rating: carrierRating(offer) ?? "" }) : null,
            carrierClaimsDays(offer) !== null ? t("offerClaimsDays", { days: carrierClaimsDays(offer) ?? 0 }) : null,
          ]
            .filter(Boolean)
            .join(" · ")}
        </Text>
      ) : null}
      <Text style={ps.title}>{localized(offer.product?.name, f.language) || t("insuranceOffer")}</Text>
      <Text style={st.total}>{f.xaf(offer.total_minor)}</Text>
      <View style={ps.row}>
        <Clock3 size={14} color={validity.expired ? colors.dangerText : colors.neutral600} />
        <Text style={[ps.meta, validity.expired && ps.error]}>
          {t("offerUntil", { validity: validity.label, date: f.dateTime(offer.valid_until) })}
        </Text>
      </View>
      <Rule />
      <InfoRow label={t("sumPremium")} value={f.xaf(offer.premium_minor)} />
      <InfoRow label={t("sumTaxes")} value={f.xaf(offer.tax_minor)} />
      <InfoRow label={t("sumFees")} value={f.xaf(offer.fee_minor)} />
      <InfoRow label={t("sumTotalPayable")} value={f.xaf(offer.total_minor)} strong />
      <InfoRow label={t("sumExcess")} value={cover.excessMinor === null ? t("sumNotStated") : f.xaf(cover.excessMinor)} />
      <Rule />
      <Text style={st.section}>{t("offerIncludedCover", { count: included.length })}</Text>
      {(expanded ? included : included.slice(0, 3)).map((c) => (
        <View key={c.code} style={st.coverRow}>
          <Text style={st.coverName}>
            {c.name}
            {c.mandatory ? <Text style={ps.meta}>{t("offerMandatory")}</Text> : null}
          </Text>
          <Text style={ps.meta}>
            {c.limitMinor !== null ? t("offerLimit", { amount: f.xaf(c.limitMinor) }) : t("offerLimitPerPolicy")}
            {c.deductibleMinor ? t("offerExcess", { amount: f.xaf(c.deductibleMinor) }) : ""}
          </Text>
        </View>
      ))}
      {!included.length ? <Text style={ps.meta}>{t("offerNoCover")}</Text> : null}
      {expanded ? (
        <>
          {optional.length ? (
            <>
              <Text style={st.section}>{t("offerRiders")}</Text>
              {optional.map((c) => (
                <View key={c.code} style={st.coverRow}>
                  <Text style={st.coverName}>{c.name}</Text>
                  <Text style={ps.meta}>
                    {c.limitMinor !== null ? t("offerLimit", { amount: f.xaf(c.limitMinor) }) : t("offerAvailable")}
                    {c.premiumMinor ? ` · +${f.xaf(c.premiumMinor)}` : ""}
                    {t("offerAskInsurer")}
                  </Text>
                </View>
              ))}
            </>
          ) : null}
          <Text style={st.section}>{t("sumKeyExclusions")}</Text>
          {cover.exclusions.length ? cover.exclusions.map((e) => <Text key={e.code} style={ps.meta}>• {e.name}</Text>) : <Text style={ps.meta}>{t("offerNoExclusions")}</Text>}
          {cover.documents.map((d) => (
            <Pressable key={d.url} accessibilityRole="link" style={ps.row} onPress={() => void Linking.openURL(d.url)}>
              <FileText size={16} color={colors.blue600} />
              <Text style={ps.link}>{d.label}</Text>
              <ExternalLink size={14} color={colors.blue600} />
            </Pressable>
          ))}
        </>
      ) : null}
      <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded(!expanded)} style={[ps.row, st.toggle]}>
        {expanded ? <ChevronUp size={16} color={colors.blue600} /> : <ChevronDown size={16} color={colors.blue600} />}
        <Text style={ps.link}>{expanded ? t("offerLessDetail") : t("offerMoreDetail")}</Text>
      </Pressable>
      <Button label={validity.expired ? t("offerExpired") : t("offerSelect")} loading={selecting} disabled={disabled || validity.expired} onPress={onSelect} />
    </Card>
  );
}

const st = StyleSheet.create({
  provider: { ...type.label, color: colors.blue700 },
  total: { ...type.sectionTitle, color: colors.navy950, fontVariant: ["tabular-nums"] },
  section: { ...type.label, color: colors.navy950, marginTop: space.x1 },
  coverRow: { gap: 2, paddingVertical: 2 },
  coverName: { ...type.body, color: colors.neutral700 },
  compare: { flexDirection: "row", alignItems: "center", gap: space.x1, padding: space.x1, minHeight: 44, borderRadius: radius.control },
  toggle: { minHeight: 44 },
  compareText: { ...type.label, color: colors.neutral700 },
});
