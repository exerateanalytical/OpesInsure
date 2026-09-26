import React, { ReactNode } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CalendarDays, Car, Coins, ShieldCheck, ShieldOff } from "lucide-react-native";
import { CheckList, DetailRow, HeroCard, SectionHeading } from "@/components/design";
import { Card, StatusChip } from "@/components/ui";
import { PriceRow, TotalBand } from "@/components/policies/RenewalUi";
import { Proposal, QuoteOffer } from "@/api/client";
import { localized, normalizeCoverage, providerName } from "@/lib/purchase";
import { carrierLogo } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * What the customer is agreeing to (design 13/53): insurer + product hero,
 * price breakdown with the total band, cover dates and excess, included
 * cover as check bullets and key exclusions — all from the server's terms
 * snapshot (never recomputed on the device).
 */
export function ProposalSummary({ proposal, offer, chip, title }: { proposal: Proposal; offer?: QuoteOffer | null; chip?: ReactNode; title?: string }) {
  const f = useFormatters();
  const { t: tr } = useTranslation();
  const t = proposal.terms_snapshot;
  // The proposal's own offer wins for terms; the store's selected offer fills in the insurer when the API row lacks the carrier relation.
  const source = proposal.offer ?? offer ?? null;
  const named = [proposal.offer, offer].find((o) => o?.carrier?.party?.display_name) ?? null;
  const cover = normalizeCoverage(t?.coverage_snapshot ?? source?.coverage_snapshot, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  const productName = localized(source?.product?.name, f.language) || tr("insurancePolicy");
  const lineCode = String(source?.product?.line_code ?? "").toUpperCase();
  const isMotor = lineCode === "MOTOR" || /motor|auto|véhicule|vehicle/i.test(productName);
  const provider = named ? providerName(named, f.language) : null;
  return (
    <>
      <Card>
        <SectionHeading title={title ?? tr("rrPolicySummary")} right={chip ?? <StatusChip label={proposal.proposal_number} tone="info" />} />
        <HeroCard
          icon={isMotor ? Car : ShieldCheck}
          title={productName}
          provider={provider}
          providerLogo={named ? carrierLogo(named) : null}
          lines={[tr("sumApplication") + " " + proposal.proposal_number]}
          meta={[
            { icon: CalendarDays, label: tr("sumCoverStarts"), value: t?.coverage_starts_at ? f.date(t.coverage_starts_at) : tr("sumWhenPaid") },
            { icon: CalendarDays, label: tr("sumCoverEnds"), value: t?.coverage_ends_at ? f.date(t.coverage_ends_at) : tr("sumTwelveMonths") },
          ]}
          style={st.hero}
        />
      </Card>
      <Card>
        <SectionHeading title={tr("rrPriceBreakdown")} />
        <View style={st.priceBox}>
          <PriceRow label={tr("sumPremium")} value={f.xaf(t?.premium_minor)} />
          <PriceRow label={tr("sumTaxes")} value={f.xaf(t?.tax_minor)} />
          <PriceRow label={tr("sumFees")} value={f.xaf(t?.fee_minor)} />
        </View>
        <TotalBand label={tr("sumTotalToPay")} value={f.xaf(t?.total_minor)} />
        <DetailRow icon={Coins} label={tr("sumExcess")} value={cover.excessMinor === null ? tr("sumNotStated") : f.xaf(cover.excessMinor)} />
      </Card>
      {included.length || cover.exclusions.length ? (
        <Card>
          <SectionHeading icon={ShieldCheck} title={tr("sumWhatCovered")} />
          {included.length ? (
            <CheckList items={included.map((c) => (c.limitMinor !== null ? `${c.name} · ${f.xaf(c.limitMinor)}` : c.name))} />
          ) : null}
          {cover.exclusions.length ? (
            <View style={st.exclusions}>
              <View style={st.exclusionHead}>
                <ShieldOff size={18} color={colors.dangerText} />
                <Text style={st.exclusionTitle}>{tr("sumKeyExclusions")}</Text>
              </View>
              {cover.exclusions.map((e) => (
                <Text key={e.code} style={st.exclusion}>
                  • {e.name}
                </Text>
              ))}
            </View>
          ) : null}
        </Card>
      ) : null}
    </>
  );
}

const st = StyleSheet.create({
  hero: { borderWidth: 0, padding: 0 },
  priceBox: { backgroundColor: colors.neutral50, borderRadius: 12, padding: space.x3, gap: space.x1 },
  exclusions: { gap: space.x1, marginTop: space.x2 },
  exclusionHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  exclusionTitle: { ...type.label, color: colors.navy950 },
  exclusion: { ...type.meta, color: colors.neutral600 },
});
