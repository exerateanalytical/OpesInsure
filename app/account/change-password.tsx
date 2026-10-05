import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { KeyRound, LogOut, ShieldCheck } from "lucide-react-native";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { CustomerApi } from "@/api/customer";
import { useSession } from "@/store/session";
import { passwordChangeProblem } from "@/lib/passwordChange";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * Change password (Security): PUT /me/password {current_password, password, password_confirmation}.
 * The server ends every session on success (all devices, this one included), so the app signs out
 * locally and opens sign-in with the new password.
 */
export default function ChangePassword() {
  const { t } = useTranslation();
  const signOut = useSession((s) => s.signOut);
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");
  const [touched, setTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [done, setDone] = useState(false);
  const problem = passwordChangeProblem(current, next, confirm);
  const fieldError = (key: "current" | "next" | "confirm") => (touched && problem?.field === key ? t(problem.copy) : undefined);

  const submit = async () => {
    setTouched(true);
    if (problem || busy) return;
    setBusy(true);
    setError(null);
    try {
      await CustomerApi.changePassword(current, next, confirm);
      setDone(true);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const signInAgain = async () => {
    setBusy(true);
    // Every session was revoked by the server; the local sign-out only clears this device.
    await signOut().catch(() => undefined);
    router.replace("/(auth)/sign-in");
  };

  if (done)
    return (
      <Screen>
        <BrandHeader title={t("pwChangeTitle")} back={false} right={null} />
        <Banner icon={ShieldCheck} tint="green" title={t("pwChangedTitle")} body={t("pwChangedBody")} />
        <Button label={t("pwSignInAgain")} icon={LogOut} loading={busy} onPress={() => void signInAgain()} />
      </Screen>
    );

  return (
    <Screen>
      <BrandHeader title={t("pwChangeTitle")} subtitle={t("pwChangeSubtitle")} back right={null} />
      <Card style={styles.card}>
        <TextField label={t("pwCurrent")} value={current} onChangeText={setCurrent} secureTextEntry autoCapitalize="none" autoComplete="current-password" textContentType="password" error={fieldError("current")} />
        <TextField label={t("pwNew")} value={next} onChangeText={setNext} secureTextEntry autoCapitalize="none" autoComplete="new-password" textContentType="newPassword" error={fieldError("next")} hint={t("passwordMin")} />
        <TextField label={t("pwConfirm")} value={confirm} onChangeText={setConfirm} secureTextEntry autoCapitalize="none" autoComplete="new-password" textContentType="newPassword" error={fieldError("confirm")} />
        {error ? <ErrorCard error={error} fallback={t("pwChangeFailed")} /> : null}
        <Button label={t("pwChangeSubmit")} icon={KeyRound} loading={busy} disabled={busy} onPress={() => void submit()} />
      </Card>
      <Text style={styles.note}>{t("pwChangeSignOutNote")}</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { gap: space.x3 },
  note: { ...type.meta, color: colors.neutral600 },
});
