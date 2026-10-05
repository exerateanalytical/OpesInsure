import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Building2, FileText, Hash, Pencil, ShieldAlert, Smartphone, Wallet } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, DetailRow, SectionHeading } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { SelectField } from "@/components/forms/SelectField";
import { SettlementHero } from "@/components/claims/SettlementHero";
import { SettlementFlowsApi, type SettlementDetail } from "@/api/customerFlows";
import { canSetPayout, payoutPayload } from "@/lib/customerFlows";
import { handleStepUpRequired } from "@/security/step-up";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

type Method = "MOBILE_MONEY" | "BANK_TRANSFER";

/**
 * Settlement payment tracking: payment status, net amount and reference, where the
 * money goes (PUT settlement/payout — mobile money or bank, editable until the payment
 * is requested; the server keeps it masked in answers and alerts the account owner)
 * and the payment advice PDF once the insurer has issued one.
 */
export default function SettlementPayment() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const { data, setData, loading, error, reload } = useLoad(() => SettlementFlowsApi.get(id), [id]);
  const [editing, setEditing] = useState(false);
  const [method, setMethod] = useState<Method>("MOBILE_MONEY");
  const [operator, setOperator] = useState<string>("MTN");
  const [msisdn, setMsisdn] = useState("");
  const [bankName, setBankName] = useState("");
  const [accountName, setAccountName] = useState("");
  const [accountNumber, setAccountNumber] = useState("");
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<unknown>(null);
  const [saved, setSaved] = useState(false);
  const payload = payoutPayload({ method, operator, msisdn, bankName, accountName, accountNumber });

  const save = async () => {
    if (!payload) return;
    setBusy(true);
    setSaveError(null);
    setSaved(false);
    try {
      setData(await SettlementFlowsApi.setPayout(id, payload));
      setEditing(false);
      setSaved(true);
      setMsisdn("");
      setAccountNumber("");
    } catch (e) {
      if (!handleStepUpRequired(e, "PAYOUT_DESTINATION_CHANGE", `/claim/${id}/settlement-payment`)) setSaveError(e);
    } finally {
      setBusy(false);
    }
  };

  const payoutCard = (x: SettlementDetail) => {
    const p = x.payout;
    const editable = canSetPayout(x);
    return (
      <Card style={s.card}>
        <SectionHeading
          title={t("payoutTitle")}
          icon={Wallet}
          action={editable && !editing ? (p ? t("payoutChange") : t("payoutAdd")) : undefined}
          onAction={() => (setSaved(false), setEditing(true))}
        />
        {p ? (
          p.method === "MOBILE_MONEY" ? (
            <DetailRow icon={Smartphone} label={td(`payoutOperator_${p.operator ?? ""}`, p.operator ?? "")} value={p.msisdn_masked} />
          ) : (
            <>
              <DetailRow icon={Building2} label={p.bank_name ?? t("payoutBank")} value={p.account_number_masked} />
              {p.account_name ? <Text style={s.meta}>{p.account_name}</Text> : null}
            </>
          )
        ) : (
          <Text style={s.body}>{editable ? t("payoutNone") : t("payoutNoneLocked")}</Text>
        )}
        {p?.updated_at ? <Text style={s.meta}>{t("payoutUpdated", { date: f.dateTime(p.updated_at) })}</Text> : null}
        {saved ? <StatusChip label={t("payoutSaved")} tone="success" /> : null}
        {editing && editable ? (
          <View style={s.form}>
            <SelectField
              label={t("payoutMethod")}
              value={method}
              onChange={(v) => setMethod(v as Method)}
              options={[
                { value: "MOBILE_MONEY", label: t("payoutMobileMoney") },
                { value: "BANK_TRANSFER", label: t("payoutBankTransfer") },
              ]}
            />
            {method === "MOBILE_MONEY" ? (
              <>
                <SelectField
                  label={t("payoutOperator")}
                  value={operator}
                  onChange={setOperator}
                  options={[
                    { value: "MTN", label: t("payoutOperator_MTN") },
                    { value: "ORANGE", label: t("payoutOperator_ORANGE") },
                  ]}
                />
                <TextField label={t("payoutMsisdn")} value={msisdn} onChangeText={setMsisdn} keyboardType="phone-pad" autoComplete="off" hint={t("payoutMsisdnHint")} />
              </>
            ) : (
              <>
                <TextField label={t("payoutBank")} value={bankName} onChangeText={setBankName} maxLength={120} />
                <TextField label={t("payoutAccountName")} value={accountName} onChangeText={setAccountName} maxLength={160} />
                <TextField label={t("payoutAccountNumber")} value={accountNumber} onChangeText={setAccountNumber} autoCapitalize="characters" autoComplete="off" maxLength={48} hint={t("payoutAccountHint")} />
              </>
            )}
            <Banner icon={ShieldAlert} tint="gold" body={t("payoutCheck")} />
            <Button label={t("payoutSave")} loading={busy} disabled={!payload || busy} onPress={() => void save()} />
            <Button label={t("cancel")} variant="tertiary" onPress={() => setEditing(false)} />
            {saveError ? <ErrorCard error={saveError} fallback={t("errGeneric")} /> : null}
          </View>
        ) : null}
      </Card>
    );
  };

  return (
    <Screen>
      <BrandHeader title={t("settlePayTitle")} subtitle={t("settlePaySubtitle")} />
      <StatePanel loading={loading} error={error} data={data} onRetry={() => void reload()} isEmpty={(x) => !x} emptyTitle={t("settleNone")} emptyMessage={t("settleNoneBody")} loadingLabel={t("settleLoading")}>
        {(x) => !x ? null : (
          <>
            <SettlementHero settlement={x} />
            <Card>
              <View style={s.row}>
                <Text style={[s.title, s.flex]}>{t("settleTracking")}</Text>
                <StatusChip
                  label={x.payment_status ? td(`status_${x.payment_status}`, x.payment_status) : t("settlePayNotAvailable")}
                  tone={x.payment_status === "PAID" ? "success" : "warning"}
                />
              </View>
              <View style={s.row}>
                <Hash size={18} color={colors.navy800} />
                <Text style={[s.body, s.flex]}>{t("settlePayRef", { ref: x.payment_reference ?? t("settlePayRefPending") })}</Text>
              </View>
            </Card>
            {payoutCard(x)}
            {x.payment_advice_document_id ? (
              <Banner
                icon={FileText}
                tint="blue"
                title={t("payoutAdvice")}
                body={t("payoutAdviceBody")}
                onPress={() => router.push({ pathname: "/documents/[id]", params: { id: x.payment_advice_document_id as string } })}
              />
            ) : null}
            {(x.allowed_actions ?? []).includes("sign_discharge") ? (
              <Banner icon={Pencil} tint="gold" title={t("dchReviewSign")} body={t("dchReviewSignBody")} onPress={() => router.push(`/claim/${id}/discharge`)} />
            ) : null}
          </>
        )}
      </StatePanel>
      <Banner icon={ShieldAlert} tint="gold" body={t("settlePayWarning")} />
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  form: { gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy900 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
