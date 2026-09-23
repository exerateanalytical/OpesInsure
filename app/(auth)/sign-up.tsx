import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Check, UserPlus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { colors, radius, space, type } from "@/theme/tokens";
import { AuthApi } from "@/api/client";

const TERMS_VERSION = "2026-01-01";

export default function SignUp() {
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = async () => {
    setError(undefined);
    setFieldErrors({});

    const normalizedPhone = phone.replace(/\s/g, "").replace(/^6/, "+2376");
    const errors: Record<string, string> = {};
    if (fullName.trim().length < 3)
      errors.fullName = "Enter your full name.";
    if (!/^\+2376\d{8}$/.test(normalizedPhone))
      errors.phone = "Enter a valid Cameroon mobile number.";
    if (password.length < 12)
      errors.password = "At least 12 characters.";
    if (password !== confirmPassword)
      errors.confirmPassword = "Passwords do not match.";
    if (!agreed) errors.agreed = "Required to continue.";
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setBusy(true);
    try {
      await AuthApi.register({
        full_name: fullName.trim(),
        phone_e164: normalizedPhone,
        email: email.trim() || undefined,
        password,
        password_confirmation: confirmPassword,
        locale: "en",
        terms_version: TERMS_VERSION,
      });
      // Registration alone leaves the account unverified. Requesting a code
      // immediately and handing off to the same verify screen sign-in uses
      // means completing that one OTP is the only extra step — it doubles
      // as the account's activation, not a separate email/SMS confirmation.
      const challenge = await AuthApi.requestOtp(normalizedPhone);
      router.push({
        pathname: "/(auth)/verify",
        params: {
          challengeId: challenge.challenge_id,
          phone: normalizedPhone,
          expiresIn: String(challenge.expires_in),
        },
      });
    } catch (e) {
      if (e && typeof e === "object" && "fields" in e && e.fields) {
        const serverErrors: Record<string, string> = {};
        const fields = e.fields as Record<string, string[]>;
        if (fields.full_name?.[0]) serverErrors.fullName = fields.full_name[0];
        if (fields.phone_e164?.[0]) serverErrors.phone = fields.phone_e164[0];
        if (fields.email?.[0]) serverErrors.email = fields.email[0];
        if (fields.password?.[0]) serverErrors.password = fields.password[0];
        setFieldErrors(serverErrors);
      }
      setError(e instanceof Error ? e.message : "Could not create your account.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader
        title="Create your account"
        subtitle="Set up secure access to OpesInsure"
        back
      />
      <Card feature>
        <TextField
          label="Full name"
          value={fullName}
          onChangeText={setFullName}
          autoCapitalize="words"
          error={fieldErrors.fullName}
        />
        <TextField
          label="Mobile number"
          value={phone}
          onChangeText={setPhone}
          keyboardType="phone-pad"
          placeholder="6 70 00 00 00"
          hint="Country code +237"
          error={fieldErrors.phone}
        />
        <TextField
          label="Email (optional)"
          value={email}
          onChangeText={setEmail}
          keyboardType="email-address"
          autoCapitalize="none"
          error={fieldErrors.email}
        />
        <TextField
          label="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          textContentType="newPassword"
          hint="At least 12 characters"
          error={fieldErrors.password}
        />
        <TextField
          label="Confirm password"
          value={confirmPassword}
          onChangeText={setConfirmPassword}
          secureTextEntry
          textContentType="newPassword"
          error={fieldErrors.confirmPassword}
        />
        <Pressable
          accessibilityRole="checkbox"
          accessibilityState={{ checked: agreed }}
          style={styles.terms}
          onPress={() => setAgreed((v) => !v)}
        >
          <View style={[styles.checkbox, agreed && styles.checkboxChecked]}>
            {agreed ? <Check size={14} color={colors.white} /> : null}
          </View>
          <Text style={styles.termsText}>
            I agree to the Terms of Service and Privacy Policy
          </Text>
        </Pressable>
        {fieldErrors.agreed ? (
          <Text style={styles.error}>{fieldErrors.agreed}</Text>
        ) : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button
          label="Create account"
          icon={UserPlus}
          loading={busy}
          onPress={() => void submit()}
        />
      </Card>
      <Button
        label="I already have an account"
        variant="tertiary"
        onPress={() => router.replace("/(auth)/sign-in")}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  terms: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: space.x3,
    paddingVertical: space.x3,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: radius.control,
    borderWidth: 1.5,
    borderColor: colors.neutral400,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 1,
  },
  checkboxChecked: {
    backgroundColor: colors.blue600,
    borderColor: colors.blue600,
  },
  termsText: { ...type.body, color: colors.neutral700, flex: 1 },
  error: { ...type.meta, color: colors.dangerText },
});
