import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { Link2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { CustomerApi } from "@/api/customer";
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
        ...(params.policyId ? { policy_id: params.policyId } : {}),
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
    <Screen>
      <AppHeader title={t("supportNewTitle")} subtitle={t("supportNeverShare")} back />
      {linked ? (
        <Card>
          <View style={s.row}>
            <Link2 size={18} color={colors.blue600} />
            <Text style={s.body}>{linked}</Text>
          </View>
        </Card>
      ) : null}
      <Card>
        <Text style={s.label}>{t("supportCategory")}</Text>
        <View style={s.wrap} accessibilityRole="radiogroup">
          {CATEGORIES.map((x) => (
            <Pressable
              key={x}
              accessibilityRole="radio"
              accessibilityState={{ selected: category === x }}
              style={[s.option, category === x && s.selected]}
              onPress={() => setCategory(x)}
            >
              <Text style={s.optionText}>{td(`supportCategory_${x}`, x)}</Text>
            </Pressable>
          ))}
        </View>
        {category === "PRIVACY_REQUEST" ? <StatusChip label={t("privacyRequestNote")} tone="info" /> : null}
        <TextField label={t("supportSubject")} value={subject} onChangeText={setSubject} hint={t("minChars", { count: 4 })} />
        <TextField
          label={t("supportDescribe")}
          multiline
          value={description}
          onChangeText={setDescription}
          style={s.area}
          hint={t("minChars", { count: 15 })}
        />
        {error ? <Text accessibilityRole="alert" style={s.error}>{error}</Text> : null}
        <Button
          label={category === "FORMAL_COMPLAINT" ? t("supportSubmitComplaint") : t("supportCreate")}
          loading={busy}
          disabled={subject.trim().length < 4 || description.trim().length < 15}
          onPress={() => void submit()}
        />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700, flex: 1 },
  row: { flexDirection: "row", gap: space.x2, alignItems: "center" },
  wrap: { gap: space.x2 },
  option: {
    minHeight: 44,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    justifyContent: "center",
  },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  optionText: { ...type.body, color: colors.navy950 },
  area: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
  error: { ...type.meta, color: colors.dangerText },
});
