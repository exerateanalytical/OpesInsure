import React, { ReactNode } from "react";
import { StyleSheet, View } from "react-native";
import { CalendarDays, CarFront, Clock, Coins, FileSignature, ShieldCheck, UserRound } from "lucide-react-native";
import { HeroCard, type HeroMeta } from "@/components/design";
import { ReviewRow, ReviewSection } from "@/components/review/ReviewSummary";
import { CoverList } from "@/components/purchase/CoverList";
import { PriceRow, TotalBand } from "@/components/policies/RenewalUi";
import type { Proposal, QuoteOffer, QuoteResult } from "@/api/client";
import type { ProposalChecklist } from "@/api/workflow";
import { localized, normalizeCoverage, proposalStatusInfo, providerName } from "@/lib/purchase";
import { carrierLogo, riskFactsLabel } from "@/lib/renewal";
import { coverEnd, coverStart, hasInstalments, nonPaymentConsequence, paymentPlan, type CoverEnd, type CoverStart, type ScheduleRow } from "@/lib/contractTerms";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space } from "@/theme/tokens";

/**
 * What the customer is agreeing to (design 13/53), all from the server: the
 * insurer + product hero with the insured object, cover period and offer
 * validity; price breakdown, payment plan (instalments) and what happens on a
 * missed payment; covers with their own limit and deductible; key exclusions.
 * Used by the terms screen, the application hub and checkout.
 *
 * GET proposals/{id} carries neither the insurer nor the risk facts: `quote`
 * (useProposalQuote) supplies them, so a deep link with an empty store still
 * shows the insurer. `checklist` adds the product's cover-term rule.
 */
export function ProposalSummary({ proposal, offer, quote, checklist, chip, title }: { proposal: Proposal; offer?: QuoteOffer | null; quote?: QuoteResult | null; checklist?: ProposalChecklist | null; chip?: ReactNode; title?: string }) {
  const f = useFormatters();
  const { t, td } = useTranslation();
  const snap = proposal.terms_snapshot;
  // The proposal's own offer wins; the store's selected offer only counts when it is this proposal's offer (a stale selection must never rename the insurer).
  const ownOfferId = proposal.offer?.id ?? proposal.quote_offer_id ?? snap?.offer_id ?? null;
  const own = (o: QuoteOffer | null | undefined) => (o && (!ownOfferId || o.id === ownOfferId) ? o : null);
  const quoteOffer = own(quote?.offers.find((o) => o.id === ownOfferId));
  const storeOffer = own(offer);
  const source = proposal.offer ?? quoteOffer ?? storeOffer ?? null;
  const named = [proposal.offer, quoteOffer, storeOffer].find((o) => o?.carrier?.party?.display_name) ?? null;
  const logo = [proposal.offer, quoteOffer, storeOffer].map(carrierLogo).find(Boolean) ?? carrierLogo(proposal);
  const cover = normalizeCoverage(snap?.coverage_snapshot ?? source?.coverage_snapshot, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  const productName = localized(source?.product?.name, f.language) || t("insurancePolicy");
  const lineCode = String(source?.product?.line_code ?? quote?.quote.line_code ?? snap?.line_code ?? "").toUpperCase();
  const isMotor = lineCode === "MOTOR" || /motor|auto|véhicule|vehicle/i.test(productName);
  const provider = named ? providerName(named, f.language) : null;
  const insured = riskFactsLabel(snap?.risk_facts ?? quote?.quote.risk_facts);

  const terms = proposal.cover_terms ?? checklist?.cover_terms ?? null;
  const rule = checklist?.cover_term_rule ?? null;
  const plan = paymentPlan(terms);
  const nonPayment = nonPaymentConsequence(terms, rule);
  // Validity only matters before the policy exists.
  const stage = proposalStatusInfo(proposal.status, f.language).stage;
  const validUntil = !proposal.policy_id && stage !== "paid" && stage !== "closed" && stage !== "declined" ? source?.valid_until ?? null : null;

  const startText = (s: CoverStart) => (s.kind === "date" ? f.date(s.date) : s.event === "APPROVAL" ? t("ctStartsOnApproval") : s.event === "MIDNIGHT" ? t("ctStartsMidnight") : t("sumWhenPaid"));
  const endText = (e: CoverEnd) =>
    e.kind === "date" ? f.date(e.date) : e.unit === "DAY" ? (e.value === 1 ? t("ctEndsAfterDay") : t("ctEndsAfterDays", { count: e.value })) : e.value === 1 ? t("ctEndsAfterMonth") : t("ctEndsAfterMonths", { count: e.value });
  const dueText = (r: ScheduleRow) =>
    r.due.kind === "bind" ? t("ctDueAtBind") : r.due.kind === "months" ? (r.due.months === 1 ? t("ctDueAfterMonth") : t("ctDueAfterMonths", { count: r.due.months })) : t("ctDueLater", { n: r.sequence });

  const meta: HeroMeta[] = [
    ...(insured ? [{ icon: isMotor ? CarFront : UserRound, label: t("qtInsured"), value: insured }] : []),
    { icon: CalendarDays, label: t("sumCoverStarts"), value: startText(coverStart(terms, rule, snap?.coverage_starts_at)) },
    { icon: CalendarDays, label: t("sumCoverEnds"), value: endText(coverEnd(terms, rule, snap?.coverage_ends_at)) },
    ...(validUntil ? [{ icon: Clock, label: t("cqrValidUntil"), value: f.date(validUntil) }] : []),
  ];
  // Per-cover deductibles are listed with each cover; the single excess row is for offers without them.
  const perCoverDeductible = included.some((c) => c.deductibleMinor !== null);

  return (
    <>
      <ReviewSection icon={FileSignature} title={title ?? t("ctSummaryTitle")}>
        <HeroCard
          icon={isMotor ? CarFront : ShieldCheck}
          title={productName}
          provider={provider}
          providerLogo={logo}
          chip={chip}
          lines={[`${t("sumApplication")} ${proposal.proposal_number}`]}
          meta={meta}
          metaColumns={2}
          compact
          style={st.hero}
        />
      </ReviewSection>

      <ReviewSection icon={Coins} title={t("ctPriceTitle")}>
        <View style={st.stack}>
        <View style={st.priceBox}>
          <PriceRow label={t("sumPremium")} value={f.xaf(snap?.premium_minor)} />
          <PriceRow label={t("sumTaxes")} value={f.xaf(snap?.tax_minor)} />
          <PriceRow label={t("sumFees")} value={f.xaf(snap?.fee_minor)} />
        </View>
        <TotalBand label={t("sumTotalToPay")} value={f.xaf(snap?.total_minor)} />
        <ReviewRow first label={t("ctPayment")} value={td(`ctPlan_${plan.plan}`, t("ctPlan_CUSTOM"))} />
        {hasInstalments(plan) ? (
          <View style={st.priceBox} accessibilityLabel={t("ctSchedule")}>
            {plan.rows.map((r) => (
              <PriceRow key={r.sequence} label={dueText(r)} value={f.xaf(r.amountMinor)} sub={r.feeMinor ? t("ctInstalmentFee", { amount: f.xaf(r.feeMinor) }) : undefined} strong={r.due.kind === "bind"} />
            ))}
            {plan.totalMinor !== null ? <PriceRow label={t("ctTotalPayable")} value={f.xaf(plan.totalMinor)} strong /> : null}
          </View>
        ) : null}
        {nonPayment ? <ReviewRow label={t("ctNonPayment")} value={td(`ctNonPayment_${nonPayment}`, "")} /> : null}
        </View>
      </ReviewSection>

      <ReviewSection icon={ShieldCheck} title={t("sumWhatCovered")}>
        <CoverList covers={included} exclusions={cover.exclusions} />
        {!perCoverDeductible ? <ReviewRow first={!included.length && !cover.exclusions.length} label={t("sumExcess")} value={cover.excessMinor === null ? t("sumNotStated") : f.xaf(cover.excessMinor)} /> : null}
      </ReviewSection>
    </>
  );
}

const st = StyleSheet.create({
  hero: { borderWidth: 0, padding: 0 },
  stack: { gap: space.x2 },
  priceBox: { backgroundColor: colors.neutral50, borderRadius: radius.control, padding: space.x3, gap: space.x1 },
});
