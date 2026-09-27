import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { FileCheck2, FileSignature, HandCoins, XCircle } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { DetailActions, DetailHistory, DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { CarrierGate, usePermission } from "@/components/carrier/CarrierGate";
import { OperationsList } from "@/components/OperationsList";
import { Card, SectionTitle, TextField } from "@/components/ui";
import { CarrierApi } from "@/api/client";
import { CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { proposalStatusInfo } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const ISSUANCE_PENDING = ["REQUESTED", "CARRIER_REVIEW", "PENDING"];

/**
 * Issuance Detail (CAR-005, ISS-003). The decision happens here, next to the
 * proposal, customer, product, premium and payment-verification context, with
 * an explicit confirmation and step-up when the server asks for it. The
 * policy number is always allocated by the server. Authority (delegated or
 * maker-checker) is enforced server-side: when the API returns
 * `capabilities`, only those actions show; otherwise the route permission is
 * mirrored and the server still decides.
 */
export default function CarrierIssuanceDetail() {
  return (
    <CarrierGate module="issuance">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const canDecide = usePermission("carrier.referrals.decide");
  const [carrierReference, setCarrierReference] = useState("");
  const [reason, setReason] = useState("");
  const [issued, setIssued] = useState<string | null>(null);
  const q = useLoad(async () => {
    const [queue, proposals, payments] = await Promise.all([
      CarrierApi.issuance(),
      CarrierWorkspaceApi.proposals().catch(() => []),
      CarrierWorkspaceApi.payments().catch(() => null),
    ]);
    const item = queue.find((x) => x.id === id) ?? null;
    if (!item) return null;
    return {
      item,
      proposal: proposals.find((p) => p.reference === item.reference) ?? null,
      payments: (payments?.items ?? []).filter((p) => p.proposal_number === item.reference),
    };
  }, [id]);

  return (
    <DetailScreen
      title={t("cdIssuanceTitle")}
      subtitle={(d) => d?.item.reference}
      query={q}
      loadingLabel={t("caLoadingIssuance")}
      isMissing={(d) => d === null}
    >
      {(d) => {
        const { item, proposal, payments } = d!;
        const pending = ISSUANCE_PENDING.includes(item.status);
        const caps = item.capabilities;
        const allowed = (key: string) => (caps ? caps.includes(key) : pending && canDecide);
        const paid = payments.some((p) => p.status === "SUCCEEDED");
        return (
          <>
            <DetailSection
              title={t("cdSummary")}
              rows={[
                [t("cdReference"), item.reference],
                [t("cdStatus"), humanize(item.status)],
                [t("cdStage"), item.stage ? humanize(item.stage) : null],
                [t("cdSubject"), item.subject],
                [t("cdInsurer"), item.carrier_name ?? null],
                [t("cdSubmitted"), shortDate(item.submitted_at)],
                [t("cdPolicyNumber"), issued],
              ]}
            />
            {proposal ? (
              <>
                <DetailSection
                  title={t("cdProposalContext")}
                  rows={[
                    [t("cdCustomer"), proposal.customer_name],
                    [t("cdProduct"), proposal.product],
                    [t("cdLine"), proposal.line_code],
                    [t("cdStatus"), proposalStatusInfo(proposal.status, language).label],
                    [t("cdTotalPremium"), money(proposal.premium_minor)],
                  ]}
                />
                <OperationsList
                  icon={FileSignature}
                  onPress={(pid) => router.push(`/carrier/proposals/${pid}` as never)}
                  rows={[{ id: proposal.id, title: t("cdOpenProposal"), subtitle: proposal.reference, status: "" }]}
                />
              </>
            ) : (
              <UnavailableSection title={t("cdProposalContext")} />
            )}
            <SectionTitle title={t("cdPaymentVerification")} />
            {payments.length ? (
              <OperationsList
                icon={HandCoins}
                onPress={(pid) => router.push(`/carrier/payments/${pid}` as never)}
                rows={payments.map((x) => ({
                  id: x.id,
                  title: `${money(x.amount_minor)} · ${x.provider}`,
                  subtitle: `${humanize(x.reconciliation_status)} · ${shortDate(x.created_at)}`,
                  status: x.status,
                }))}
              />
            ) : (
              <Card>
                <Text style={s.warn}>{t("cdNoVerifiedPayment")}</Text>
              </Card>
            )}
            {pending && !paid && payments.length ? <Text style={s.warn}>{t("cdPaymentNotSucceeded")}</Text> : null}
            <DetailHistory
              title={t("cdApprovals")}
              items={(item.approvals ?? []).map((a, i) => ({
                key: `${a.role}-${i}`,
                when: a.decided_at,
                text: `${humanize(a.role)} · ${humanize(a.decision)}`,
                by: a.actor_name ?? null,
              }))}
            />
            <UnavailableSection title={t("cdCoverDocsWording")} />
            {pending && (allowed("approve") || allowed("reject")) ? (
              <>
                <SectionTitle title={t("cdDecision")} />
                <Card>
                  <Text style={s.meta}>{t("caPolicyNumberByServer")}</Text>
                  {allowed("approve") ? (
                    <TextField
                      label={t("caCarrierReferenceOptional")}
                      value={carrierReference}
                      onChangeText={setCarrierReference}
                      autoCapitalize="characters"
                      maxLength={64}
                    />
                  ) : null}
                  {allowed("reject") ? (
                    <TextField label={t("caReasonRejection")} value={reason} onChangeText={setReason} multiline />
                  ) : null}
                </Card>
              </>
            ) : null}
            {pending ? (
              <DetailActions
                actions={[
                  {
                    key: "approve",
                    label: t("caApproveIssue"),
                    icon: FileCheck2,
                    allowed: allowed("approve"),
                    confirm: t("cdConfirmIssue", { reference: item.reference }),
                    stepUpPurpose: "carrier_issuance",
                    run: async () => {
                      const r = await CarrierWorkspaceApi.approveIssuance(
                        item.id,
                        carrierReference.trim() ? { carrier_reference: carrierReference.trim() } : {},
                      );
                      setIssued(r.policy_number);
                      await q.reload();
                      return t("caPolicyIssued", { number: r.policy_number });
                    },
                  },
                  {
                    key: "reject",
                    label: t("settleReject"),
                    icon: XCircle,
                    variant: "danger",
                    allowed: allowed("reject"),
                    disabled: reason.trim().length < 5,
                    confirm: t("cdConfirmReject", { reference: item.reference }),
                    stepUpPurpose: "carrier_issuance",
                    run: async () => {
                      await CarrierWorkspaceApi.rejectIssuance(item.id, reason.trim());
                      await q.reload();
                    },
                    successMessage: t("caIssuanceRejected"),
                  },
                ]}
              />
            ) : null}
          </>
        );
      }}
    </DetailScreen>
  );
}

const s = StyleSheet.create({
  meta: { ...type.meta, color: colors.neutral600 },
  warn: { ...type.body, color: colors.warningText },
});
