import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text, View } from "react-native";
import { AlertTriangle, CreditCard, Eye, FileText, Flag, LifeBuoy, Link2, LucideIcon, Paperclip, ShieldAlert, Truck } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, RadioCard, SectionHeading, type Tint } from "@/components/design";
import { CustomerApi } from "@/api/customer";
import { WalletApi } from "@/api/client";
import { ChoiceChips } from "@/components/portal/Workspace";
import { SelectField } from "@/components/forms/SelectField";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const CATEGORIES = [
  "GENERAL_SUPPORT",
  "PAYMENT",
  "POLICY_DOCUMENT",
  "CLAIM",
  "DELIVERY",
  "FORMAL_COMPLAINT",
  "REPORT_FRAUD",
  "PRIVACY_REQUEST",
] as const;
const CATEGORY_ICON: Record<(typeof CATEGORIES)[number], [LucideIcon, Tint]> = {
  GENERAL_SUPPORT: [LifeBuoy, "blue"],
  PAYMENT: [CreditCard, "gold"],
  POLICY_DOCUMENT: [FileText, "blue"],
  CLAIM: [ShieldAlert, "gold"],
  DELIVERY: [Truck, "blue"],
  FORMAL_COMPLAINT: [Flag, "red"],
  REPORT_FRAUD: [AlertTriangle, "red"],
  PRIVACY_REQUEST: [Eye, "neutral"],
};

/**
 * New support case. Context params link the case to a claim / payment /
 * policy (claimId, paymentId, policyId + reference) and preselect the
 * category. The server has no link columns yet, so the reference is also
 * written into the description where staff will see it.
 */
export default function NewSupport() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{
    category?: string;
    claimId?: string;
    paymentId?: string;
    policyId?: string;
    reference?: string;
    subject?: string;
    body?: string;
  }>();
  const initial =
    CATEGORIES.find((c) => c === params.category) ??
    (params.claimId ? "CLAIM" : params.paymentId ? "PAYMENT" : "GENERAL_SUPPORT");
  const [category, setCategory] = useState<string>(initial);
  const [subject, setSubject] = useState(params.subject ?? (params.reference ? `${params.reference} — ` : ""));
  const [description, setDescription] = useState(params.body ?? "");
  const [busy, setBusy] = useState(false);
  const [policyId, setPolicyId] = useState<string | null>(null);
  const policies = useLoad(() => (params.policyId || params.claimId || params.paymentId ? Promise.resolve([]) : WalletApi.all(2)), []);
  const [error, setError] = useState<string | null>(null);
  const linked = params.claimId
    ? t("supportLinkedClaim", { ref: params.reference ?? params.claimId })
    : params.paymentId
      ? t("supportLinkedPayment", { ref: params.reference ?? params.paymentId })
      : params.policyId
        ? t("supportLinkedPolicy", { ref: params.reference ?? params.policyId })
        : null;

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const context = linked ? `\n\n[${linked}]` : "";
      const created = await CustomerApi.createSupportCase({
        category,
        subject: subject.trim(),
        description: `${description.trim()}${context}`,
        ...(params.claimId ? { claim_id: params.claimId } : {}),
        ...(params.paymentId ? { payment_id: params.paymentId } : {}),
        ...(params.policyId ? { policy_id: params.policyId } : policyId ? { policy_id: policyId } : {}),
      });
      router.replace({ pathname: "/support/[id]", params: { id: created.id } });
    } catch (e) {
      // The form keeps its content so the customer can retry.
      setError(e instanceof Error ? e.message : t("supportCreateFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen
      footer={
        <CtaBar>
          {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
          <Button
            label={category === "FORMAL_COMPLAINT" ? t("supportSubmitComplaint") : t("supportCreate")}
            loading={busy}
            disabled={subject.trim().length < 4 || description.trim().length < 15}
            onPress={() => void submit()}
          />
        </CtaBar>
      }
    >
      <BrandHeader title={t("supportNewTitle")} subtitle={t("supportNeverShare")} back right="help" />
      {linked ? <Banner icon={Link2} tint="blue" body={linked} /> : null}
      <Card style={s.card}>
        <SectionHeading title={t("supportCategory")} />
        <View style={s.wrap} accessibilityRole="radiogroup">
          {CATEGORIES.map((x) => {
            const [Icon, tint] = CATEGORY_ICON[x];
            return (
              <RadioCard key={x} selected={category === x} onPress={() => setCategory(x)} icon={Icon} tint={tint} title={td(`supportCategory_${x}`, x)} style={s.option} />
            );
          })}
        </View>
        {category === "PRIVACY_REQUEST" ? <StatusChip label={t("privacyRequestNote")} tone="info" /> : null}
      </Card>
      {policies.data?.length ? (
        <Card style={s.card}>
          <SectionHeading title={t("supportRelatedPolicy")} />
          {policies.data.length > 4 ? (
            // Long lists: a drop-down; the first row clears the link.
            <SelectField
              label={t("supportRelatedPolicy")}
              value={policyId ?? undefined}
              onChange={(v) => setPolicyId(v || null)}
              options={[{ value: "", label: t("mdNotChosen") }, ...policies.data.map((p) => ({ value: p.id, label: p.policy_number, subtitle: p.product_name ?? undefined }))]}
            />
          ) : (
            <ChoiceChips<string>
              label={t("supportRelatedPolicy")}
              value={policyId}
              onChange={(v) => setPolicyId(v === policyId ? null : v)}
              options={policies.data.map((p) => ({ value: p.id, label: `${p.policy_number}${p.product_name ? ` · ${p.product_name}` : ""}` }))}
            />
          )}
        </Card>
      ) : null}
      <Card style={s.card}>
        <TextField label={t("supportSubject")} maxLength={200} value={subject} onChangeText={setSubject} hint={t("minChars", { count: 4 })} />
        <TextField
          label={t("supportDescribe")}
          multiline
          value={description}
          onChangeText={setDescription}
          maxLength={10000}
          style={s.area}
          hint={t("minChars", { count: 15 })}
        />
        <Banner icon={Paperclip} tint="neutral" title={t("supportAttach")} body={t("supportAttachAfterBody")} />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature },
  wrap: { gap: space.x2 },
  option: { padding: space.x3 },
  area: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
  error: { ...type.meta, color: colors.dangerText },
});
