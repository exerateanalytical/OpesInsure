import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as ImagePicker from "expo-image-picker";
import { Camera, CheckCircle2, Images, UserRound } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { Preferences, ProfileExtras, emptyProfileExtras, profileExtrasToNotes } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { withoutRelock } from "@/lib/appLock";

const ID_TYPES = ["NATIONAL_ID", "PASSPORT", "RESIDENCE_PERMIT", "DRIVING_LICENCE"] as const;

/**
 * Identity verification (App\Application\Kyc\MobileKycService):
 *  1. add an identifier (PATCH /mobile/kyc/profile),
 *  2. photograph the document (POST /mobile/documents → /mobile/kyc/documents),
 *  3. submit (POST /mobile/kyc/submission) with the profile extras as notes.
 * With ?first=1 (right after sign-up) the step can be skipped and finished
 * later from Profile.
 */
export default function Kyc() {
  const { first } = useLocalSearchParams<{ first?: string }>();
  const onboarding = first === "1";
  const { t, td, date } = useTranslation();
  const q = useLoad(() => CustomerApi.kyc());
  const [idType, setIdType] = useState<(typeof ID_TYPES)[number]>("NATIONAL_ID");
  const [idValue, setIdValue] = useState("");
  const [extras, setExtras] = useState<ProfileExtras>(emptyProfileExtras);
  const [busy, setBusy] = useState<"id" | "doc" | "submit" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  useEffect(() => {
    void Preferences.profileExtras().then(setExtras);
  }, []);

  const run = async (kind: "id" | "doc" | "submit", fn: () => Promise<void>) => {
    setBusy(kind);
    setError(null);
    setNotice(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(null);
    }
  };
  const addIdentifier = () =>
    run("id", async () => {
      q.setData(await CustomerApi.addIdentifier({ identifier_type: idType, identifier_value: idValue.trim(), identifier_country: "CM" }));
      setIdValue("");
      setNotice(t("kycIdentifierSaved"));
    });
  const addDocument = (source: "camera" | "library") =>
    run("doc", async () => {
      if (source === "camera") {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) throw new Error(t("cameraPermissionNeeded"));
      }
      const options: ImagePicker.ImagePickerOptions = { mediaTypes: ["images"], quality: 0.7, base64: true };
      const result = source === "camera" ? await withoutRelock(() => ImagePicker.launchCameraAsync(options)) : await withoutRelock(() => ImagePicker.launchImageLibraryAsync(options));
      const asset = result.assets?.[0];
      if (result.canceled || !asset?.base64) return;
      const doc = await CustomerApi.uploadDocument({
        category: "KYC_IDENTITY",
        mime_type: asset.mimeType === "image/png" ? "image/png" : "image/jpeg",
        file_base64: asset.base64,
      });
      await CustomerApi.attachKycDocument(doc.id, "IDENTITY_DOCUMENT");
      q.setData(await CustomerApi.kyc());
      setNotice(t("kycDocumentAdded"));
    });
  const submit = () =>
    run("submit", async () => {
      await CustomerApi.submitKyc(profileExtrasToNotes(extras));
      await Preferences.saveProfileExtras({ ...extras, submitted_at: new Date().toISOString() });
      q.setData(await CustomerApi.kyc());
      setNotice(t("kycSubmitted"));
    });
  const finish = () => router.replace("/(customer)/(tabs)");

  return (
    <Screen>
      <AppHeader
        title={onboarding ? t("kycWelcomeTitle") : t("identityVerification")}
        subtitle={onboarding ? t("kycWelcomeSubtitle") : t("kycSubtitle")}
        back={!onboarding}
      />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(k) => {
          const status = k.submission?.status ?? "NOT_STARTED";
          const locked = status === "SUBMITTED" || status === "UNDER_REVIEW" || status === "APPROVED" || status === "VERIFIED";
          const docs = k.submission?.documents ?? [];
          return (
            <>
              <Card>
                <StatusChip
                  label={td(`kycStatus_${status}`, status)}
                  tone={status === "APPROVED" || status === "VERIFIED" ? "success" : status === "REJECTED" ? "danger" : locked ? "info" : "warning"}
                />
                <Text style={styles.body}>{t(locked ? "kycLockedBody" : "kycIntro")}</Text>
                {k.submission?.submitted_at ? <Text style={styles.meta}>{t("kycSubmittedOn", { date: date(k.submission.submitted_at) })}</Text> : null}
              </Card>

              <Card>
                <Text style={styles.title}>{t("kycStep1")}</Text>
                {k.identifiers.map((i) => (
                  <View key={`${i.type}-${i.masked_value}`} style={styles.row}>
                    <CheckCircle2 size={18} color={i.verified_at ? colors.success : colors.neutral500} />
                    <Text style={styles.body}>
                      {td(`idType_${i.type}`, i.type)} · {i.masked_value}
                    </Text>
                  </View>
                ))}
                {!locked ? (
                  <>
                    <View style={styles.chips} accessibilityRole="radiogroup">
                      {ID_TYPES.map((x) => (
                        <Pressable
                          key={x}
                          accessibilityRole="radio"
                          accessibilityState={{ selected: idType === x }}
                          onPress={() => setIdType(x)}
                          style={[styles.chip, idType === x && styles.chipOn]}
                        >
                          <Text style={[styles.chipText, idType === x && styles.chipTextOn]}>{td(`idType_${x}`, x)}</Text>
                        </Pressable>
                      ))}
                    </View>
                    <TextField label={t("kycIdNumber")} value={idValue} onChangeText={setIdValue} autoCapitalize="characters" />
                    <Button label={t("kycSaveIdentifier")} variant="secondary" loading={busy === "id"} disabled={idValue.trim().length < 4 || !!busy} onPress={() => void addIdentifier()} />
                  </>
                ) : null}
              </Card>

              <Card>
                <Text style={styles.title}>{t("kycStep2")}</Text>
                <Text style={styles.body}>{t("kycDocumentBody")}</Text>
                {docs.map((d) => (
                  <View key={d.id} style={styles.row}>
                    <CheckCircle2 size={18} color={d.scan_status === "CLEAN" ? colors.success : colors.neutral500} />
                    <Text style={styles.body}>{td(`scan_${d.scan_status}`, d.scan_status)}</Text>
                  </View>
                ))}
                {!locked ? (
                  <>
                    <Button label={t("kycTakePhoto")} icon={Camera} variant="secondary" loading={busy === "doc"} disabled={!!busy} onPress={() => void addDocument("camera")} />
                    <Button label={t("evidenceFromLibrary")} icon={Images} variant="tertiary" disabled={!!busy} onPress={() => void addDocument("library")} />
                  </>
                ) : null}
              </Card>

              <Card>
                <Text style={styles.title}>{t("kycStep3")}</Text>
                <Text style={styles.body}>{t("kycProfileBody")}</Text>
                <Button label={t("personalInformation")} icon={UserRound} variant="tertiary" onPress={() => router.push("/account/profile")} />
                {!locked ? (
                  <Button
                    label={t("kycSubmit")}
                    loading={busy === "submit"}
                    disabled={!k.identifiers.length || !docs.length || !!busy}
                    onPress={() => void submit()}
                  />
                ) : null}
                {!locked && (!k.identifiers.length || !docs.length) ? <Text style={styles.meta}>{t("kycSubmitHint")}</Text> : null}
              </Card>
            </>
          );
        }}
      </StatePanel>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {notice ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{notice}</Text> : null}
      {onboarding ? (
        <>
          <Button label={t("kycDone")} onPress={finish} />
          <Button label={t("kycSkip")} variant="tertiary" onPress={finish} />
          <Text style={styles.meta}>{t("kycLaterNote")}</Text>
        </>
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  chip: { minHeight: 44, paddingHorizontal: space.x3, justifyContent: "center", borderRadius: radius.pill, borderWidth: 1, borderColor: colors.neutral300 },
  chipOn: { backgroundColor: colors.blue50, borderColor: colors.blue600 },
  chipText: { ...type.label, color: colors.navy950 },
  chipTextOn: { color: colors.blue700 },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});
