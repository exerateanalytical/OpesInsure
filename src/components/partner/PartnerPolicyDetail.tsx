import React from "react";
import { Text } from "react-native";
import { Href, router } from "expo-router";
import { ContactRound, RefreshCw, ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Button, Card, Money, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { ClientDocumentsCard } from "./ClientDocumentsCard";
import { SaleCommissionCard } from "./SaleCommission";
import type { CommissionRow } from "./commissionFilters";
import { ClientDocument, PartnerClaim, PartnerPolicy, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import { StyleSheet, View } from "react-native";
import { FileText } from "lucide-react-native";
import { AgentButton, AgentCard, AgentNavRow, AgentSection, AgentShell } from "@/components/agent";
import { agentColors as ac, agentType as aT } from "@/theme/agent";
import { KV } from "./AgentEarningsUi";
import { AgentListRow, AgentRawChip } from "./AgentListUi";
import { BookLoad, BookNote } from "./AgentBookUi";

const DAY = 86_400_000;
export const daysUntil = (iso?: string | null) => (iso ? Math.ceil((Date.parse(iso) - Date.now()) / DAY) : null);

/**
 * Role-aware book policy detail (AGT-002), shared by agent and broker. Data
 * comes from the caller's server-scoped book lists; a policy outside the book
 * is simply not found. Actions are assistance only (FNOL, renewal requote) —
 * never adjudication or issuance.
 */
export function PartnerPolicyDetail({
  id,
  base,
  loadPolicies,
  loadClaims,
  loadDocuments,
  canAssist = false,
  loadCommissions,
  canReportClaim = false,
  variant = "default",
}: {
  id: string;
  /** "/agent" or "/broker" — detail links stay inside the portal. */
  base: string;
  loadPolicies: () => Promise<PartnerPolicy[]>;
  loadClaims: () => Promise<PartnerClaim[]>;
  loadDocuments: (customerId: string) => Promise<ClientDocument[]>;
  /** Show FNOL assistance / renewal requote buttons (agent.clients.manage holders). */
  canAssist?: boolean;
  /** Commission ledger of the caller: shows "You earned" for this sale (server accruals joined by policy id). */
  loadCommissions?: () => Promise<CommissionRow[]>;
  /** Show "Report a claim" even without party_id on the row (the claim form resolves the policyholder). */
  canReportClaim?: boolean;
  /** "agent" = Commercial Agent spec v2 drill-down; broker keeps "default". */
  variant?: "default" | "agent";
}) {
  const { t, td } = useTranslation();
  const q = useLoad(async () => {
    const [policies, claims] = await Promise.all([loadPolicies(), loadClaims().catch(() => [] as PartnerClaim[])]);
    return { policy: policies.find((p) => p.id === id) ?? null, claims: claims.filter((c) => c.policy_id === id) };
  }, [id]);
  // Separate load: a missing finance permission or a failed ledger never hides the policy.
  const commissions = useLoad(async () => (loadCommissions ? await loadCommissions().catch(() => null) : null), [id]);
  const p = q.data?.policy;
  const days = daysUntil(p?.coverage_ends_at);
  const renewable = !!p && ["ACTIVE", "EXPIRING"].includes(p.status) && days !== null && days <= 60;
  if (variant === "agent") {
    return (
      <AgentShell variant="drilldown" title={t("pdPolicy")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
        <BookLoad q={q} icon={FileText} rows={5} emptyTitle={t("pdNotFound")} emptyBody={t("pdNotFoundBody")} isEmpty={(d) => !d.policy}>
          {(d) => {
            const pol = d.policy!;
            const canClaim = ((canAssist && pol.party_id) || canReportClaim) && pol.status === "ACTIVE";
            return (
              <>
                <AgentCard>
                  <View style={a.hero}>
                    <AgentRawChip raw={pol.status} label={td(`policyStatus_${pol.status}`, humanize(pol.status))} />
                    <Text style={a.amount} numberOfLines={1} adjustsFontSizeToFit>{money(pol.premium_minor)}</Text>
                    <Text style={a.heroMeta}>{[pol.policy_number, pol.customer_name].filter(Boolean).join(" · ")}</Text>
                  </View>
                  <KV label={t("pcInsurer")} value={pol.carrier_name} />
                  <KV label={t("pcProduct")} value={pol.line_code ? td(`line_${pol.line_code}`, humanize(pol.line_code)) : null} />
                  <KV label={t("pdStarts")} value={shortDate(pol.coverage_starts_at)} />
                  <KV label={t("pdEnds")} value={shortDate(pol.coverage_ends_at)} />
                  <KV label={t("pdIssued")} value={shortDate(pol.issued_at)} />
                </AgentCard>
                <SaleCommissionCard
                  rows={commissions.data}
                  sale={{ policyId: pol.id, premiumMinor: pol.premium_minor }}
                  onOpen={(accrualId) => router.push(`${base}/commissions/${accrualId}` as Href)}
                />
                {pol.customer_id ? (
                  <AgentCard padded={false}>
                    <AgentNavRow
                      divider={false}
                      icon={ContactRound}
                      title={pol.customer_name}
                      subtitle={t("pdOpenCustomer")}
                      onPress={() => router.push(`${base}/clients/${pol.customer_id}` as Href)}
                    />
                  </AgentCard>
                ) : null}
                <AgentSection title={t("pdRenewal")}>
                  <AgentCard style={a.gap}>
                    <Text style={a.body}>
                      {days === null ? t("pdNoEndDate") : days < 0 ? t("pdExpiredAgo", { days: -days }) : t("pdDaysLeft", { days })}
                    </Text>
                    {renewable && canAssist && pol.customer_id ? (
                      <>
                        <AgentButton
                          icon={RefreshCw}
                          label={t("pdStartRenewal")}
                          onPress={() => router.push(`${base}/sales/new?customerId=${pol.customer_id}&renewalOf=${pol.id}` as Href)}
                        />
                        <Text style={a.meta}>{t("pdStartRenewalHint")}</Text>
                      </>
                    ) : null}
                  </AgentCard>
                </AgentSection>
                <AgentSection title={t("claims")}>
                  <AgentCard padded={false}>
                    {d.claims.length === 0 ? <BookNote>{t("pdNoClaims")}</BookNote> : null}
                    {d.claims.map((c, i) => (
                      <AgentListRow
                        key={c.id}
                        first={i === 0}
                        icon={ShieldAlert}
                        title={c.claim_number}
                        subtitle={shortDate(c.submitted_at)}
                        amount={c.estimated_loss_minor != null ? money(c.estimated_loss_minor) : null}
                        status={c.status}
                        statusLabel={td(`claimStatus_${c.status}`, humanize(c.status))}
                        onPress={() => router.push(`${base}/claims/${c.id}` as Href)}
                      />
                    ))}
                  </AgentCard>
                  {canClaim ? (
                    <AgentButton
                      variant="secondary"
                      icon={ShieldAlert}
                      label={t("pdAssistClaim")}
                      onPress={() => router.push(`${base}/claims/new?policyId=${pol.id}` as Href)}
                    />
                  ) : null}
                </AgentSection>
                {pol.customer_id ? (
                  <ClientDocumentsCard variant="agent" customerId={pol.customer_id} policyId={pol.id} load={loadDocuments} />
                ) : null}
                <Text style={a.meta}>{t("pdPaymentNotice")}</Text>
              </>
            );
          }}
        </BookLoad>
      </AgentShell>
    );
  }
  return (
    <Screen>
      <AppHeader title={p?.policy_number ?? t("pdPolicy")} subtitle={p?.customer_name} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("policiesLoading")}>
        {(d) =>
          !d.policy ? (
            <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />
          ) : (
            <>
              <Card feature>
                <StatusChip label={td(`policyStatus_${d.policy.status}`, humanize(d.policy.status))} tone={d.policy.status === "ACTIVE" ? "success" : "info"} />
                <Money amount={d.policy.premium_minor / 100} size="large" />
                <DetailRow label={t("pcInsurer")} value={d.policy.carrier_name} />
                <DetailRow label={t("pcProduct")} value={d.policy.line_code ? td(`line_${d.policy.line_code}`, humanize(d.policy.line_code)) : null} />
                <DetailRow label={t("pdStarts")} value={shortDate(d.policy.coverage_starts_at)} />
                <DetailRow label={t("pdEnds")} value={shortDate(d.policy.coverage_ends_at)} />
                <DetailRow label={t("pdIssued")} value={shortDate(d.policy.issued_at)} />
              </Card>
              <SaleCommissionCard
                rows={commissions.data}
                sale={{ policyId: d.policy.id, premiumMinor: d.policy.premium_minor }}
                onOpen={(accrualId) => router.push(`${base}/commissions/${accrualId}` as Href)}
              />
              {d.policy.customer_id ? (
                <FlowRow
                  icon={ContactRound}
                  title={d.policy.customer_name}
                  subtitle={t("pdOpenCustomer")}
                  onPress={() => router.push(`${base}/clients/${d.policy!.customer_id}` as Href)}
                />
              ) : null}
              <SectionTitle title={t("pdRenewal")} />
              <Card>
                <Text style={{ ...type.body, color: colors.neutral700 }}>
                  {days === null ? t("pdNoEndDate") : days < 0 ? t("pdExpiredAgo", { days: -days }) : t("pdDaysLeft", { days })}
                </Text>
                {renewable && canAssist && d.policy.customer_id ? (
                  <Button
                    icon={RefreshCw}
                    label={t("pdStartRenewal")}
                    hint={t("pdStartRenewalHint")}
                    onPress={() => router.push(`${base}/sales/new?customerId=${d.policy!.customer_id}&renewalOf=${d.policy!.id}` as Href)}
                  />
                ) : null}
              </Card>
              <SectionTitle title={t("claims")} />
              <Card>
                {d.claims.length === 0 ? <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("pdNoClaims")}</Text> : null}
                {d.claims.map((c) => (
                  <FlowRow
                    key={c.id}
                    icon={ShieldAlert}
                    title={c.claim_number}
                    subtitle={[c.estimated_loss_minor != null ? money(c.estimated_loss_minor) : null, shortDate(c.submitted_at)].filter(Boolean).join(" · ")}
                    status={td(`claimStatus_${c.status}`, humanize(c.status))}
                    onPress={() => router.push(`${base}/claims/${c.id}` as Href)}
                  />
                ))}
                {((canAssist && d.policy.party_id) || canReportClaim) && d.policy.status === "ACTIVE" ? (
                  <Button
                    variant="secondary"
                    icon={ShieldAlert}
                    label={t("pdAssistClaim")}
                    onPress={() => router.push(`${base}/claims/new?policyId=${d.policy!.id}` as Href)}
                  />
                ) : null}
              </Card>
              {d.policy.customer_id ? (
                <ClientDocumentsCard customerId={d.policy.customer_id} policyId={d.policy.id} load={loadDocuments} />
              ) : null}
              <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("pdPaymentNotice")}</Text>
            </>
          )
        }
      </StatePanel>
    </Screen>
  );
}

const a = StyleSheet.create({
  hero: { gap: 6, paddingBottom: 12 },
  amount: { ...aT.heroAmount, color: ac.heading },
  heroMeta: { ...aT.secondary, color: ac.secondary },
  gap: { gap: 12 },
  body: { ...aT.body, color: ac.text },
  meta: { ...aT.caption, color: ac.secondary },
});
