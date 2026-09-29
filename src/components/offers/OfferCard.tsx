import React, { useEffect, useState } from "react";
import { Pressable, StyleProp, StyleSheet, Text, View, ViewStyle } from "react-native";
import { router } from "expo-router";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { ArrowRight, BadgeCheck, CalendarDays, CheckCircle2, CheckSquare, ChevronDown, ChevronUp, Clock3, Coins, ExternalLink, FileText, ShieldCheck, Square, Star } from "lucide-react-native";
import { CheckList, MetaGrid } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { Button, ripple, StatusChip } from "@/components/ui";
import { initialsOf } from "@/components/filters/FilteredList";
import { InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { QuoteOffer } from "@/api/client";
import { carrierClaimsDays, carrierRating, coverLevel, localized, normalizeCoverage, providerName, validityLeft } from "@/lib/purchase";
import { MAX_COMPARE, offerPeriod, periodCopy, type OfferChoice } from "@/lib/offerChoice";
import { carrierLogo } from "@/lib/renewal";
import { useInsurerLogo } from "@/components/offers/useInsurerLogo";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";

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
 * The one choose affordance for an offer, shared by the offer cards and the comparison columns:
 * "Select Offer" while it can be chosen, "Selected — open application" for the accepted offer,
 * otherwise disabled with the reason (expired, no longer available, another offer chosen).
 */
export function ChooseButton({ choice, best, selecting, opening, disabled, onSelect, onOpenApplication, style, compact }: { choice: OfferChoice; best?: boolean; selecting?: boolean; opening?: boolean; disabled?: boolean; onSelect: () => void; onOpenApplication?: () => void; style?: StyleProp<ViewStyle>; /** Narrow comparison column: no icon, tight padding. */ compact?: boolean }) {
  const { t } = useTranslation();
  if (compact) style = [st.compact, style];
  if (choice.kind === "selected")
    return <Button size="small" variant="primary" icon={compact ? undefined : CheckCircle2} label={t("ofSelectedOpen")} loading={opening} disabled={!onOpenApplication} onPress={onOpenApplication} style={style} />;
  if (choice.kind === "blocked") {
    const label = choice.reason === "expired" ? t("offerExpired") : choice.reason === "other_chosen" ? t("ofOtherChosen") : t("ofOfferUnavailable");
    return <Button size="small" variant="secondary" label={label} disabled style={style} />;
  }
  return <Button size="small" variant={best ? "gold" : "primary"} icon={compact ? undefined : ArrowRight} label={t("roSelect")} loading={selecting} disabled={disabled} onPress={onSelect} style={style} />;
}

/**
 * One insurer's offer (design 20): logo, name, product, rating when stated,
 * "Total payable" (with the cover period only when the offer states one) and
 * its premium / taxes / fees breakdown, cover level / excess / validity,
 * included cover as check bullets, expandable cover details + exclusions +
 * documents, then "View Details" and the shared choose button. Every value
 * comes from the rated offer.
 */
export function OfferCard({
  offer,
  all,
  badge,
  best,
  compareSelected,
  compareFull,
  onToggleCompare,
  choice,
  onSelect,
  onOpenApplication,
  selecting,
  opening,
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
  /** Three offers are already ticked: this tick is refused (onToggleCompare explains why). */
  compareFull?: boolean;
  /** Absent when the offer cannot be compared (not choosable). */
  onToggleCompare?: () => void;
  choice: OfferChoice;
  onSelect: () => void;
  onOpenApplication?: () => void;
  selecting?: boolean;
  opening?: boolean;
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
  const period = periodCopy(offerPeriod(offer));
  const compareLabel = compareFull && !compareSelected ? t("ofCompareMaxA11y", { count: MAX_COMPARE }) : t("offerAddCompare");
  return (
    <View style={[st.card, best && st.cardBest, width ? { width } : null]}>
      {badge || onToggleCompare ? (
        <View style={ps.between}>
          {badge ? <StatusChip label={badge.label} tone={badge.tone} /> : <View />}
          {onToggleCompare ? (
            <Pressable
              accessibilityRole="checkbox"
              accessibilityState={{ checked: !!compareSelected, disabled: !!compareFull && !compareSelected }}
              accessibilityLabel={compareLabel}
              hitSlop={8}
              onPress={onToggleCompare}
              android_ripple={ripple()}
              style={[st.compare, compareFull && !compareSelected && st.disabled]}
            >
              {compareSelected ? <CheckSquare size={20} color={colors.blue600} /> : <Square size={20} color={colors.neutral500} />}
              <Text style={st.compareText}>{t("offerCompare")}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}

      <View style={st.head}>
        <InstitutionMark logoUrl={logo} initials={initialsOf(name)} size={56} />
        <View style={st.flex}>
          <Pressable accessibilityRole="link" accessibilityLabel={name} hitSlop={4} onPress={() => carrierId && router.push({ pathname: "/institutions/insurer/[id]", params: { id: carrierId } })} style={st.nameRow}>
            <Text style={st.name} numberOfLines={1}>{name}</Text>
            {carrierVerified(offer) ? <BadgeCheck size={18} color={colors.blue600} /> : null}
          </Pressable>
          <Text style={st.product}>{localized(offer.product?.name, f.language) || t("insuranceOffer")}</Text>
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
          <Text style={st.priceLabel}>{t("sumTotalPayable")}</Text>
          <Text style={st.price} accessibilityLabel={`${t("sumTotalPayable")} ${f.xaf(offer.total_minor)}${period ? ` ${t(period.key as CopyKey, period.vars)}` : ""}`}>{f.xaf(offer.total_minor)}</Text>
          {period ? <Text style={st.period}>{t(period.key as CopyKey, period.vars)}</Text> : null}
        </View>
      </View>

      <View style={st.breakdown} accessibilityRole="summary">
        {([["sumPremium", offer.premium_minor], ["sumTaxes", offer.tax_minor], ["sumFees", offer.fee_minor]] as const).map(([key, minor], i) => (
          <View key={key} style={[st.breakdownCell, i > 0 && st.breakdownDivider]}>
            <Text style={st.breakdownLabel}>{t(key)}</Text>
            <Text style={st.breakdownValue}>{f.xaf(minor)}</Text>
          </View>
        ))}
      </View>

      <MetaGrid
        columns={3}
        items={[
          { icon: ShieldCheck, label: t("ofCoverLevel"), value: level },
          { icon: Coins, label: t("sumExcess"), value: cover.excessMinor === null ? t("sumNotStated") : f.xaf(cover.excessMinor) },
          { icon: expired ? Clock3 : CalendarDays, label: t("roValidity"), value: validity.label, tone: expired ? "danger" : undefined },
        ]}
      />

      {shown.length ? (
        <View style={st.covers}>
          <CheckList items={shown} columns={twoColumns ? 2 : 1} tint="blue" compact />
          {!expanded && names.length > shown.length ? <Text style={st.more}>{t("roMore", { count: names.length - shown.length })}</Text> : null}
        </View>
      ) : (
        <Text style={ps.meta}>{t("offerNoCover")}</Text>
      )}

      {expanded ? (
        <View style={st.details}>
          <Rule />
          <Text style={st.section}>{t("ofOfferDetails")}</Text>
          <InfoRow label={t("cmpRowValidUntil")} value={t("offerUntil", { validity: validity.label, date: f.dateTime(offer.valid_until) })} />
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
        <Button
          size="small"
          variant="secondary"
          icon={expanded ? ChevronUp : ChevronDown}
          iconPosition="right"
          label={expanded ? t("roHideDetails") : t("roViewDetails")}
          onPress={() => setExpanded(!expanded)}
          style={st.action}
        />
        <ChooseButton choice={choice} best={best} selecting={selecting} opening={opening} disabled={disabled} onSelect={onSelect} onOpenApplication={onOpenApplication} style={st.action} />
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
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
  priceLabel: { ...type.meta, color: colors.neutral600, textAlign: "right" },
  price: { ...type.sectionTitle, fontSize: 18, lineHeight: 24, color: colors.navy950, fontVariant: ["tabular-nums"], textAlign: "right" },
  period: { ...type.meta, color: colors.neutral600 },
  breakdown: { flexDirection: "row", borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, paddingVertical: space.x2 },
  breakdownCell: { flex: 1, paddingHorizontal: space.x2, gap: 2 },
  breakdownDivider: { borderLeftWidth: 1, borderLeftColor: colors.neutral200 },
  breakdownLabel: { ...type.meta, color: colors.neutral600 },
  breakdownValue: { ...type.label, color: colors.navy950, fontVariant: ["tabular-nums"] },
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
  // Two actions side by side on a 360 dp phone: tighter padding than a full-width button.
  action: { flex: 1, paddingHorizontal: space.x2 },
  compact: { paddingHorizontal: space.x1 },
});
