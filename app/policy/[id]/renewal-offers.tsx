import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, BadgeCheck, CalendarDays, ChevronDown, ChevronUp, Coins, ExternalLink, FileText, ShieldCheck, Star, Tag } from "lucide-react-native";
import { BrandHeader, CheckList, CtaBar, MetaGrid } from "@/components/design";
import { Button, Card, ripple, Screen, StatusChip } from "@/components/ui";
import { InstitutionMark } from "@/components/InstitutionMark";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, InfoRow, Rule } from "@/components/purchase/PurchaseUi";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { RenewalHero, RenewalSteps } from "@/components/policies/RenewalUi";
import { useRenewal } from "@/hooks/useRenewal";
import { useInsurance } from "@/store/insurance";
import { QuoteOffer } from "@/api/client";
import { bestValueOfferId, carrierLogo, daysUntil, insuredObjectLabel, RenewalFlow, RenewalSort, renewalPeriod, sortRenewalOffers } from "@/lib/renewal";
import { carrierKey, carrierRating, coverLevel, localized, normalizeCoverage, providerName, validityLeft } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const LEVEL_KEY = { essential: "ofLevelEssential", standard: "ofLevelStandard", full: "ofLevelFull" } as const;

/**
 * Step 2b: every insurer's renewal offer for the same risk (design 52).
 * Ratings are shown only when the carrier row carries one; "Best Value"
 * is cover-per-franc from stated limits; "Current Provider" marks the
 * policy's insurer.
 */
const EMPTY_OFFERS: never[] = [];
export default function RenewalOffers() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { id } = useLocalSearchParams<{ id: string }>();
  const { policy, policyState, result, busy, error, loadPolicy, prepare } = useRenewal(id, { autoQuote: true });
  const setQuote = useInsurance((s) => s.setQuoteResult);
  const ctx = RenewalFlow.get(id);
  const offers = result?.offers ?? EMPTY_OFFERS;
  const [sort, setSort] = useState<RenewalSort>("price");
  const [selected, setSelected] = useState<string | null>(ctx?.selectedOfferId ?? null);
  const [expanded, setExpanded] = useState<string | null>(null);
  const visible = useMemo(() => sortRenewalOffers(offers, sort, policy?.carrier_id), [offers, sort, policy?.carrier_id]);
  const best = useMemo(() => bestValueOfferId(offers), [offers]);
  const days = daysUntil(policy?.coverage_ends_at);
  const period = renewalPeriod(policy?.coverage_ends_at);
  const vehicle = insuredObjectLabel(policy, result?.quote.risk_facts);
  const currentPremium = policy?.terms_snapshot?.total_minor ?? policy?.premium_minor ?? null;

  const proceed = (offerId: string) => {
    if (!policy || !result) return;
    setQuote(result.quote, result.offers);
    RenewalFlow.start(policy, result);
    RenewalFlow.update({ selectedOfferId: offerId });
    router.push({ pathname: "/policy/[id]/renewal-review", params: { id: id ?? "" } } as never);
  };

  return (
    <Screen
      footer={
        policyState === "ready" && offers.length ? (
          <CtaBar>
            <Button label={t("roContinue")} icon={ArrowRight} disabled={!selected} onPress={() => selected && proceed(selected)} />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("roTitle")} subtitle={t("roSubtitle")} right={null} />
      <RenewalSteps current={1} />
      {policyState === "loading" ? (
        <LoadingState label={t("renewLoadingPolicy")} />
      ) : policyState === "missing" ? (
        <Card>
          <Text style={st.title}>{t("renewMissing")}</Text>
          <Text style={st.body}>{t("renewMissingBody")}</Text>
          <Button label={t("retry")} variant="secondary" onPress={() => void loadPolicy()} />
          <Button label={t("renewGoPolicies")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)/policies")} />
        </Card>
      ) : (
        <>
          <RenewalHero
            policy={policy}
            offer={null}
            riskFacts={result?.quote.risk_facts}
            title={vehicle ?? undefined}
            lines={vehicle ? [policy?.product_name ?? null] : [null]}
            chip={
              days !== null ? (
                <View style={st.expiryChip}>
                  <CalendarDays size={18} color={colors.danger} />
                  <View>
                    <Text style={st.expiryLabel}>{days >= 0 ? t("roExpiresIn") : t("policyStatus_EXPIRED")}</Text>
                    {days >= 0 ? <Text style={st.expiryValue}>{t("rnDays", { count: days })}</Text> : null}
                  </View>
                </View>
              ) : null
            }
            meta={[
              { icon: CalendarDays, label: t("rnCurrentExpiry"), value: f.date(policy?.coverage_ends_at) },
              { icon: ShieldCheck, label: t("roCurrentPremium"), value: currentPremium !== null ? f.xaf(currentPremium) : "—" },
            ]}
          />

          {busy && !result ? <LoadingState label={t("rqPreparing")} /> : null}
          {error ? <ErrorCard error={error} fallback={t("renewUnavailable")} onRetry={() => void prepare()} retryLabel={t("rnRetryQuote")} /> : null}
          {result && !offers.length ? (
            <Card>
              <Text style={st.body}>{t("rnNoOffers")}</Text>
              <Button label={t("rnRetryQuote")} variant="secondary" loading={busy} onPress={() => void prepare()} />
            </Card>
          ) : null}

          {offers.length ? (
            <View accessibilityRole="tablist" style={st.segments}>
              {([
                ["price", t("roSortPrice"), Tag],
                ["same_cover", t("roSortSame"), ShieldCheck],
                ["best_value", t("roSortValue"), Star],
              ] as const).map(([key, label, Icon]) => {
                const on = sort === key;
                return (
                  <Pressable key={key} accessibilityRole="tab" accessibilityState={{ selected: on }} accessibilityLabel={label} onPress={() => setSort(key)} android_ripple={ripple(on)} style={({ pressed }) => [st.segment, on && st.segmentOn, pressed && st.pressed]}>
                    <Icon size={16} color={on ? colors.white : colors.navy900} />
                    <Text style={[st.segmentText, on && st.segmentTextOn]}>{label}</Text>
                  </Pressable>
                );
              })}
            </View>
          ) : null}

          {visible.map((o) => (
            <RenewalOfferCard
              key={o.id}
              offer={o}
              all={offers}
              selected={selected === o.id}
              expanded={expanded === o.id}
              isCurrent={!!policy?.carrier_id && carrierKey(o) === policy.carrier_id}
              isBest={best === o.id}
              period={period}
              onToggle={() => setExpanded(expanded === o.id ? null : o.id)}
              onSelect={() => {
                setSelected(o.id);
                proceed(o.id);
              }}
              onPick={() => setSelected(o.id)}
            />
          ))}
        </>
      )}
    </Screen>
  );
}

function RenewalOfferCard({
  offer,
  all,
  selected,
  expanded,
  isCurrent,
  isBest,
  period,
  onToggle,
  onSelect,
  onPick,
}: {
  offer: QuoteOffer;
  all: QuoteOffer[];
  selected: boolean;
  expanded: boolean;
  isCurrent: boolean;
  isBest: boolean;
  period: { start: string; end: string } | null;
  onToggle: () => void;
  onSelect: () => void;
  onPick: () => void;
}) {
  const { t } = useTranslation();
  const f = useFormatters();
  const cover = normalizeCoverage(offer.coverage_snapshot, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  const optional = cover.coverages.filter((c) => c.optional);
  const rating = carrierRating(offer);
  const validity = validityLeft(offer.valid_until, Date.now(), f.language);
  const name = providerName(offer, f.language);
  const level = t(LEVEL_KEY[coverLevel(offer, all)]);
  const names = [...included.map((c) => c.name), ...optional.map((c) => `${c.name} (${t("cmpOptional")})`)];
  const shown = expanded ? names : names.slice(0, 6);
  return (
    <Pressable accessibilityRole="radio" accessibilityState={{ selected }} accessibilityLabel={name} onPress={onPick} android_ripple={ripple()} style={({ pressed }) => [st.offer, selected && st.offerOn, pressed && st.pressed]}>
      <View style={st.offerHead}>
        <InstitutionMark logoUrl={carrierLogo(offer)} initials={name.slice(0, 2).toUpperCase()} size={44} />
        <View style={st.headText}>
          <View style={st.nameRow}>
            <Text style={st.name}>{name}</Text>
            <BadgeCheck size={18} color={colors.blue600} />
          </View>
          <Text style={st.product} numberOfLines={2}>{localized(offer.product?.name, f.language) || t("insuranceOffer")}</Text>
          {rating ? (
            <View style={st.ratingRow}>
              <Star size={14} color={colors.gold500} fill={colors.gold500} />
              <Text style={st.rating}>{rating}</Text>
            </View>
          ) : null}
        </View>
        <View style={st.priceCol}>
          {isBest ? <StatusChip label={t("roBestValue")} tone="warning" /> : isCurrent ? <StatusChip label={t("roCurrentProvider")} tone="info" /> : null}
          <Text style={st.price}>{f.xaf(offer.total_minor)}</Text>
          <Text style={st.perYear}>{t("roPerYear")}</Text>
        </View>
      </View>
      <MetaGrid
        columns={3}
        items={[
          { icon: ShieldCheck, label: t("ofCoverLevel"), value: level },
          { icon: Coins, label: t("sumExcess"), value: cover.excessMinor === null ? t("sumNotStated") : f.xaf(cover.excessMinor) },
          { icon: CalendarDays, label: t("roPeriod"), value: t("roMonths12") },
        ]}
      />
      {period ? <Text style={st.meta}>{`${t("roPeriod")}: ${f.range(period.start, period.end)}`}</Text> : null}
      {shown.length ? (
        <View style={st.covers}>
          <CheckList items={shown} columns={2} compact />
          {!expanded && names.length > shown.length ? <Text style={st.more}>{t("roMore", { count: names.length - shown.length })}</Text> : null}
        </View>
      ) : (
        <Text style={st.meta}>{t("offerNoCover")}</Text>
      )}
      {expanded ? (
        <View style={st.details}>
          <Rule />
          <InfoRow label={t("sumPremium")} value={f.xaf(offer.premium_minor)} />
          <InfoRow label={t("sumTaxes")} value={f.xaf(offer.tax_minor)} />
          <InfoRow label={t("sumFees")} value={f.xaf(offer.fee_minor)} />
          <InfoRow label={t("sumTotalPayable")} value={f.xaf(offer.total_minor)} strong />
          <InfoRow label={t("roValidity")} value={t("offerUntil", { validity: validity.label, date: f.dateTime(offer.valid_until) })} />
          <Text style={st.section}>{t("sumKeyExclusions")}</Text>
          {cover.exclusions.length ? cover.exclusions.map((e) => <Text key={e.code} style={st.meta}>• {e.name}</Text>) : <Text style={st.meta}>{t("offerNoExclusions")}</Text>}
          {cover.documents.map((d) => (
            <Pressable key={d.url} accessibilityRole="link" style={st.docRow} onPress={() => openDocumentUrl(d.url, d.label)}>
              <FileText size={16} color={colors.blue600} />
              <Text style={st.link}>{d.label}</Text>
              <ExternalLink size={14} color={colors.blue600} />
            </Pressable>
          ))}
        </View>
      ) : null}
      <View style={st.actions}>
        <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={onToggle} android_ripple={ripple()} style={({ pressed }) => [st.detailsBtn, pressed && st.pressed]}>
          <Text style={st.detailsText}>{expanded ? t("roHideDetails") : t("roViewDetails")}</Text>
          {expanded ? <ChevronUp size={18} color={colors.blue600} /> : <ChevronDown size={18} color={colors.blue600} />}
        </Pressable>
        <Pressable accessibilityRole="button" accessibilityLabel={t("roSelect")} disabled={validity.expired} onPress={onSelect} android_ripple={ripple(true)} style={({ pressed }) => [st.selectBtn, isBest && st.selectBtnGold, validity.expired && st.disabled, pressed && st.pressed]}>
          <Text style={[st.selectText, isBest && st.selectTextGold]}>{validity.expired ? t("offerExpired") : t("roSelect")}</Text>
          {!validity.expired ? <ArrowRight size={18} color={isBest ? colors.navy950 : colors.white} /> : null}
        </Pressable>
      </View>
      {selected ? (
        <View style={st.selectedRow}>
          <Tag size={14} color={colors.blue700} />
          <Text style={st.selectedText}>{t("roSelected")}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.9 },
  disabled: { opacity: 0.5 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  link: { ...type.label, color: colors.blue600 },
  section: { ...type.label, color: colors.navy950, marginTop: space.x1 },
  expiryChip: { flexDirection: "row", alignItems: "center", gap: space.x2, backgroundColor: colors.dangerSoft, borderRadius: radius.card, paddingHorizontal: space.x2, paddingVertical: 6 },
  expiryLabel: { ...type.caption, fontFamily: "Inter_500Medium", color: colors.dangerText },
  expiryValue: { ...type.label, color: colors.dangerText },
  headText: { flexBasis: 130, flexGrow: 1, flexShrink: 1, minWidth: 0 },
  segments: { flexDirection: "row", gap: space.x2 },
  segment: { flexBasis: 0, flexGrow: 1, minHeight: 48, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 4, paddingHorizontal: 4, paddingVertical: 6, borderRadius: radius.control, borderWidth: 1.5, borderColor: colors.neutral200, backgroundColor: colors.white },
  segmentOn: { backgroundColor: colors.blue600, borderColor: colors.blue600 },
  segmentText: { ...type.label, fontSize: 12, lineHeight: 15, color: colors.navy950, textAlign: "center", flexShrink: 1 },
  segmentTextOn: { color: colors.white },
  offer: { backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.neutral200, borderRadius: radius.feature, padding: space.x4, gap: space.x3, overflow: "hidden" },
  offerOn: { borderColor: colors.blue600 },
  offerHead: { flexDirection: "row", flexWrap: "wrap", gap: space.x3, alignItems: "flex-start" },
  nameRow: { flexDirection: "row", alignItems: "center", gap: 4 },
  name: { ...type.cardTitle, fontSize: 16, lineHeight: 21, color: colors.navy950, flexShrink: 1 },
  product: { ...type.meta, color: colors.neutral600 },
  ratingRow: { flexDirection: "row", alignItems: "center", gap: 4, marginTop: 2 },
  rating: { ...type.meta, color: colors.neutral700 },
  priceCol: { alignItems: "flex-end", gap: 2, flexShrink: 0, marginLeft: "auto" },
  price: { ...type.sectionTitle, fontSize: 17, lineHeight: 22, color: colors.navy950, fontVariant: ["tabular-nums"] },
  perYear: { ...type.meta, color: colors.neutral600 },
  covers: { backgroundColor: colors.blue50, borderRadius: radius.card, padding: space.x3, gap: space.x1 },
  more: { ...type.meta, color: colors.blue700 },
  details: { gap: space.x2 },
  docRow: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 40 },
  actions: { flexDirection: "row", gap: space.x2 },
  detailsBtn: { flex: 1, minHeight: 48, borderWidth: 1.5, borderColor: colors.blue600, borderRadius: radius.control, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 4, overflow: "hidden" },
  detailsText: { ...type.label, color: colors.blue600 },
  selectBtn: { flex: 1, minHeight: 48, backgroundColor: colors.blue600, borderRadius: radius.control, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, overflow: "hidden" },
  selectBtnGold: { backgroundColor: colors.gold500 },
  selectText: { ...type.label, color: colors.white },
  selectTextGold: { color: colors.navy950 },
  selectedRow: { flexDirection: "row", alignItems: "center", gap: 6 },
  selectedText: { ...type.meta, color: colors.blue700 },
});
