import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { ReceiptText, RefreshCw, ShieldAlert, ShieldPlus, UserPlus } from "lucide-react-native";
import { usePermission } from "@/components/carrier/CarrierGate";
import { AppHeader, Card, Money, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { Customer360Panel } from "@/components/crm/Customer360Panel";
import { ClientDocumentsCard } from "@/components/partner/ClientDocumentsCard";
import { ClientRelatedRecords } from "@/components/partner/ClientRelatedRecords";
import { WorkspaceMenu } from "@/components/portal/Workspace";
import { UnavailableSection } from "@/components/detail";
import { BrokerWorkspaceApi, shortDate } from "@/api/partner";

/** BRK-001 broker client detail: portfolio, related records and next actions. */
export default function BrokerClientDetail() {
  const { t } = useTranslation();
  const { id, partyId } = useLocalSearchParams<{ id: string; partyId?: string }>();
  const q = useLoad(() => BrokerApi.client(id), [id]);
  const canReportClaim = usePermission("broker.claims.file");
  const x = q.data;
  const name = encodeURIComponent(x?.full_name ?? "");
  return (
    <Screen>
      <AppHeader title={x?.full_name ?? t("agClient")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            <StatusChip
              label={x?.origin_locked ? t("brOriginLockedBroker") : t("fltOriginOpen")}
              tone={x?.origin_locked ? "success" : "neutral"}
            />
            <Text>
              {[x?.phone_e164, x?.city].filter(Boolean).join(" · ")}
            </Text>
            <Text>{t("bkPolicyCount", { count: x?.policies ?? 0 })}</Text>
            <Text>{t("brOutstandingBalance")}</Text>
            {x ? <Money amount={x.outstanding_minor / 100} size="large" /> : null}
            <Text>{t("bkNextRenewal", { date: x?.renewal_due_at ? shortDate(x.renewal_due_at) : t("agNone") })}</Text>
          </Card>
          <SectionTitle title={t("bkNextActions")} />
          <WorkspaceMenu
            items={[
              { label: t("leadNewTitle"), subtitle: t("brLeadsSubtitle"), icon: UserPlus, href: "/broker/leads/new" },
              { label: t("claims"), subtitle: t("brClaimsOnBook"), icon: ShieldAlert, href: `/broker/claims?q=${name}` },
              ...(canReportClaim ? [{ label: t("brReportClaim"), subtitle: t("pdFileClaimHint"), icon: ShieldPlus, href: `/broker/claims/new?customerId=${id}` }] : []),
              { label: t("brRenewals"), subtitle: t("agPoliciesDueSoon"), icon: RefreshCw, href: "/broker/renewals" },
              { label: t("brReceivables"), subtitle: t("brAmountsDue"), icon: ReceiptText, href: "/broker/receivables" },
            ]}
          />
          <UnavailableSection title={t("bkNewQuote")} message={t("bkAssistedQuotePending")} />
          <ClientRelatedRecords
            customerId={id}
            base="/broker"
            loadPolicies={BrokerWorkspaceApi.policies}
            loadProposals={BrokerWorkspaceApi.proposals}
            loadClaims={BrokerWorkspaceApi.claims}
          />
          <Customer360Panel partyId={x?.party_id ?? partyId ?? null} />
          <ClientDocumentsCard customerId={id} load={BrokerWorkspaceApi.clientDocuments} />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
