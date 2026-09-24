import React from "react";
import { Text } from "react-native";
import { Card } from "@/components/ui";
import { InfoRow, Rule, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { Proposal, QuoteOffer } from "@/api/client";
import { localized, normalizeCoverage, providerName } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";

/**
 * What the customer is agreeing to: insurer, product, price breakdown,
 * cover dates, excess, included cover and key exclusions — all from the
 * server's terms snapshot (never recomputed on the device).
 */
export function ProposalSummary({ proposal, offer }: { proposal: Proposal; offer?: QuoteOffer | null }) {
  const f = useFormatters();
  const t = proposal.terms_snapshot;
  const source = proposal.offer ?? offer ?? null;
  const cover = normalizeCoverage(t?.coverage_snapshot ?? source?.coverage_snapshot, f.language);
  const included = cover.coverages.filter((c) => !c.optional);
  return (
    <>
      <Card>
        <Text style={ps.meta}>{source ? providerName(source) : "Licensed insurance carrier"}</Text>
        <Text style={ps.title}>{localized(source?.product?.name, f.language) || "Insurance policy"}</Text>
        <InfoRow label="Application" value={proposal.proposal_number} />
        <Rule />
        <InfoRow label="Premium" value={f.xaf(t?.premium_minor)} />
        <InfoRow label="Taxes" value={f.xaf(t?.tax_minor)} />
        <InfoRow label="Fees" value={f.xaf(t?.fee_minor)} />
        <InfoRow label="Total to pay" value={f.xaf(t?.total_minor)} strong />
        <Rule />
        <InfoRow label="Cover starts" value={t?.coverage_starts_at ? f.date(t.coverage_starts_at) : "When payment is confirmed"} />
        <InfoRow label="Cover ends" value={t?.coverage_ends_at ? f.date(t.coverage_ends_at) : "12 months after start"} />
        <InfoRow label="Excess / deductible" value={cover.excessMinor === null ? "Not stated" : f.xaf(cover.excessMinor)} />
      </Card>
      {included.length || cover.exclusions.length ? (
        <Card>
          <Text style={ps.title}>What is covered</Text>
          {included.map((c) => (
            <InfoRow key={c.code} label={c.name} value={c.limitMinor !== null ? f.xaf(c.limitMinor) : "Included"} />
          ))}
          {cover.exclusions.length ? (
            <>
              <Rule />
              <Text style={ps.title}>Key exclusions</Text>
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
