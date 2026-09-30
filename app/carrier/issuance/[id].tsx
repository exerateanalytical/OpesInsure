import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { BadgeCheck, FileCheck2, FilePen, FileSignature, HandCoins, ShieldCheck, XCircle } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { DetailActions, DetailHistory, DetailScreen, DetailSection, UnavailableSection, type DetailAction } from "@/components/detail";
import { CarrierGate, usePermission } from "@/components/carrier/CarrierGate";
import { OperationsList } from "@/components/OperationsList";
import { Card, SectionTitle, TextField } from "@/components/ui";
import { ApiError } from "@/api/client";
import { CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { ISSUANCE_PENDING, issuanceActions, type IssuanceAction } from "@/lib/carrierDecisions";
import { proposalStatusInfo } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * Issuance Detail (CAR-005, ISS-003). The decision happens here, next to the
 * proposal, customer, product, premium and payment-verification context, with
 * an explicit confirmation and step-up when the server asks for it. The
 * policy number is always allocated by the server. Maker-checker (verify,
 * request correction, approve, second approval, reject) follows the
 * `capabilities` GET mobile/carrier/issuance/{id} returns for this user; the
 * server re-checks every action.
 */
export default function CarrierIssuanceDetail() {
  return (
    <CarrierGate module="issuance">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t, td, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const canDecide = usePermission("carrier.referrals.decide");
  const [carrierReference, setCarrierReference] = useState("");
  const [reason, setReason] = useState("");
  const [issued, setIssued] = useState<string | null>(null);
  const q = useLoad(async () => {
    const [item, proposals, payments] = await Promise.all([
      CarrierWorkspaceApi.issuance(String(id)).catch((e: unknown) => {
        if (e instanceof ApiError && e.status === 404) return null;
        throw e;
      }),
      CarrierWorkspaceApi.proposals().catch(() => []),
      CarrierWorkspaceApi.payments().catch(() => null),
    ]);
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
        const actions = issuanceActions(item, canDecide);
        const allowed = (key: IssuanceAction) => actions.includes(key);
        const paid = item.payment?.status === "SUCCEEDED" || payments.some((p) => p.status === "SUCCEEDED");
        const reference = carrierReference.trim() ? { carrier_reference: carrierReference.trim() } : {};
        const reasonReady = reason.trim().length >= 5;
        const after = async () => {
          setReason("");
          await q.reload();
        };
        const all: Record<IssuanceAction, DetailAction> = {
          verify: {
            key: "verify",
            label: t("cdIssVerify"),
            icon: ShieldCheck,
            variant: "secondary",
            allowed: allowed("verify"),
            confirm: t("cdIssConfirmVerify", { reference: item.reference }),
            run: async () => {
              await CarrierWorkspaceApi.verifyIssuance(item.id, reason.trim() || undefined);
              await after();
            },
            successMessage: t("cdIssVerified"),
          },
          request_correction: {
            key: "request_correction",
            label: t("cdIssRequestCorrection"),
            icon: FilePen,
            variant: "secondary",
            allowed: allowed("request_correction"),
            disabled: !reasonReady,
            confirm: t("cdIssConfirmCorrection", { reference: item.reference }),
            run: async () => {
              await CarrierWorkspaceApi.requestIssuanceCorrection(item.id, reason.trim());
              await after();
            },
            successMessage: t("cdIssCorrectionRequested"),
          },
          approve: {
            key: "approve",
            label: t("caApproveIssue"),
            icon: FileCheck2,
            allowed: allowed("approve"),
            confirm: t("cdConfirmIssue", { reference: item.reference }),
            stepUpPurpose: "carrier_issuance",
            run: async () => {
              const r = await CarrierWorkspaceApi.approveIssuance(item.id, reference);
              setIssued(r.policy_number);
              await q.reload();
              return t("caPolicyIssued", { number: r.policy_number });
            },
          },
          second_approve: {
            key: "second_approve",
            label: t("cdIssSecondApprove"),
            icon: BadgeCheck,
            allowed: allowed("second_approve"),
            confirm: t("cdConfirmIssue", { reference: item.reference }),
            stepUpPurpose: "carrier_issuance",
            run: async () => {
              const r = await CarrierWorkspaceApi.secondApproveIssuance(item.id, reference);
              setIssued(r.policy_number);
              await q.reload();
              return t("caPolicyIssued", { number: r.policy_number });
            },
          },
          reject: {
            key: "reject",
            label: t("settleReject"),
            icon: XCircle,
            variant: "danger",
            allowed: allowed("reject"),
            disabled: !reasonReady,
            confirm: t("cdConfirmReject", { reference: item.reference }),
            stepUpPurpose: "carrier_issuance",
            run: async () => {
              await CarrierWorkspaceApi.rejectIssuance(item.id, reason.trim());
              await after();
            },
            successMessage: t("caIssuanceRejected"),
          },
        };
        const needsReference = allowed("approve") || allowed("second_approve");
        const needsReason = allowed("reject") || allowed("request_correction") || allowed("verify");
        return (
          <>
            <DetailSection
              title={t("cdSummary")}
              rows={[
                [t("cdReference"), item.reference],
                [t("cdStatus"), td(`issStatus_${item.status}`, item.status)],
                [t("cdStage"), item.stage ? td(`issStage_${item.stage}`, item.stage) : null],
                [t("cdCustomer"), item.customer ?? null],
                [t("cdProduct"), item.product ?? null],
                [t("cdTotalPremium"), item.premium ? money(item.premium.amount_minor) : null],
                [t("cdInsurer"), item.carrier_name ?? null],
                [t("cdSubmitted"), shortDate(item.submitted_at)],
                [t("cdCarrierReference"), item.carrier_reference ?? null],
                [t("cdIssCorrectionReason"), item.correction_reason ?? null],
                [t("cdRejectionReason"), item.rejection_reason ?? null],
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
            ) : item.payment ? (
              <Card>
                <Text style={s.meta}>
                  {td(`payStatus_${item.payment.status}`, item.payment.status)}
                  {item.payment.provider_reference ? ` · ${item.payment.provider_reference}` : ""}
                </Text>
                {!item.payment.verified ? <Text style={s.warn}>{t("cdNoVerifiedPayment")}</Text> : null}
              </Card>
            ) : (
              <Card>
                <Text style={s.warn}>{t("cdNoVerifiedPayment")}</Text>
              </Card>
            )}
            {pending && !paid && payments.length ? <Text style={s.warn}>{t("cdPaymentNotSucceeded")}</Text> : null}
            <DetailHistory
              title={t("cdApprovals")}
              items={(item.approvals ?? []).map((a, i) => ({
                key: `${a.step}-${i}`,
                when: a.at,
                text: td(`issStep_${a.step}`, a.step),
                by: a.name ?? null,
              }))}
            />
            <UnavailableSection title={t("cdCoverDocsWording")} />
            {actions.length && (needsReference || needsReason) ? (
              <>
                <SectionTitle title={t("cdDecision")} />
                <Card>
                  <Text style={s.meta}>{t("caPolicyNumberByServer")}</Text>
                  {needsReference ? (
                    <TextField
                      label={t("caCarrierReferenceOptional")}
                      value={carrierReference}
                      onChangeText={setCarrierReference}
                      autoCapitalize="characters"
                      maxLength={64}
                    />
                  ) : null}
                  {needsReason ? (
                    <TextField label={t("cdIssReasonLabel")} value={reason} onChangeText={setReason} multiline maxLength={2000} />
                  ) : null}
                </Card>
              </>
            ) : null}
            {actions.length ? <DetailActions actions={actions.map((a) => all[a])} /> : null}
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
