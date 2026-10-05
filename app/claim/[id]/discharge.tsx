import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { FileSignature, FileText, PenLine, ShieldCheck, XCircle } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ConsentRow, ErrorCard } from "@/components/purchase/PurchaseUi";
import { SettlementHero } from "@/components/claims/SettlementHero";
import { SettlementFlowsApi } from "@/api/customerFlows";
import { canSignDischarge, DISCHARGE_DECLINE_MIN } from "@/lib/customerFlows";
import { handleStepUpRequired } from "@/security/step-up";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Review & sign the settlement discharge (REQ-CLM-013, ACCEPTED → DISCHARGE_SIGNED).
 * The discharge PDF opens in the document viewer; signing needs the consent box and a
 * step-up (CLAIM_SETTLEMENT_DECISION), declining needs a reason. The server decides
 * whether the caller may sign (allowed_actions: sign_discharge).
 */
export default function Discharge() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => SettlementFlowsApi.get(id), [id]);
  const [consent, setConsent] = useState(false);
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState<"sign" | "decline" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const x = q.data;
  const signable = canSignDischarge(x);

  const run = async (kind: "sign" | "decline") => {
    setBusy(kind);
    setError(null);
    try {
      const next = kind === "sign" ? await SettlementFlowsApi.signDischarge(id) : await SettlementFlowsApi.declineDischarge(id, reason.trim());
      q.setData(next);
      setDeclining(false);
    } catch (e) {
      if (!(kind === "sign" && handleStepUpRequired(e, "CLAIM_SETTLEMENT_DECISION", `/claim/${id}/discharge`))) setError(e);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen
      footer={
        signable ? (
          <CtaBar>
            {declining ? (
              <>
                <Button label={t("dchDeclineConfirm")} variant="danger" icon={XCircle} loading={busy === "decline"} disabled={reason.trim().length < DISCHARGE_DECLINE_MIN || !!busy} onPress={() => void run("decline")} />
                <Button label={t("cancel")} variant="tertiary" onPress={() => setDeclining(false)} />
              </>
            ) : (
              <>
                <Button label={t("dchSign")} icon={PenLine} loading={busy === "sign"} disabled={!consent || !!busy} onPress={() => void run("sign")} />
                <Button label={t("dchDecline")} variant="secondary" disabled={!!busy} onPress={() => setDeclining(true)} />
              </>
            )}
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={t("dchTitle")} subtitle={t("dchSubtitle")} back right="help" />
      <StatePanel loading={q.loading} error={q.error} data={x === undefined ? undefined : x} onRetry={() => void q.reload()} isEmpty={(v) => !v || !v.discharge} emptyTitle={t("dchNone")} emptyMessage={t("dchNoneBody")} loadingLabel={t("settleLoading")}>
        {(v) =>
          !v || !v.discharge ? null : (
            <>
              <SettlementHero settlement={v} />
              <Card style={s.card}>
                <SectionHeading title={t("dchDocument")} icon={FileSignature} right={<StatusChip label={td(`dchStatus_${v.discharge.status}`, v.discharge.status)} tone={v.discharge.status === "COMPLETED" ? "success" : v.discharge.status === "DECLINED" ? "danger" : "warning"} />} />
                <Text style={s.body}>{t("dchDocumentBody")}</Text>
                {v.discharge.document_id ? (
                  <Button label={t("dchOpen")} icon={FileText} variant="secondary" onPress={() => router.push({ pathname: "/documents/[id]", params: { id: v.discharge!.document_id as string } })} />
                ) : null}
                {v.discharge.signed_at ? <Text style={s.meta}>{t("dchSignedOn", { date: f.dateTime(v.discharge.signed_at) })}</Text> : null}
                {v.discharge.declined_at ? <Text style={s.meta}>{t("dchDeclinedOn", { date: f.dateTime(v.discharge.declined_at) })}</Text> : null}
                {v.discharge.expires_at && v.discharge.status === "PENDING" ? <Text style={s.meta}>{t("dchExpires", { date: f.date(v.discharge.expires_at) })}</Text> : null}
              </Card>
              <Card style={s.card}>
                <SectionHeading title={t("dchConsent")} icon={ShieldCheck} />
                <Text style={s.consent}>{v.discharge.consent_text}</Text>
                {signable ? <ConsentRow checked={consent} onPress={() => setConsent((c) => !c)} label={t("dchConsentBox")} /> : null}
                {declining ? (
                  <TextField label={t("dchDeclineReason")} value={reason} onChangeText={setReason} multiline maxLength={2000} style={s.area} hint={t("minChars", { count: DISCHARGE_DECLINE_MIN })} />
                ) : null}
              </Card>
              {v.discharge.status === "COMPLETED" ? <Banner icon={ShieldCheck} tint="green" body={t("dchDoneBody")} onPress={() => router.push(`/claim/${id}/settlement-payment`)} /> : null}
              {v.discharge.status === "DECLINED" ? <Banner icon={XCircle} tint="gold" body={t("dchDeclinedBody")} /> : null}
              {error ? <ErrorCard error={error} fallback={t("errGeneric")} /> : null}
            </>
          )
        }
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: space.x3 },
  body: { ...type.body, color: colors.neutral700 },
  consent: { ...type.body, color: colors.navy950, backgroundColor: colors.neutral50, borderRadius: radius.control, padding: space.x3 },
  meta: { ...type.meta, color: colors.neutral600 },
  area: { minHeight: 100, textAlignVertical: "top", paddingTop: 12 },
});
