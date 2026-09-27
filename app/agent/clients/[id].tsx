import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { allowedAction } from "@/lib/capabilities";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { Customer360Panel } from "@/components/crm/Customer360Panel";
import { ClientDocumentsCard } from "@/components/partner/ClientDocumentsCard";
import { AgentWorkspaceApi } from "@/api/partner";
import { ClientRelatedRecords } from "@/components/partner/ClientRelatedRecords";
export default function AgentClientDetail() {
  const { t } = useTranslation();
  const { id, partyId } = useLocalSearchParams<{ id: string; partyId?: string }>();
  const q = useLoad(() => AgentApi.client(id), [id]);
  const x = q.data;
  return (
    <Screen>
      <AppHeader title={x?.full_name ?? t("agClient")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            <StatusChip label={x?.kyc_status ?? "LOADING"} tone="info" />
            <Text>{x?.phone_e164}</Text>
            <Text>{x?.city}</Text>
            <Text>
              {x?.origin_locked
                ? t("agOriginProtectedClient")
                : t("agOwnershipAwaiting")}
            </Text>
            <Text>Active policies: {x?.active_policies ?? 0}</Text>
            <Text>Renewal due: {x?.renewal_due_at ?? t("agNone")}</Text>
          </Card>
          <ClientRelatedRecords
            customerId={id}
            base="/agent"
            loadPolicies={AgentWorkspaceApi.policies}
            loadProposals={AgentWorkspaceApi.proposals}
            loadClaims={AgentWorkspaceApi.claims}
          />
          <Customer360Panel partyId={x?.party_id ?? partyId ?? null} />
          <ClientDocumentsCard customerId={id} load={AgentWorkspaceApi.clientDocuments} />
          {/* allowed_actions (when sent) must list create_quote. */}
          {allowedAction(x, "create_quote", true) ? (
            <Button
              label={t("agStartAssistedSale")}
              onPress={() => router.push(`/agent/sales/new?customerId=${id}`)}
            />
          ) : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
