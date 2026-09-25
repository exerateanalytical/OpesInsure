import React, { useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { AccountApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { TimezonePicker } from "@/components/TimezonePicker";
import { useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { profileToValues } from "@/lib/inputForms";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * Personal information. Name and email: PATCH /mobile/account/profile.
 * Date of birth, occupation, address and beneficiaries: the server form
 * customer_profile (GET /forms/customer_profile) submitted to PATCH
 * /mobile/account/customer-profile — pickers, not free text. Details once
 * kept only on this device prefill the form until the server has its own.
 */
export default function Profile() {
  const { t } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const hydrate = useSession((s) => s.hydrate);
  const [name, setName] = useState(user?.full_name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [seed, setSeed] = useState<Record<string, string> | null>(null);
  const emailOk = !email.trim() || /^\S+@\S+\.\S+$/.test(email.trim());

  useEffect(() => {
    let live = true;
    void (async () => {
      const local = await Preferences.profileExtras();
      const legacy = profileToValues({
        ...local,
        beneficiaries: local.beneficiaries.map((b) => ({ name: b.full_name, relationship: b.relationship, share_percent: b.share_percent })),
      });
      let server: Record<string, string> = {};
      try {
        server = profileToValues(await AccountApi.customerProfile());
      } catch {
        // Offline: the form still opens with what the device knows.
      }
      if (live) setSeed({ ...legacy, ...Object.fromEntries(Object.entries(server).filter(([, v]) => v)) });
    })();
    return () => {
      live = false;
    };
  }, []);

  const saveIdentity = async () => {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await AccountApi.updateProfile({ full_name: name.trim(), email: email.trim() || null });
      await hydrate();
      setNotice(t("profileSaved"));
    } catch (e) {
      setError(e instanceof Error ? e.message : t("profileSaveFailed"));
    } finally {
      setBusy(false);
    }
  };
  const identityChanged = name.trim() !== (user?.full_name ?? "") || (email.trim() || null) !== (user?.email ?? null);

  return (
    <Screen>
      <AppHeader title={t("personalInformation")} back />
      <Card>
        <TextField label={t("fullName")} value={name} onChangeText={setName} autoComplete="name" />
        <TextField
          label={t("email")}
          value={email}
          onChangeText={setEmail}
          keyboardType="email-address"
          autoCapitalize="none"
          autoComplete="email"
          error={emailOk ? undefined : t("emailInvalid")}
        />
        <Text style={styles.body}>{t("phoneChangeNote")}</Text>
        {identityChanged ? (
          <Button label={t("saveChanges")} loading={busy} disabled={name.trim().length < 3 || !emailOk} onPress={() => void saveIdentity()} />
        ) : null}
      </Card>

      <Text style={styles.body}>{t("profileServerNote")}</Text>
      <SchemaForm
        form="customer_profile"
        initialValues={seed}
        submitLabel={t("profileSaveForm")}
        onSubmit={async (payload) => {
          setNotice(null);
          // An empty beneficiaries list clears them; other empties are left untouched.
          await AccountApi.updateCustomerProfile({ beneficiaries: [], ...payload });
          await Preferences.forgetProfileExtras();
          setNotice(t("profileSaved"));
        }}
      />

      <TimezonePicker />

      <Card>
        <Button label={t("identityVerification")} variant="tertiary" onPress={() => router.push("/onboarding/kyc")} />
      </Card>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
