import React, { useCallback, useEffect, useState } from "react";
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { router, useLocalSearchParams } from "expo-router";
import {
  ArrowRight,
  BadgeCheck,
  Building2,
  Handshake,
  KeyRound,
  LockKeyhole,
  LucideIcon,
  Phone,
  Ticket,
} from "lucide-react-native";
import { AuthCard, AuthHero } from "@/components/auth/AuthHero";
import { AuthFooterBranding } from "@/components/auth/AuthFooter";
import { AuthPrimaryButton, AuthSecondaryButton, AuthTextField } from "@/components/auth/AuthField";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { finishSignIn, isCameroonMobile, normalizeCameroonPhone } from "@/components/auth/finishSignIn";
import { authColors, authIcon, authRadius, authSpace, authType } from "@/theme/tokens";
import { AuthApi, type AuthTokens, type OtpChannel } from "@/api/client";
import { DemoAccountPicker } from "@/components/auth/DemoAccountPicker";
import { demoCredential, normalizeDemoDirectory, type DemoAccountLike, type DemoDirectory } from "@/lib/demoLogin";
import { LockoutNotice } from "@/components/auth/LockoutNotice";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { isLockout, lockoutSeconds } from "@/lib/customerLogic";

const toInvitation = () => router.push("/(auth)/invitation");
/** Public destinations that need no account: one tile each, not a stack of links. */
const publicLinks: { icon: LucideIcon; label: CopyKey; onPress: () => void }[] = [
  { icon: Building2, label: "insurers", onPress: () => router.push("/institutions/insurers") },
  { icon: Handshake, label: "brokers", onPress: () => router.push("/institutions/brokers") },
  { icon: BadgeCheck, label: "verifyCertificate", onPress: () => router.push("/verify") },
  { icon: Ticket, label: "partnerJoinInvitation", onPress: toInvitation },
];
const otpChannels: { key: OtpChannel; label: string }[] = [
  { key: "whatsapp", label: "WhatsApp" },
  { key: "sms", label: "SMS" },
];

export default function SignIn() {
  // Set when arriving from the partner invitation screen: carried through
  // sign-in so the invitation is accepted right after.
  const { invite } = useLocalSearchParams<{ invite?: string }>();
  const [mode, setMode] = useState<"password" | "otp">("password");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [channel, setChannel] = useState<OtpChannel>("whatsapp");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [demo, setDemo] = useState<DemoDirectory | null>(null);
  const insets = useSafeAreaInsets();
  const { t } = useTranslation();
  // Server lockout (429 / 423): a dedicated countdown state, not a red line.
  const [locked, setLocked] = useState<number | null>(null);
  const unlock = useCallback(() => setLocked(null), []);

  // Demo phones come from the real server (GET /public/demo-accounts), which
  // only answers while its demo mode is on; everywhere else this is null and
  // the block does not render.
  useEffect(() => {
    let live = true;
    if (process.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN === "false") return;
    AuthApi.demoAccounts()
      .then((d) => {
        if (live) setDemo(normalizeDemoDirectory(d));
      })
      // Demo accounts are optional (server-gated); no list on failure.
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);

  // Picking an account signs in against the live server: the shared demo
  // password (data.password, top level) via password login, else the OTP
  // pair with the server's demo code (data.otp, 123456).
  const [demoAccountId, setDemoAccountId] = useState<string | null>(null);
  const signInAsDemoAccount = async (account: DemoAccountLike) => {
    if (!demo || busy) return;
    setBusy(true);
    setDemoAccountId(account.phone_e164);
    setError(undefined);
    try {
      const credential = demoCredential(demo, account);
      let auth: AuthTokens;
      if (credential.kind === "password") {
        auth = await AuthApi.passwordLogin(credential.phone, credential.password);
      } else {
        const challenge = await AuthApi.requestOtp(credential.phone);
        auth = await AuthApi.verifyOtp(challenge.challenge_id, credential.phone, credential.otp);
      }
      await finishSignIn(auth, invite);
    } catch (e) {
      setPhone(account.phone_e164);
      if (isLockout(e)) setLocked(lockoutSeconds(e));
      else setError(e instanceof Error ? e.message : t("demoUnavailable"));
    } finally {
      setBusy(false);
      setDemoAccountId(null);
    }
  };

  const submit = async () => {
    const normalized = normalizeCameroonPhone(phone);
    if (!isCameroonMobile(normalized)) {
      setError(t("phoneInvalid"));
      return;
    }
    if (mode === "password" && !password) {
      setError(t("passwordRequired"));
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      if (mode === "password") {
        const auth = await AuthApi.passwordLogin(normalized, password);
        await finishSignIn(auth, invite);
        return;
      }
      const result = await AuthApi.requestOtp(normalized, channel);
      router.push({
        pathname: "/(auth)/verify",
        params: {
          challengeId: result.challenge_id,
          phone: normalized,
          channel,
          expiresIn: String(result.expires_in),
          ...(invite ? { invite } : {}),
        },
      });
    } catch (e) {
      if (isLockout(e)) {
        setLocked(lockoutSeconds(e));
        return;
      }
      setError(
        e instanceof Error
          ? e.message
          : mode === "password"
            ? t("signInFailed")
            : t("otpRequestFailed"),
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : "height"}
        style={styles.flex}
      >
        <ScrollView
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          contentContainerStyle={{ paddingBottom: insets.bottom + authSpace[3] }}
        >
          <AuthHero
            heading={t("welcomeBack")}
            subheading={t("signInSubheading")}
          />
          <AuthCard>
            {locked ? <LockoutNotice seconds={locked} onDone={unlock} /> : null}
            <AuthTextField
              icon={Phone}
              placeholder={t("mobileNumber")}
              value={phone}
              onChangeText={setPhone}
              keyboardType="phone-pad"
              autoComplete="tel"
              error={mode === "otp" ? error : undefined}
            />
            <Text style={styles.hint}>{t("phoneHint")}</Text>
            {mode === "password" ? (
              <>
                <AuthTextField
                  icon={KeyRound}
                  placeholder={t("password")}
                  value={password}
                  onChangeText={setPassword}
                  secureToggle
                  autoCapitalize="none"
                  autoComplete="password"
                  error={error}
                />
                <Pressable
                  accessibilityRole="button"
                  onPress={() =>
                    router.push({
                      pathname: "/(auth)/forgot-password",
                      params: phone ? { phone: normalizeCameroonPhone(phone) } : {},
                    })
                  }
                  style={styles.forgotRow}
                >
                  <Text style={styles.link}>{t("forgotPassword")}</Text>
                </Pressable>
              </>
            ) : (
              <ChannelPicker
                label={t("sendCodeBy")}
                options={otpChannels}
                value={channel}
                onChange={setChannel}
              />
            )}
            <AuthPrimaryButton
              label={
                busy
                  ? mode === "password" ? t("signingIn") : t("sending")
                  : mode === "password" ? t("signIn") : t("sendCode")
              }
              icon={ArrowRight}
              loading={busy}
              disabled={!!locked}
              onPress={() => void submit()}
            />
            <Pressable
              accessibilityRole="button"
              onPress={() => {
                setError(undefined);
                setMode((m) => (m === "password" ? "otp" : "password"));
              }}
              style={styles.linkRow}
            >
              <Text style={styles.link}>
                {mode === "password" ? t("useOtpInstead") : t("usePasswordInstead")}
              </Text>
            </Pressable>

            {demo && demo.accounts.length > 0 ? (
              <DemoAccountPicker
                accounts={demo.accounts}
                busyPhone={demoAccountId}
                disabled={busy || !!locked}
                onPick={(account) => void signInAsDemoAccount(account)}
              />
            ) : null}

            <AuthSecondaryButton label={t("createAccount")} onPress={() => router.push("/(auth)/sign-up")} />
            <View style={styles.trustRow}>
              <LockKeyhole size={16} color={authColors.slate500} />
              <Text style={styles.trustText}>{t("authTrust")}</Text>
            </View>

            <View style={styles.divider} />
            <Text style={styles.publicCaption}>{t("browseInsurers")}</Text>
            <View style={styles.publicGrid}>
              {publicLinks.map((link) => {
                const Icon = link.icon;
                return (
                  <Pressable
                    key={link.label}
                    accessibilityRole="button"
                    accessibilityLabel={t(link.label)}
                    onPress={link.onPress}
                    style={({ pressed }) => [styles.publicTile, pressed && styles.pressed]}
                  >
                    <View style={styles.publicIcon}>
                      <Icon size={authIcon.normal} strokeWidth={authIcon.strokeWidth} color={authColors.navy800} />
                    </View>
                    <Text style={styles.publicLabel} numberOfLines={2}>{t(link.label)}</Text>
                  </Pressable>
                );
              })}
            </View>
          </AuthCard>
          <AuthFooterBranding />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: authColors.navy950 },
  flex: { flex: 1 },
  hint: { ...authType.label, fontSize: 12, color: authColors.slate500, marginTop: -authSpace[2] },
  trustRow: { flexDirection: "row", gap: authSpace[2], alignItems: "flex-start", paddingTop: authSpace[1] },
  trustText: { ...authType.body, fontSize: 13, color: authColors.textSecondary, flex: 1 },
  linkRow: { alignItems: "center", paddingVertical: authSpace[1] },
  forgotRow: { alignSelf: "flex-end", paddingVertical: authSpace[1], marginTop: -authSpace[2] },
  link: { ...authType.label, color: authColors.blue500 },
  pressed: { opacity: 0.85 },
  divider: { height: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: authSpace[2] },
  publicCaption: { ...authType.label, fontSize: 12, color: authColors.slate500, textAlign: "center" },
  publicGrid: { flexDirection: "row", flexWrap: "wrap", gap: authSpace[2] },
  publicTile: {
    flexBasis: "47%",
    flexGrow: 1,
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    gap: authSpace[2],
    paddingHorizontal: authSpace[3],
    paddingVertical: authSpace[2],
    borderRadius: authRadius.md,
    borderWidth: 1,
    borderColor: authColors.ice200,
    backgroundColor: authColors.ice50,
  },
  publicIcon: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: authColors.white,
    alignItems: "center",
    justifyContent: "center",
  },
  publicLabel: { ...authType.label, fontSize: 13, lineHeight: 17, color: authColors.navy950, flex: 1 },
});
