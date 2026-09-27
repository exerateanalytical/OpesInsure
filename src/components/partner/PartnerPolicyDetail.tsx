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
