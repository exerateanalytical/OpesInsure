import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Pencil, UserPlus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ConsentRow, ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import { canAddWitness, witnessPayload } from "@/lib/claimParties";

export default function Parties() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, setData, loading, error, reload } = useLoad(() => ClaimsCompletionApi.parties(id), [id]);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  // Contact details for someone other than the claimant need their consent (MobileClaimPartyService).
  const [consent, setConsent] = useState(false);
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState<unknown>(null);
  // The witness is shown read-only for a last check before it is added to the claim.
  const [reviewing, setReviewing] = useState(false);
  const hasPhone = phone.trim().length > 0;
  const add = async () => {
    setAdding(true);
    setAddError(null);
    try {
      const p = await ClaimsCompletionApi.addParty(id, witnessPayload(name, phone, consent));
      setData([...(data ?? []), p]);
      setName("");
      setPhone("");
      setConsent(false);
      setReviewing(false);
    } catch (e) {
      setAddError(e);
    } finally {
      setAdding(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={t("partiesTitle")} subtitle={t("partiesSubtitle")} back />
      <StatePanel loading={loading} error={error} data={data} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("partiesLoading")}>
        {(parties) => (
          <>
            {parties.map((p) => (
              <Card key={p.id}>
                <StatusChip label={td(`partyRole_${p.role}`, p.role)} tone="info" />
                <Text style={s.title}>{p.display_name}</Text>
                {p.contact_phone ? <Text style={s.body}>{p.contact_phone}</Text> : null}
                {p.contact_email ? <Text style={s.body}>{p.contact_email}</Text> : null}
              </Card>
            ))}
          </>
        )}
      </StatePanel>
      {reviewing ? (
        <>
          <ReviewSection icon={UserPlus} title={t("partiesAddWitness")} onEdit={() => setReviewing(false)}>
            <ReviewRows
              rows={[
                { key: "display_name", label: t("partiesFullName"), value: name.trim() || null },
                { key: "contact_phone", label: t("partiesPhone"), value: phone.trim() || null },
              ]}
            />
          </ReviewSection>
          {addError ? <ErrorCard error={addError} fallback={t("errGeneric")} /> : null}
          <Button label={t("partiesConfirmAdd")} icon={UserPlus} loading={adding} onPress={() => void add()} />
          <Button label={t("reviewBackToForm")} icon={Pencil} variant="tertiary" disabled={adding} onPress={() => setReviewing(false)} />
        </>
      ) : (
      <Card>
        <Text style={s.title}>{t("partiesAddWitness")}</Text>
        <TextField label={t("partiesFullName")} value={name} onChangeText={setName} />
        <TextField label={t("partiesPhone")} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
        {hasPhone ? (
          <ConsentRow checked={consent} onPress={() => setConsent(!consent)} label={t("partiesConsent")} />
        ) : null}
        <Button
          label={t("reviewBeforeSave")}
          variant="secondary"
          disabled={!canAddWitness(name, phone, consent)}
          onPress={() => {
            setAddError(null);
            setReviewing(true);
          }}
        />
      </Card>
      )}
      <Button label={t("partiesContinue")} onPress={() => router.push(`/claim/${id}/checklist`)} />
    </Screen>
  );
}
const s = StyleSheet.create({
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});
