import React, { useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Plus, Trash2 } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, SectionTitle, TextField } from "@/components/ui";
import { useSession } from "@/store/session";
import { Beneficiary, Preferences, ProfileExtras, emptyProfileExtras } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const validDate = (v: string) => !v || (ISO_DATE.test(v) && !Number.isNaN(new Date(v).getTime()) && new Date(v).getTime() < Date.now());

/**
 * Personal information. Name and email go to PATCH /mobile/account/profile.
 * Address, occupation, date of birth and beneficiaries have no server
 * columns yet: they are kept on the device and sent with the next KYC
 * submission (as structured notes) from the identity-verification screen.
 */
export default function Profile() {
  const { t } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const hydrate = useSession((s) => s.hydrate);
  const [name, setName] = useState(user?.full_name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [extras, setExtras] = useState<ProfileExtras>(emptyProfileExtras);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    void Preferences.profileExtras().then(setExtras);
  }, []);
  const patch = (p: Partial<ProfileExtras>) => {
    setSaved(false);
    setExtras((x) => ({ ...x, ...p }));
  };
  const patchBeneficiary = (id: string, p: Partial<Beneficiary>) =>
    patch({ beneficiaries: extras.beneficiaries.map((b) => (b.id === id ? { ...b, ...p } : b)) });
  const shares = extras.beneficiaries.reduce((sum, b) => sum + (Number(b.share_percent) || 0), 0);
  const dobOk = validDate(extras.date_of_birth) && extras.beneficiaries.every((b) => validDate(b.date_of_birth));
  const sharesOk = extras.beneficiaries.length === 0 || shares === 100;
  const emailOk = !email.trim() || /^\S+@\S+\.\S+$/.test(email.trim());

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await Preferences.saveProfileExtras(extras);
      if (name.trim() !== user?.full_name || (email.trim() || null) !== (user?.email ?? null)) {
        await AccountApi.updateProfile({ full_name: name.trim(), email: email.trim() || null });
        await hydrate();
      }
      setSaved(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("profileSaveFailed"));
    } finally {
      setBusy(false);
    }
  };

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
        <TextField
          label={t("dateOfBirth")}
          value={extras.date_of_birth}
          onChangeText={(v) => patch({ date_of_birth: v })}
          placeholder="1990-05-21"
          keyboardType="numbers-and-punctuation"
          hint={t("dateFormatHint")}
          error={validDate(extras.date_of_birth) ? undefined : t("dateInvalid")}
        />
        <TextField label={t("occupation")} value={extras.occupation} onChangeText={(v) => patch({ occupation: v })} />
        <Text style={styles.body}>{t("phoneChangeNote")}</Text>
      </Card>

      <SectionTitle title={t("address")} />
      <Card>
        <TextField label={t("addressLine1")} value={extras.address_line1} onChangeText={(v) => patch({ address_line1: v })} autoComplete="street-address" />
        <TextField label={t("city")} value={extras.city} onChangeText={(v) => patch({ city: v })} />
        <TextField label={t("region")} value={extras.region} onChangeText={(v) => patch({ region: v })} hint={t("regionHint")} />
      </Card>

      <SectionTitle title={t("beneficiaries")} />
      <Text style={styles.body}>{t("beneficiariesBody")}</Text>
      {extras.beneficiaries.map((b, i) => (
        <Card key={b.id}>
          <View style={styles.row}>
            <Text style={[styles.title, styles.flex]}>{t("beneficiaryN", { n: i + 1 })}</Text>
            <Button
              label={t("remove")}
              icon={Trash2}
              variant="tertiary"
              onPress={() => patch({ beneficiaries: extras.beneficiaries.filter((x) => x.id !== b.id) })}
            />
          </View>
          <TextField label={t("fullName")} value={b.full_name} onChangeText={(v) => patchBeneficiary(b.id, { full_name: v })} />
          <TextField label={t("relationship")} value={b.relationship} onChangeText={(v) => patchBeneficiary(b.id, { relationship: v })} hint={t("relationshipHint")} />
          <TextField
            label={t("dateOfBirth")}
            value={b.date_of_birth}
            onChangeText={(v) => patchBeneficiary(b.id, { date_of_birth: v })}
            placeholder="2015-01-31"
            keyboardType="numbers-and-punctuation"
            error={validDate(b.date_of_birth) ? undefined : t("dateInvalid")}
          />
          <TextField
            label={t("sharePercent")}
            value={b.share_percent}
            onChangeText={(v) => patchBeneficiary(b.id, { share_percent: v.replace(/\D/g, "").slice(0, 3) })}
            keyboardType="number-pad"
          />
        </Card>
      ))}
      {extras.beneficiaries.length ? (
        <Text style={sharesOk ? styles.meta : styles.error}>{t("sharesTotal", { total: shares })}</Text>
      ) : null}
      <Button
        label={t("addBeneficiary")}
        icon={Plus}
        variant="secondary"
        disabled={extras.beneficiaries.length >= 5}
        onPress={() =>
          patch({
            beneficiaries: [
              ...extras.beneficiaries,
              { id: String(Date.now()), full_name: "", relationship: "", date_of_birth: "", share_percent: extras.beneficiaries.length ? "" : "100" },
            ],
          })
        }
      />

      <Card>
        <Text style={styles.meta}>{t("profileExtrasNote")}</Text>
        <Button label={t("identityVerification")} variant="tertiary" onPress={() => router.push("/onboarding/kyc")} />
      </Card>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {saved ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{t("profileSaved")}</Text> : null}
      <Button
        label={t("saveChanges")}
        loading={busy}
        disabled={name.trim().length < 3 || !dobOk || !sharesOk || !emailOk}
        onPress={() => void save()}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
