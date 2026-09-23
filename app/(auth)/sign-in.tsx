import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, LockKeyhole, Phone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { BrandMark } from "@/components/BrandMark";
import { colors, space, type } from "@/theme/tokens";
import { AuthApi, type DemoAccount } from "@/api/client";
import { useSession } from "@/store/session";

export default function SignIn() {
  const [phone, setPhone] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [demo, setDemo] = useState<{ otp: string; accounts: DemoAccount[] } | null>(null);

  // Ask the API it is actually pointed at. Returns null in a real deployment,
  // where the endpoint does not exist, so nothing renders.
  useEffect(() => {
    let live = true;
    void AuthApi.demoAccounts().then((d) => {
      if (live) setDemo(d);
    });
    return () => {
      live = false;
    };
  }, []);

  // True one tap: request the code, verify it with the known demo OTP, and
  // complete sign-in immediately. No second screen, no second tap — a
  // reviewer never enters or even sees a code.
  const completeAuth = useSession((s) => s.completeAuthentication);
  const [demoAccountId, setDemoAccountId] = useState<string | null>(null);
  const signInAsDemoAccount = async (account: DemoAccount) => {
    if (!demo) return;
    setBusy(true);
    setDemoAccountId(account.phone_e164);
    setError(undefined);
    try {
      const challenge = await AuthApi.requestOtp(account.phone_e164);
      const auth = await AuthApi.verifyOtp(
        challenge.challenge_id,
        account.phone_e164,
        demo.otp,
      );
      await completeAuth(auth);
      router.replace("/(auth)/role");
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Demo account is unavailable.",
      );
    } finally {
      setBusy(false);
      setDemoAccountId(null);
    }
  };
  const submit = async () => {
    const normalized = phone.replace(/\s/g, "").replace(/^6/, "+2376");
    if (!/^\+2376\d{8}$/.test(normalized)) {
      setError("Enter a valid Cameroon mobile number.");
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      const result = await AuthApi.requestOtp(normalized);
      router.push({
        pathname: "/(auth)/verify",
        params: {
          challengeId: result.challenge_id,
          phone: normalized,
          expiresIn: String(result.expires_in),
        },
      });
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Unable to request a security code.",
      );
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <View style={styles.brand}>
        <BrandMark />
      </View>
      <AppHeader
        title="Welcome"
        subtitle="Use your Cameroon mobile number to continue"
      />
      <Card feature>
        <TextField
          label="Mobile number"
          value={phone}
          onChangeText={setPhone}
          keyboardType="phone-pad"
          placeholder="6 70 00 00 00"
          hint="Country code +237"
          error={error}
        />
        <Button
          label="Send secure code"
          icon={Phone}
          loading={busy}
          onPress={submit}
        />
      </Card>
      <View style={styles.trust}>
        <LockKeyhole size={18} color={colors.success} />
        <Text style={styles.trustText}>
          Your account is protected with encrypted authentication and role-based
          access.
        </Text>
      </View>
      <Button
        label="Browse insurers without signing in"
        variant="tertiary"
        onPress={() => router.push("/institutions/insurers")}
      />
      {demo && demo.accounts.length > 0 ? (
        <Card>
          <Text style={styles.demoTitle}>Demo accounts</Text>
          <Text style={styles.demoHint}>
            Tap a role to sign in. The code {demo.otp} is filled in for you.
          </Text>
          {demo.accounts.map((account) => (
            <Pressable
              accessibilityRole="button"
              disabled={busy}
              key={account.phone_e164}
              onPress={() => void signInAsDemoAccount(account)}
              style={styles.demoRow}
            >
              <View style={styles.demoFlex}>
                <Text style={styles.demoRole}>{account.label}</Text>
                <Text style={styles.demoPhone}>{account.phone_e164}</Text>
              </View>
              {demoAccountId === account.phone_e164 ? (
                <Text style={styles.demoBusy}>Signing in…</Text>
              ) : (
                <ChevronRight size={20} color={colors.neutral500} />
              )}
            </Pressable>
          ))}
        </Card>
      ) : null}
      {process.env.EXPO_PUBLIC_DEMO_MODE === "true" ? (
        <Button label="Browse bundled demo personas" variant="tertiary" onPress={() => router.push("/demo-accounts")} />
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  brand: { marginTop: space.x3 },
  trust: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  trustText: { ...type.meta, color: colors.neutral600, flex: 1 },
  demoTitle: { ...type.label, color: colors.navy950 },
  demoHint: { ...type.meta, color: colors.neutral600 },
  demoRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingVertical: space.x3,
  },
  demoFlex: { flex: 1 },
  demoRole: { ...type.body, color: colors.navy950 },
  demoPhone: { ...type.meta, color: colors.neutral500 },
  demoBusy: { ...type.meta, color: colors.blue600 },
});
