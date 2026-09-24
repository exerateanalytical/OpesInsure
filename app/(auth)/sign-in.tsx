import React, { useEffect, useState } from "react";
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router, useLocalSearchParams } from "expo-router";
import {
  ArrowRight,
  Building2,
  ChevronRight,
  Handshake,
  KeyRound,
  LockKeyhole,
  Phone,
  ShieldCheck,
  UsersRound,
} from "lucide-react-native";
import { AuthCard, AuthHero } from "@/components/auth/AuthHero";
import { AuthFooterBranding } from "@/components/auth/AuthFooter";
import { AuthPrimaryButton, AuthSecondaryButton, AuthTextField } from "@/components/auth/AuthField";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { TrustStrip } from "@/components/auth/TrustStrip";
import { finishSignIn, isCameroonMobile, normalizeCameroonPhone } from "@/components/auth/finishSignIn";
import { authColors, authSpace, authType } from "@/theme/tokens";
import { AuthApi, type AuthTokens, type DemoAccount, type OtpChannel } from "@/api/client";

const toInvitation = () => router.push("/(auth)/invitation");
const audiences = [
  { icon: UsersRound, label: "Individuals & Families" },
  { icon: Building2, label: "Businesses & Organizations" },
  { icon: Handshake, label: "Brokers & Agents", onPress: toInvitation },
  { icon: ShieldCheck, label: "Insurance Companies", onPress: toInvitation },
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
  const [demo, setDemo] = useState<{ otp: string; accounts: DemoAccount[] } | null>(null);

  // Demo phones come from the real server (GET /public/demo-accounts), which
  // only answers while its demo mode is on; everywhere else this is null and
  // the block does not render.
  useEffect(() => {
    let live = true;
    if (process.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN === "false") return;
    void AuthApi.demoAccounts().then((d) => {
      if (live) setDemo(d);
    });
    return () => {
      live = false;
    };
  }, []);

  // One tap: phone + demo password against the live server. Falls back to
  // the OTP pair (known demo code) for a server without password login.
  const [demoAccountId, setDemoAccountId] = useState<string | null>(null);
  const signInAsDemoAccount = async (account: DemoAccount) => {
    if (!demo) return;
    setBusy(true);
    setDemoAccountId(account.phone_e164);
    setError(undefined);
    try {
      // The password comes from the server's demo list (demo mode only);
      // without it, use the OTP pair with the known demo code.
      let auth: AuthTokens;
      if (account.password) {
        auth = await AuthApi.passwordLogin(account.phone_e164, account.password);
      } else {
        const challenge = await AuthApi.requestOtp(account.phone_e164);
        auth = await AuthApi.verifyOtp(challenge.challenge_id, account.phone_e164, demo.otp);
      }
      await finishSignIn(auth, invite);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Demo account is unavailable.");
    } finally {
      setBusy(false);
      setDemoAccountId(null);
    }
  };

  const submit = async () => {
    const normalized = normalizeCameroonPhone(phone);
    if (!isCameroonMobile(normalized)) {
      setError("Enter a valid Cameroon mobile number.");
      return;
    }
    if (mode === "password" && !password) {
      setError("Enter your password.");
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
      setError(
        e instanceof Error
          ? e.message
          : mode === "password"
            ? "Unable to sign in."
            : "Unable to request a security code.",
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        style={styles.flex}
      >
        <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
          <AuthHero
            heading="Welcome back"
            subheading="Compare, buy and manage insurance from trusted insurers, brokers and agents."
          />
          <AuthCard>
            <AuthTextField
              icon={Phone}
              placeholder="Mobile number"
              value={phone}
              onChangeText={setPhone}
              keyboardType="phone-pad"
              autoComplete="tel"
              error={mode === "otp" ? error : undefined}
            />
            <Text style={styles.hint}>Country code +237 · e.g. 6 70 00 00 00</Text>
            {mode === "password" ? (
              <>
                <AuthTextField
                  icon={KeyRound}
                  placeholder="Password"
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
                  <Text style={styles.link}>Forgot password?</Text>
                </Pressable>
              </>
            ) : (
              <ChannelPicker
                label="Send my code by"
                options={otpChannels}
                value={channel}
                onChange={setChannel}
              />
            )}
            <AuthPrimaryButton
              label={
                busy
                  ? mode === "password" ? "Signing in…" : "Sending…"
                  : mode === "password" ? "Sign In" : "Send code"
              }
              icon={ArrowRight}
              loading={busy}
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
                {mode === "password" ? "Use a one-time code instead" : "Use my password instead"}
              </Text>
            </Pressable>
            <AuthSecondaryButton label="Create Account" onPress={() => router.push("/(auth)/sign-up")} />
            <View style={styles.trustRow}>
              <LockKeyhole size={16} color={authColors.slate500} />
              <Text style={styles.trustText}>
                Protected with encrypted authentication and role-based access.
              </Text>
            </View>
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push("/institutions/insurers")}
              style={styles.linkRow}
            >
              <Text style={styles.link}>Browse insurers without signing in</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push("/institutions/brokers")}
              style={styles.linkRow}
            >
              <Text style={styles.link}>Browse brokers</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => router.push("/verify")}
              style={styles.linkRow}
            >
              <Text style={styles.link}>Verify a certificate</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={toInvitation}
              style={styles.linkRow}
            >
              <Text style={styles.link}>Insurer, broker or agent? Join by invitation</Text>
            </Pressable>

            <View style={styles.divider} />
            <Text style={styles.audienceCaption}>For customers, insurers, brokers and agents.</Text>
            <TrustStrip items={audiences} />
          </AuthCard>

          {demo && demo.accounts.length > 0 ? (
            <View style={styles.demoCard}>
              <Text style={styles.demoTitle}>Demo accounts</Text>
              <Text style={styles.demoHint}>
                Tap an account to sign in against the live server (code {demo.otp} where no password is listed), or long-press to prefill it above.
              </Text>
              {demo.accounts.map((account) => (
                <Pressable
                  accessibilityRole="button"
                  disabled={busy}
                  key={account.phone_e164}
                  onPress={() => void signInAsDemoAccount(account)}
                  onLongPress={() => {
                    setPhone(account.phone_e164);
                    if (account.password) {
                      setPassword(account.password);
                      setMode("password");
                    }
                  }}
                  style={styles.demoRow}
                >
                  <View style={styles.demoFlex}>
                    <Text style={styles.demoRole}>{account.label}</Text>
                    <Text style={styles.demoPhone}>{account.phone_e164}</Text>
                  </View>
                  {demoAccountId === account.phone_e164 ? (
                    <Text style={styles.demoBusy}>Signing in…</Text>
                  ) : (
                    <ChevronRight size={20} color={authColors.slate500} />
                  )}
                </Pressable>
              ))}
            </View>
          ) : null}
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
  linkRow: { alignItems: "center", paddingVertical: authSpace[2] },
  forgotRow: { alignSelf: "flex-end", paddingVertical: authSpace[1], marginTop: -authSpace[2] },
  link: { ...authType.label, color: authColors.blue500 },
  divider: { height: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: authSpace[2] },
  audienceCaption: { ...authType.label, fontSize: 12, color: authColors.slate500, textAlign: "center" },
  demoCard: {
    marginHorizontal: authSpace[5],
    marginTop: authSpace[4],
    backgroundColor: authColors.white,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: authColors.ice200,
    padding: authSpace[4],
    gap: authSpace[1],
  },
  demoTitle: { ...authType.label, color: authColors.navy950 },
  demoHint: { ...authType.body, fontSize: 13, color: authColors.textSecondary },
  demoRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: authSpace[3],
    paddingVertical: authSpace[3],
  },
  demoFlex: { flex: 1 },
  demoRole: { ...authType.body, color: authColors.navy950 },
  demoPhone: { ...authType.label, fontSize: 12, color: authColors.slate500 },
  demoBusy: { ...authType.label, fontSize: 12, color: authColors.blue500 },
});
