import React, { useState } from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { Alert, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { CarrierApi, CarrierReferral } from "@/api/client";
import { useTranslation } from "@/i18n";
import { errorMessage } from "@/lib/purchase";
export default function ReferralDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const q = useLoad(() => CarrierApi.referral(id), [id]);
  const x: CarrierReferral | undefined = q.data;
  const setX = q.setData;
  const [note, setNote] = useState("");
  const decide = (d: "APPROVE" | "DECLINE" | "MORE_INFORMATION") =>
    Alert.alert(
      t("refDecisionQ"),
      t("refDecision", { decision: td(`refDecision_${d}`, d) }),
      [
        { text: t("cancel"), style: "cancel" },
        {
          text: t("refConfirm"),
          onPress: async () => {
            try {
              setX(await CarrierApi.decideReferral(id, d, note));
            } catch (e) {
              // AUTHORITY_EXCEEDED / STALE_RECORD / ... arrive localized.
              Alert.alert(t("refNotRecorded"), errorMessage(e, t("errGeneric")));
            }
          },
        },
      ],
    );
  return (
    <Screen>
      <AppHeader title="Referral review" subtitle={x?.quote_id} back />
      {!x ? (
        <StatePanel {...q} onRetry={q.reload} loadingLabel="Loading referral…">
          {() => null}
        </StatePanel>
      ) : null}
      {x ? (
      <Card feature>
        <StatusChip label={x?.status ?? "LOADING"} tone="warning" />
        <Text>
          {x?.customer_name} · {x?.product}
        </Text>
        {x ? <Money amount={x.premium_minor / 100} /> : null}
        <Text>{x?.reason}</Text>
        <TextField
          label="Underwriting note"
          multiline
          value={note}
          onChangeText={setNote}
        />
      </Card>
      ) : null}
      {x?.status === "PENDING_REVIEW" ? (
        <>
          <Button
            label="Approve within authority"
            disabled={note.length < 5}
            onPress={() => decide("APPROVE")}
          />
          <Button
            label="Request more information"
            variant="secondary"
            disabled={note.length < 5}
            onPress={() => decide("MORE_INFORMATION")}
          />
          <Button
            label="Decline with reason"
            variant="danger"
            disabled={note.length < 5}
            onPress={() => decide("DECLINE")}
          />
        </>
      ) : null}
    </Screen>
  );
}
