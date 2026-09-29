import React, { useEffect, useRef, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CheckCircle2, IdCard, MailCheck, MapPin, Pencil, UserRound, UsersRound } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Screen, StatusChip, TextField } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { EditableSchemaSection, SummaryCard, SummaryField } from "@/components/forms/SchemaSummary";
import { ReviewIntro, ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
import { useSession } from "@/store/session";
import { Preferences } from "@/store/preferences";
import { useLoad } from "@/hooks/useLoad";
import { useEmailVerification } from "@/hooks/useEmailVerification";
import { PROFILE_PERSONAL_FIELDS, profileToValues } from "@/lib/inputForms";
import { useTranslation } from "@/i18n";
import { accountProfileStepUpPurpose } from "@/lib/stepUpFlow";
import { allowedAction } from "@/lib/capabilities";
import { claimCoordinates } from "@/lib/deviceLocation";
import type { DeviceFix } from "@/lib/locationMatch";
import { STEP_UP_CANCELLED, withStepUp } from "@/security/step-up";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Personal information, read as a profile card with Edit actions:
 *  - contact details (name, email, mobile): PATCH /mobile/account/profile;
 *  - about you & address: server form customer_profile (GET /forms/customer_profile)
 *    -> PATCH /mobile/account/customer-profile, pickers not free text.
 * Summaries show what the server holds (the same GET the Profile tab's
 * completion card reads); details once kept only on this device prefill the
 * form until the server has its own.
 */
export default function Profile() {
  const { t } = useTranslation();
  const email = useEmailVerification();
  const profile = useLoad(() => AccountApi.customerProfile(), []);
  const [legacy, setLegacy] = useState<Record<string, string> | null>(null);
  // Device fix from the address autofill: latitude/longitude ride on the PATCH only when present.
  const fixRef = useRef<DeviceFix | null>(null);

  useEffect(() => {
    let live = true;
    void Preferences.profileExtras().then((local) => {
      if (live) setLegacy(profileToValues({ ...local, beneficiaries: [] }));
    });
    return () => {
      live = false;
    };
  }, []);

  const server = profileToValues(profile.data);
  const values = Object.fromEntries(PROFILE_PERSONAL_FIELDS.map((k) => [k, server[k] ?? ""]));

  return (
    <Screen>
      <BrandHeader title={t("personalDetailsTitle")} subtitle={t("personalDetailsSubtitle")} back right={null} />
      <ContactSection />
      {email.unverified ? (
        <Pressable
          accessibilityRole="button"
          disabled={email.state === "busy" || email.state === "sent"}
          onPress={() => void email.send()}
          style={({ pressed }) => [styles.verify, pressed && styles.pressed]}
        >
          <MailCheck size={20} color={colors.warningText} />
          <Text style={styles.verifyText}>
            {email.state === "sent" ? t("emailVerifySent") : email.state === "busy" ? t("sending") : email.state === "error" ? t("emailVerifyError") : t("emailVerify")}
          </Text>
        </Pressable>
      ) : null}

      {profile.loading && !profile.data ? <LoadingState /> : null}
      {profile.error && !profile.data ? <ErrorState error={profile.error} onRetry={profile.reload} /> : null}
      {profile.data ? (
        <EditableSchemaSection
          form="customer_profile"
          only={PROFILE_PERSONAL_FIELDS}
          values={values}
          seed={legacy}
          icon={MapPin}
          title={t("piAboutTitle")}
          submitLabel={t("profileSaveForm")}
          // Save first shows the section's answers read-only; confirming saves them.
          review={{ intro: t("reviewSaveIntro"), confirmLabel: t("benConfirmSave"), title: t("piAboutTitle"), continueLabel: t("reviewBeforeSave") }}
          disabled={!allowedAction(profile.data, "update_profile", true)}
          onLocation={(fix) => {
            fixRef.current = fix;
          }}
          onSubmit={async (payload) => {
            profile.setData(await AccountApi.updateCustomerProfile({ ...payload, ...claimCoordinates(fixRef.current) }));
            // Device-only drafts are dropped once the server holds the details (beneficiaries have their own page).
            await Preferences.forgetProfileExtrasPart("personal");
          }}
        />
      ) : null}

      <Banner icon={IdCard} tint="blue" body={t("personalReverifyNote")} />
      <Banner icon={UsersRound} tint="blue" title={t("benPageTitle")} body={t("benPageLinkBody")} onPress={() => router.push("/account/beneficiaries" as never)} />
    </Screen>
  );
}

/** Name / email / mobile: a summary with an Edit action; e-mail changes go through step-up. */
function ContactSection() {
  const { t } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const hydrate = useSession((s) => s.hydrate);
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(user?.full_name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  // Save shows the new contact details read-only first; Confirm saves (step-up for an e-mail change).
  const [reviewing, setReviewing] = useState(false);
  const emailOk = !email.trim() || /^\S+@\S+\.\S+$/.test(email.trim());
  const changed = name.trim() !== (user?.full_name ?? "") || (email.trim() || null) !== (user?.email ?? null);
  const phoneVerified = !!user?.phone_verified_at || user?.contacts_verified === true;
  const emailVerified = !!user?.email_verified_at || user?.contacts_verified === true;
  const emailChanged = (email.trim() || null) !== (user?.email ?? null);

  const open = () => {
    setName(user?.full_name ?? "");
    setEmail(user?.email ?? "");
    setError(null);
    setSaved(false);
    setReviewing(false);
    setEditing(true);
  };
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      const nextEmail = email.trim() || null;
      const done = await withStepUp(accountProfileStepUpPurpose(user?.email ?? null, nextEmail), () =>
        AccountApi.updateProfile({ full_name: name.trim(), email: nextEmail }),
      );
      if (done === STEP_UP_CANCELLED) return;
      await hydrate();
      setReviewing(false);
      setEditing(false);
      setSaved(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("profileSaveFailed"));
    } finally {
      setBusy(false);
    }
  };

  const chip = (ok: boolean) => <StatusChip label={ok ? t("portalVerified") : t("portalNotVerified")} tone={ok ? "success" : "warning"} />;

  if (!editing) {
    return (
      <>
        {saved ? <Banner icon={CheckCircle2} tint="green" body={t("profileSaved")} /> : null}
        <SummaryCard icon={UserRound} title={t("contactDetails")} onEdit={open}>
          <SummaryField first label={t("fullName")} value={user?.full_name} onAdd={open} />
          <SummaryField label={t("email")} value={user?.email} onAdd={open} right={user?.email ? chip(emailVerified) : null} />
          <SummaryField label={t("personalMobile")} value={user?.phone_e164} right={user?.phone_e164 ? chip(phoneVerified) : null} note={t("phoneChangeNote")} />
        </SummaryCard>
      </>
    );
  }
  if (reviewing) {
    return (
      <>
        <ReviewIntro body={t("reviewSaveIntro")} />
        <ReviewSection icon={UserRound} title={t("contactDetails")} onEdit={() => setReviewing(false)}>
          <ReviewRows
            rows={[
              { key: "full_name", label: t("fullName"), value: name.trim() || null },
              { key: "email", label: t("email"), value: email.trim() || null },
            ]}
          />
        </ReviewSection>
        {emailChanged && email.trim() ? <Text style={styles.note}>{t("contactEmailStepUpNote")}</Text> : null}
        {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
        <Button label={t("benConfirmSave")} loading={busy} onPress={() => void save()} />
        <Button label={t("reviewBackToForm")} icon={Pencil} variant="tertiary" disabled={busy} onPress={() => setReviewing(false)} />
      </>
    );
  }
  return (
    <SummaryCard icon={UserRound} title={t("contactDetails")} onCancel={() => setEditing(false)}>
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
      <SummaryField first label={t("personalMobile")} value={user?.phone_e164} right={user?.phone_e164 ? chip(phoneVerified) : null} note={t("phoneChangeNote")} />
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      <Button
        label={t("saveChanges")}
        disabled={!changed || name.trim().length < 3 || !emailOk}
        onPress={() => {
          setError(null);
          setReviewing(true);
        }}
      />
    </SummaryCard>
  );
}

const styles = StyleSheet.create({
  pressed: { opacity: 0.85 },
  verify: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 44, paddingHorizontal: space.x3, borderRadius: radius.control, backgroundColor: colors.warningSoft },
  verifyText: { ...type.label, color: colors.warningText, flex: 1 },
  error: { ...type.meta, color: colors.dangerText },
  note: { ...type.meta, color: colors.neutral600 },
});
