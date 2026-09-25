import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { AgentApi, AgentWithdrawal } from "@/api/client";
import { handleStepUpRequired } from "@/security/step-up";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
export default function AgentWithdrawalScreen() {
  const { t } = useTranslation();
  const [amount, setAmount] = useState("");
  // Prefilled from the agent's registered mobile-money number (server
  // profile), falling back to the signed-in user's own phone. Never a
  // hard-coded default: a wrong prefill would pay someone else.
  const userPhone = useSession((st) => st.bootstrap?.user?.phone_e164) ?? "";
  const [phone, setPhone] = useState(userPhone);
  const [touched, setTouched] = useState(false);
  useEffect(() => {
    let alive = true;
    AgentApi.profile()
      .then((p) => {
        if (alive && !touched && p.momo_phone_e164) setPhone(p.momo_phone_e164);
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [touched]);
  const [result, setResult] = useState<AgentWithdrawal>();
  return (
    <Screen>
      <AppHeader
        title={t("agCommissionWithdrawal")}
        subtitle={t("agDestinationChanges")}
        back
      />
      <Card>
        <TextField
          label={t("mdAmountFcfa")}
          keyboardType="number-pad"
          value={amount}
          onChangeText={setAmount}
        />
        <TextField
          label={t("agVerifiedMomo")}
          keyboardType="phone-pad"
          value={phone}
          onChangeText={(v) => {
            setTouched(true);
            setPhone(v);
          }}
        />
        <Button
          label={t("agRequestWithdrawal")}
          disabled={Number(amount) <= 0 || phone.length < 8}
          onPress={async () => {
            try {
              setResult(await AgentApi.requestWithdrawal({
                amount_minor: Number(amount) * 100,
                provider: "mtn_momo",
                destination_phone: phone,
              }));
            } catch (error) {
              if (!handleStepUpRequired(error, "COMMISSION_WITHDRAWAL", "/agent/withdrawal")) throw error;
            }
          }}
        />
        {result ? (
          <>
            <StatusChip label={result.status} tone="warning" />
            <Text>
              Withdrawal request {result.id} is awaiting server checks. Never
              pay a fee to unlock commission.
            </Text>
          </>
        ) : null}
      </Card>
    </Screen>
  );
}
