import React, { useEffect } from "react";
import { Stack } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
  useFonts,
} from "@expo-google-fonts/inter";
import {
  Manrope_400Regular,
  Manrope_600SemiBold,
  Manrope_700Bold,
  Manrope_800ExtraBold,
} from "@expo-google-fonts/manrope";
import { colors } from "@/theme/tokens";
import { roleToPortal, useSession } from "@/store/session";
import { AppRuntime } from "@/components/AppRuntime";
import { ProductionErrorBoundary } from "@/components/ProductionErrorBoundary";

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const [loaded] = useFonts({
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
    Manrope_400Regular,
    Manrope_600SemiBold,
    Manrope_700Bold,
    Manrope_800ExtraBold,
  });
  const hydrate = useSession((s) => s.hydrate);
  const status = useSession((s) => s.status);
  const workspace = useSession((s) => s.activeWorkspace);
  useEffect(() => {
    void hydrate();
  }, [hydrate]);
  useEffect(() => {
    if (loaded) SplashScreen.hideAsync();
  }, [loaded]);
  if (!loaded) return null;
  const customer =
    status === "authenticated" &&
    roleToPortal(workspace?.role_code ?? "") === "customer";
  const agent =
    status === "authenticated" &&
    roleToPortal(workspace?.role_code ?? "") === "agent";
  const broker =
    status === "authenticated" &&
    ["broker_admin", "broker_staff"].includes(
      roleToPortal(workspace?.role_code ?? "") ?? "",
    );
  const carrier =
    status === "authenticated" &&
    roleToPortal(workspace?.role_code ?? "") === "carrier";
  const partner =
    status === "authenticated" &&
    !!workspace &&
    !["customer", "agent", "broker_admin", "broker_staff", "carrier"].includes(
      roleToPortal(workspace.role_code) ?? "",
    );
  const authenticated = status === "authenticated";
  return (
    <ProductionErrorBoundary>
    <AppRuntime>
      <StatusBar style="dark" />
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.neutral50 },
          animation: "slide_from_right",
        }}
      >
        <Stack.Protected guard={authenticated}>
          <Stack.Screen name="sync/index" />
          <Stack.Screen name="account/data-usage" />
          <Stack.Screen name="security/step-up" />
          <Stack.Screen name="security/device-status" />
          <Stack.Screen name="system/status" />
        </Stack.Protected>
        <Stack.Protected guard={customer}>
          <Stack.Screen name="quote/product" />
          <Stack.Screen name="quote/risk" />
          <Stack.Screen name="quote/offers" />
          <Stack.Screen name="quote/disclosure" />
          <Stack.Screen name="checkout" />
          <Stack.Screen name="payment" />
          <Stack.Screen name="confirmation" />
          <Stack.Screen name="policy/[id]" />
          <Stack.Screen name="policy/[id]/service" />
          <Stack.Screen name="policy/[id]/renew" />
          <Stack.Screen name="claim/new" />
          <Stack.Screen name="claim/[id]" />
          <Stack.Screen name="claim/[id]/evidence" />
          <Stack.Screen name="claim/[id]/appeal" />
          <Stack.Screen name="claim/emergency" />
          <Stack.Screen name="claim/[id]/incident" />
          <Stack.Screen name="claim/[id]/parties" />
          <Stack.Screen name="claim/[id]/checklist" />
          <Stack.Screen name="claim/[id]/inspection" />
          <Stack.Screen name="claim/[id]/repair" />
          <Stack.Screen name="claim/[id]/settlement" />
          <Stack.Screen name="claim/[id]/settlement-payment" />
          <Stack.Screen name="account/profile" />
          <Stack.Screen name="account/language" />
          <Stack.Screen name="account/devices" />
          <Stack.Screen name="account/notifications" />
          <Stack.Screen name="account/security" />
          <Stack.Screen name="onboarding/kyc" />
          <Stack.Screen name="assets/index" />
          <Stack.Screen name="assets/new" />
          <Stack.Screen name="assets/[id]" />
          <Stack.Screen name="assets/[id]/scan" />
          <Stack.Screen name="quote/questions" />
          <Stack.Screen name="quote/referral" />
          <Stack.Screen name="quote/terms" />
          <Stack.Screen name="payments/index" />
          <Stack.Screen name="payments/[id]" />
          <Stack.Screen name="payments/[id]/receipt" />
          <Stack.Screen name="payments/[id]/refund" />
          <Stack.Screen name="wallet/index" />
          <Stack.Screen name="wallet/policy/[id]" />
          <Stack.Screen name="delivery/[id]" />
          <Stack.Screen name="delivery/[id]/address" />
          <Stack.Screen name="delivery/[id]/confirm" />
          <Stack.Screen name="quotes/index" />
          <Stack.Screen name="quotes/[id]" />
          <Stack.Screen name="documents/[id]" />
          <Stack.Screen name="services/index" />
          <Stack.Screen name="services/new" />
          <Stack.Screen name="services/[id]" />
          <Stack.Screen name="notifications/index" />
          <Stack.Screen name="notifications/[id]" />
          <Stack.Screen name="support/index" />
          <Stack.Screen name="support/new" />
          <Stack.Screen name="support/[id]" />
        </Stack.Protected>
        <Stack.Protected guard={agent}>
          <Stack.Screen name="agent/index" />
          <Stack.Screen name="agent/onboarding" />
          <Stack.Screen name="agent/clients/index" />
          <Stack.Screen name="agent/clients/new" />
          <Stack.Screen name="agent/clients/[id]" />
          <Stack.Screen name="agent/sales/new" />
          <Stack.Screen name="agent/sales/[id]" />
          <Stack.Screen name="agent/renewals" />
          <Stack.Screen name="agent/wallet" />
          <Stack.Screen name="agent/withdrawal" />
          <Stack.Screen name="agent/offline" />
        </Stack.Protected>
        <Stack.Protected guard={broker}>
          <Stack.Screen name="broker/index" />
          <Stack.Screen name="broker/clients" />
          <Stack.Screen name="broker/clients/[id]" />
          <Stack.Screen name="broker/production" />
          <Stack.Screen name="broker/renewals" />
          <Stack.Screen name="broker/receivables" />
          <Stack.Screen name="broker/compliance" />
          <Stack.Screen name="broker/publications" />
        </Stack.Protected>
        <Stack.Protected guard={carrier}>
          <Stack.Screen name="carrier/index" />
          <Stack.Screen name="carrier/referrals/index" />
          <Stack.Screen name="carrier/referrals/[id]" />
          <Stack.Screen name="carrier/issuance" />
          <Stack.Screen name="carrier/claims" />
          <Stack.Screen name="carrier/settlements" />
        </Stack.Protected>
        <Stack.Protected guard={partner}>
          <Stack.Screen name="workspace/[role]" />
          <Stack.Screen name="workspace/[role]/module/[module]" />
        </Stack.Protected>
      </Stack>
    </AppRuntime>
    </ProductionErrorBoundary>
  );
}
