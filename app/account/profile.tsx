import React, { useEffect, useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CreditCard, IdCard, ShieldCheck, UsersRound } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Screen, TextField } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { TimezonePicker } from "@/components/TimezonePicker";
import { useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { profileToValues } from "@/lib/inputForms";
import { useTranslation } from "@/i18n";
import { accountProfileStepUpPurpose } from "@/lib/stepUpFlow";
import { allowedAction } from "@/lib/capabilities";
import { claimCoordinates } from "@/lib/deviceLocation";
import type { DeviceFix } from "@/lib/locationMatch";
import { STEP_UP_CANCELLED, withStepUp } from "@/security/step-up";
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
  // Raw GET customer-profile answer, for its allowed_actions (absent on older backends).
  const [serverProfile, setServerProfile] = useState<object | null>(null);
  // Device fix from the address autofill: latitude/longitude ride on the PATCH only when present.
  const fixRef = useRef<DeviceFix | null>(null);
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
        const raw = await AccountApi.customerProfile();
        if (live) setServerProfile(raw);
        server = profileToValues(raw);
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
      const nextEmail = email.trim() || null;
      const done = await withStepUp(accountProfileStepUpPurpose(user?.email ?? null, nextEmail), () =>
        AccountApi.updateProfile({ full_name: name.trim(), email: nextEmail }),
      );
      if (done === STEP_UP_CANCELLED) return;
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
      <BrandHeader title={t("personalDetailsTitle")} subtitle={t("personalDetailsSubtitle")} back right={null} />
      <View style={styles.flat} accessibilityLabel={t("contactDetails")}>
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
        <TextField label={t("personalMobile")} value={user?.phone_e164 ?? ""} editable={false} />
        <Text style={styles.body}>{t("phoneChangeNote")}</Text>
        {identityChanged ? (
          <Button label={t("saveChanges")} loading={busy} disabled={name.trim().length < 3 || !emailOk} onPress={() => void saveIdentity()} />
        ) : null}
      </View>

      <Text style={styles.body}>{t("profileServerNote")}</Text>
      {allowedAction(serverProfile, "update_profile", true) ? (
      <SchemaForm
        form="customer_profile"
        initialValues={seed}
        submitLabel={t("profileSaveForm")}
        flat
        onLocation={(fix) => {
          fixRef.current = fix;
        }}
        onSubmit={async (payload) => {
          setNotice(null);
          // An empty beneficiaries list clears them; other empties are left untouched.
          await AccountApi.updateCustomerProfile({ beneficiaries: [], ...payload, ...claimCoordinates(fixRef.current) });
          await Preferences.forgetProfileExtras();
          setNotice(t("profileSaved"));
        }}
      />
      ) : null}

      <Banner icon={IdCard} tint="blue" body={t("personalReverifyNote")} />
      <TimezonePicker />

      <Banner icon={ShieldCheck} tint="blue" title={t("identityVerification")} onPress={() => router.push("/onboarding/kyc")} />
      <Banner icon={UsersRound} tint="blue" title={t("benPageTitle")} body={t("benPageLinkBody")} onPress={() => router.push("/account/beneficiaries" as never)} />
      <Banner icon={CreditCard} tint="blue" title={t("payMethodsTitle")} body={t("payMethodsLinkBody")} onPress={() => router.push("/account/payment-methods" as never)} />
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  flat: { gap: 16 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
