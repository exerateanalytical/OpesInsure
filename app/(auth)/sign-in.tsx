import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { LockKeyhole, Phone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { BrandMark } from "@/components/BrandMark";
import { colors, space, type } from "@/theme/tokens";
import { AuthApi } from "@/api/client";

export default function SignIn() {
  const [phone, setPhone] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
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
      {process.env.EXPO_PUBLIC_DEMO_MODE === "true" ? (
        <Button label="Choose a demo account" variant="secondary" onPress={() => router.push("/demo-accounts")} />
      ) : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  brand: { marginTop: space.x3 },
  trust: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  trustText: { ...type.meta, color: colors.neutral600, flex: 1 },
});
