import React from "react";
import { Text } from "react-native";
import { Card } from "@/components/ui";
import { InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { Proposal, QuoteOffer } from "@/api/client";
import { localized, normalizeCoverage, providerName } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";

/**
 * What the customer is agreeing to: insurer, product, price breakdown,
 * cover dates, excess, included cover and key exclusions — all from the
 * server's terms snapshot (never recomputed on the device).
 */
export function ProposalSummary({ proposal, offer }: { proposal: Proposal; offer?: QuoteOffer | null }) {
  const f = useFormatters();
  const { t: tr } = useTranslation();
  const t = proposal.terms_snapshot;
  const source = proposal.offer ?? offer ?? null;
  const cover = normalizeCoverage(t?.coverage_snapshot ?? source?.coverage_snapshot, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  return (
    <>
      <Card>
        <Text style={ps.meta}>{source ? providerName(source) : tr("licensedCarrier")}</Text>
        <Text style={ps.title}>{localized(source?.product?.name, f.language) || tr("insurancePolicy")}</Text>
        <InfoRow label={tr("sumApplication")} value={proposal.proposal_number} />
        <Rule />
        <InfoRow label={tr("sumPremium")} value={f.xaf(t?.premium_minor)} />
        <InfoRow label={tr("sumTaxes")} value={f.xaf(t?.tax_minor)} />
        <InfoRow label={tr("sumFees")} value={f.xaf(t?.fee_minor)} />
        <InfoRow label={tr("sumTotalToPay")} value={f.xaf(t?.total_minor)} strong />
        <Rule />
        <InfoRow label={tr("sumCoverStarts")} value={t?.coverage_starts_at ? f.date(t.coverage_starts_at) : tr("sumWhenPaid")} />
        <InfoRow label={tr("sumCoverEnds")} value={t?.coverage_ends_at ? f.date(t.coverage_ends_at) : tr("sumTwelveMonths")} />
        <InfoRow label={tr("sumExcess")} value={cover.excessMinor === null ? tr("sumNotStated") : f.xaf(cover.excessMinor)} />
      </Card>
      {included.length || cover.exclusions.length ? (
        <Card>
          <Text style={ps.title}>{tr("sumWhatCovered")}</Text>
          {included.map((c) => (
            <InfoRow key={c.code} label={c.name} value={c.limitMinor !== null ? f.xaf(c.limitMinor) : tr("sumIncluded")} />
          ))}
          {cover.exclusions.length ? (
            <>
              <Rule />
              <Text style={ps.title}>{tr("sumKeyExclusions")}</Text>
              {cover.exclusions.map((e) => (
                <Text key={e.code} style={ps.meta}>
                  • {e.name}
                </Text>
              ))}
            </>
          ) : null}
        </Card>
      ) : null}
    </>
  );
}
