import React, { useEffect, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { ArrowRight, BadgeCheck, CalendarDays, CheckSquare, ChevronDown, ChevronUp, Clock3, Coins, ExternalLink, FileText, Receipt, ShieldCheck, Square, Star } from "lucide-react-native";
import { CheckList, MetaGrid } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { ripple, StatusChip } from "@/components/ui";
import { InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { QuoteOffer } from "@/api/client";
import { carrierClaimsDays, carrierRating, coverLevel, localized, normalizeCoverage, providerName, validityLeft } from "@/lib/purchase";
import { carrierLogo } from "@/lib/renewal";
import { useInsurerLogo } from "@/components/offers/useInsurerLogo";
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

const LEVEL_KEY = { essential: "ofLevelEssential", standard: "ofLevelStandard", full: "ofLevelFull" } as const;

/** Verified tick only when the carrier row says so (licence / verification flags); never assumed. */
function carrierVerified(offer: QuoteOffer): boolean {
  const c = (offer.carrier ?? null) as Record<string, unknown> | null;
  if (!c) return false;
  if (c.verified === true || c.is_verified === true || c.is_licensed === true) return true;
  const licence = String(c.licence_status ?? c.license_status ?? "").toUpperCase();
  return licence === "ACTIVE" || licence === "LICENSED";
}

/**
 * One insurer's offer (design 20): logo, name, product, rating when stated,
 * price per year, cover / excess / validity trio, included cover as check
 * bullets, expandable price breakdown + exclusions + documents, then
 * "View Details" and "Select Offer". Every value comes from the rated offer.
 */
export function OfferCard({
  offer,
  all,
  badge,
  best,
  compareSelected,
  onToggleCompare,
  onSelect,
  selecting,
  disabled,
  width,
  now,
}: {
  offer: QuoteOffer;
  /** Sibling offers of the same quote, for the relative cover level. */
  all?: QuoteOffer[];
  badge?: { label: string; tone: "success" | "info" | "warning" };
  /** Gold "Best Value" chip + gold select button (caller decides from stated limits). */
  best?: boolean;
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
  const name = providerName(offer, f.language);
  const logo = useInsurerLogo(carrierId, name, carrierLogo(offer));
  const rating = carrierRating(offer);
  const claimsDays = carrierClaimsDays(offer);
  const level = t(LEVEL_KEY[coverLevel(offer, all?.length ? all : [offer])]);
  const names = [...included.map((c) => c.name), ...optional.map((c) => `${c.name} (${t("cmpOptional")})`)];
  const shown = expanded ? names : names.slice(0, 6);
  // Two check columns only when every label is short enough to sit side by side on a phone.
  const twoColumns = shown.length > 1 && shown.every((n) => n.length <= 18);
  const expired = validity.expired;
  return (
    <View style={[st.card, best && st.cardBest, width ? { width } : null]}>
      {badge || onToggleCompare ? (
        <View style={ps.between}>
          {badge ? <StatusChip label={badge.label} tone={badge.tone} /> : <View />}
          {onToggleCompare ? (
            <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: !!compareSelected }} accessibilityLabel={t("offerAddCompare")} hitSlop={8} onPress={onToggleCompare} android_ripple={ripple()} style={st.compare}>
              {compareSelected ? <CheckSquare size={20} color={colors.blue600} /> : <Square size={20} color={colors.neutral500} />}
              <Text style={st.compareText}>{t("offerCompare")}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}

      <View style={st.head}>
        <InstitutionMark logoUrl={logo} initials={name.slice(0, 2).toUpperCase()} size={56} />
        <View style={st.flex}>
          <Pressable accessibilityRole="link" accessibilityLabel={name} hitSlop={4} onPress={() => carrierId && router.push({ pathname: "/institutions/insurer/[id]", params: { id: carrierId } })} style={st.nameRow}>
            <Text style={st.name} numberOfLines={2}>{name}</Text>
            {carrierVerified(offer) ? <BadgeCheck size={18} color={colors.blue600} /> : null}
          </Pressable>
          <Text style={st.product} numberOfLines={2}>{localized(offer.product?.name, f.language) || t("insuranceOffer")}</Text>
          {rating || claimsDays !== null ? (
            <View style={st.ratingRow}>
              {rating ? (
                <>
                  <Star size={14} color={colors.gold500} fill={colors.gold500} />
                  <Text style={st.rating}>{rating}</Text>
                </>
              ) : null}
              {claimsDays !== null ? <Text style={st.rating}>{rating ? " · " : ""}{t("offerClaimsDays", { days: claimsDays })}</Text> : null}
            </View>
          ) : null}
        </View>
        <View style={st.priceCol}>
          {best ? <StatusChip label={t("roBestValue")} tone="warning" /> : null}
          <Text style={st.price} accessibilityLabel={f.xaf(offer.total_minor)}>{f.xaf(offer.total_minor)}</Text>
          <Text style={st.perYear}>{t("ofPerYear")}</Text>
        </View>
      </View>

      <MetaGrid
        columns={2}
        items={[
          { icon: ShieldCheck, label: t("ofCoverLevel"), value: level },
          { icon: Coins, label: t("sumExcess"), value: cover.excessMinor === null ? t("sumNotStated") : f.xaf(cover.excessMinor) },
          { icon: Receipt, label: t("sumPremium"), value: f.xaf(offer.premium_minor) },
          { icon: expired ? Clock3 : CalendarDays, label: t("roValidity"), value: validity.label, tone: expired ? "danger" : undefined },
        ]}
      />

      {shown.length ? (
        <View style={st.covers}>
          <CheckList items={shown} columns={twoColumns ? 2 : 1} />
          {!expanded && names.length > shown.length ? <Text style={st.more}>{t("roMore", { count: names.length - shown.length })}</Text> : null}
        </View>
      ) : (
        <Text style={ps.meta}>{t("offerNoCover")}</Text>
      )}

      {expanded ? (
        <View style={st.details}>
          <Rule />
          <Text style={st.section}>{t("ofOfferDetails")}</Text>
          <InfoRow label={t("sumPremium")} value={f.xaf(offer.premium_minor)} />
          <InfoRow label={t("sumTaxes")} value={f.xaf(offer.tax_minor)} />
          <InfoRow label={t("sumFees")} value={f.xaf(offer.fee_minor)} />
          <InfoRow label={t("sumTotalPayable")} value={f.xaf(offer.total_minor)} strong />
          <InfoRow label={t("roValidity")} value={t("offerUntil", { validity: validity.label, date: f.dateTime(offer.valid_until) })} />
          <Text style={st.section}>{t("offerIncludedCover", { count: included.length })}</Text>
          {included.map((c) => (
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
            <Pressable key={d.url} accessibilityRole="link" style={st.docRow} onPress={() => openDocumentUrl(d.url, d.label)}>
              <FileText size={16} color={colors.blue600} />
              <Text style={ps.link}>{d.label}</Text>
              <ExternalLink size={14} color={colors.blue600} />
            </Pressable>
          ))}
        </View>
      ) : null}

      <View style={st.actions}>
        <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded(!expanded)} android_ripple={ripple()} style={({ pressed }) => [st.detailsBtn, pressed && st.pressed]}>
          <Text style={st.detailsText}>{expanded ? t("roHideDetails") : t("roViewDetails")}</Text>
          {expanded ? <ChevronUp size={18} color={colors.blue600} /> : <ChevronDown size={18} color={colors.blue600} />}
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={expired ? t("offerExpired") : t("roSelect")}
          accessibilityState={{ disabled: !!disabled || expired, busy: !!selecting }}
          disabled={disabled || expired || selecting}
          onPress={onSelect}
          android_ripple={ripple(true)}
          style={({ pressed }) => [st.selectBtn, best && st.selectBtnGold, (disabled || expired) && st.disabled, pressed && st.pressed]}
        >
          {selecting ? (
            <ActivityIndicator color={best ? colors.navy950 : colors.white} />
          ) : (
            <>
              <Text style={[st.selectText, best && st.selectTextGold]}>{expired ? t("offerExpired") : t("roSelect")}</Text>
              {!expired ? <ArrowRight size={18} color={best ? colors.navy950 : colors.white} /> : null}
            </>
          )}
        </Pressable>
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.9 },
  disabled: { opacity: 0.5 },
  card: { backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  cardBest: { borderColor: colors.gold500 },
  head: { flexDirection: "row", gap: space.x2, alignItems: "flex-start" },
  nameRow: { flexDirection: "row", alignItems: "center", gap: 4, minHeight: 24 },
  name: { ...type.cardTitle, fontSize: 16, lineHeight: 21, color: colors.navy950, flexShrink: 1 },
  product: { ...type.body, color: colors.neutral600 },
  ratingRow: { flexDirection: "row", alignItems: "center", gap: 4, marginTop: 2, flexWrap: "wrap" },
  rating: { ...type.meta, color: colors.neutral700 },
  priceCol: { alignItems: "flex-end", gap: 2, maxWidth: "46%" },
  price: { ...type.sectionTitle, fontSize: 18, lineHeight: 24, color: colors.navy950, fontVariant: ["tabular-nums"], textAlign: "right" },
  perYear: { ...type.meta, color: colors.neutral600 },
  covers: { backgroundColor: colors.blue50, borderRadius: radius.card, padding: space.x3, gap: space.x1 },
  more: { ...type.meta, color: colors.blue700 },
  details: { gap: space.x2 },
  section: { ...type.label, color: colors.navy950, marginTop: space.x1 },
  coverRow: { gap: 2, paddingVertical: 2 },
  coverName: { ...type.body, color: colors.neutral700 },
  docRow: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 40 },
  compare: { flexDirection: "row", alignItems: "center", gap: space.x1, paddingHorizontal: space.x2, minHeight: 40, borderRadius: radius.control, overflow: "hidden" },
  compareText: { ...type.label, color: colors.neutral700 },
  actions: { flexDirection: "row", gap: space.x2 },
  detailsBtn: { flex: 1, minHeight: 48, borderWidth: 1.5, borderColor: colors.blue600, borderRadius: radius.control, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 4, overflow: "hidden" },
  detailsText: { ...type.label, color: colors.blue600 },
  selectBtn: { flex: 1, minHeight: 48, backgroundColor: colors.blue600, borderRadius: radius.control, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, overflow: "hidden" },
  selectBtnGold: { backgroundColor: colors.gold500 },
  selectText: { ...type.label, color: colors.white },
  selectTextGold: { color: colors.navy950 },
});
