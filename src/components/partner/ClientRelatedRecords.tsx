import React from "react";
import { Text } from "react-native";
import { Href, router } from "expo-router";
import { FileSignature, FileText, ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { Card, SectionTitle } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { PartnerClaim, PartnerPolicy, PartnerProposal, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import { AgentCard, AgentSection, AgentSkeleton } from "@/components/agent";
import { AgentListRow } from "./AgentListUi";
import { BookNote } from "./AgentBookUi";

/**
 * Customer 360 related records for a book client (AGT-004): policies,
 * proposals and claims, each drillable. Built from the caller's
 * server-scoped book lists, so nothing outside the book can appear.
 */
export function ClientRelatedRecords({
  customerId,
  base,
  loadPolicies,
  loadProposals,
  loadClaims,
  variant = "default",
}: {
  customerId: string;
  base: string;
  loadPolicies: () => Promise<PartnerPolicy[]>;
  loadProposals: () => Promise<PartnerProposal[]>;
  loadClaims: () => Promise<PartnerClaim[]>;
  /** "agent" = Commercial Agent spec v2 sections/rows; broker keeps "default". */
  variant?: "default" | "agent";
}) {
  const { t, td } = useTranslation();
  const q = useLoad(async () => {
    const [policies, proposals, claims] = await Promise.all([
      loadPolicies().catch(() => [] as PartnerPolicy[]),
      loadProposals().catch(() => [] as PartnerProposal[]),
      loadClaims().catch(() => [] as PartnerClaim[]),
    ]);
    const mine = policies.filter((p) => p.customer_id === customerId);
    const ids = new Set(mine.map((p) => p.id));
    return {
      policies: mine,
      proposals: proposals.filter((p) => p.customer_id === customerId),
      claims: claims.filter((c) => ids.has(c.policy_id)),
    };
  }, [customerId]);
  if (variant === "agent" && q.loading && !q.data) return <AgentSkeleton rows={3} />;
  if (q.loading || !q.data) return null;
  const d = q.data;
  if (variant === "agent") {
    const group = (title: string, n: number, empty: string, children: React.ReactNode) => (
      <AgentSection title={`${title} (${n})`}>
        <AgentCard padded={false}>{n === 0 ? <BookNote>{empty}</BookNote> : children}</AgentCard>
      </AgentSection>
    );
    return (
      <>
        {group(t("policies"), d.policies.length, t("policiesEmpty"), d.policies.map((p, i) => (
          <AgentListRow
            key={p.id}
            first={i === 0}
            icon={FileText}
            title={p.policy_number ?? t("pdPolicy")}
            subtitle={[p.carrier_name, shortDate(p.coverage_ends_at)].filter(Boolean).join(" · ")}
            amount={money(p.premium_minor)}
            status={p.status}
            statusLabel={td(`policyStatus_${p.status}`, humanize(p.status))}
            onPress={() => router.push(`${base}/policies/${p.id}` as Href)}
          />
        )))}
        {group(t("ptProposals"), d.proposals.length, t("ptNoProposals"), d.proposals.map((p, i) => (
          <AgentListRow
            key={p.id}
            first={i === 0}
            icon={FileSignature}
            title={p.proposal_number}
            subtitle={p.carrier_name}
            amount={p.total_minor != null ? money(p.total_minor) : null}
            status={p.status}
            statusLabel={td(`proposalStatus_${p.status}`, humanize(p.status))}
            onPress={() => router.push(`${base}/proposals` as Href)}
          />
        )))}
        {group(t("claims"), d.claims.length, t("pdNoClaimsBook"), d.claims.map((c, i) => (
          <AgentListRow
            key={c.id}
            first={i === 0}
            icon={ShieldAlert}
            title={c.claim_number}
            subtitle={[c.policy_number, shortDate(c.submitted_at)].filter(Boolean).join(" · ")}
            status={c.status}
            statusLabel={td(`claimStatus_${c.status}`, humanize(c.status))}
            onPress={() => router.push(`${base}/claims/${c.id}` as Href)}
          />
        )))}
      </>
    );
  }
  const none = (label: string) => <Text style={{ ...type.meta, color: colors.neutral600 }}>{label}</Text>;
  return (
    <>
      <SectionTitle title={`${t("policies")} (${d.policies.length})`} />
      <Card>
        {d.policies.length === 0 ? none(t("policiesEmpty")) : null}
        {d.policies.map((p) => (
          <FlowRow
            key={p.id}
            icon={FileText}
            title={`${p.policy_number ?? t("pdPolicy")} · ${p.carrier_name}`}
            subtitle={`${money(p.premium_minor)} · ${shortDate(p.coverage_ends_at)}`}
            status={td(`policyStatus_${p.status}`, humanize(p.status))}
            onPress={() => router.push(`${base}/policies/${p.id}` as Href)}
          />
        ))}
      </Card>
      <SectionTitle title={`${t("ptProposals")} (${d.proposals.length})`} />
      <Card>
        {d.proposals.length === 0 ? none(t("ptNoProposals")) : null}
        {d.proposals.map((p) => (
          <FlowRow
            key={p.id}
            icon={FileSignature}
            title={p.proposal_number}
            subtitle={[p.carrier_name, p.total_minor != null ? money(p.total_minor) : null].filter(Boolean).join(" · ")}
            status={humanize(p.status)}
            onPress={() => router.push(`${base}/proposals` as Href)}
          />
        ))}
      </Card>
      <SectionTitle title={`${t("claims")} (${d.claims.length})`} />
      <Card>
        {d.claims.length === 0 ? none(t("pdNoClaimsBook")) : null}
        {d.claims.map((c) => (
          <FlowRow
            key={c.id}
            icon={ShieldAlert}
            title={c.claim_number}
            subtitle={[c.policy_number, shortDate(c.submitted_at)].filter(Boolean).join(" · ")}
            status={td(`claimStatus_${c.status}`, humanize(c.status))}
            onPress={() => router.push(`${base}/claims/${c.id}` as Href)}
          />
        ))}
      </Card>
    </>
  );
}
