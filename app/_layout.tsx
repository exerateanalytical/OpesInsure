import React, { useEffect } from "react";
import { Stack, usePathname } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
// Per-weight subpath imports: the package index requires all 18 Inter TTFs,
// which Metro then packages into the APK even though only 4 are used (PERF-003).
import { useFonts } from "expo-font";
import { Inter_400Regular } from "@expo-google-fonts/inter/400Regular";
import { Inter_500Medium } from "@expo-google-fonts/inter/500Medium";
import { Inter_600SemiBold } from "@expo-google-fonts/inter/600SemiBold";
import { Inter_700Bold } from "@expo-google-fonts/inter/700Bold";
import { colors } from "@/theme/tokens";
import { roleToPortal, useSession, WORKSPACE_PORTALS } from "@/store/session";
import { carrierShellAllowed } from "@/lib/carrierAccess";
import { AppRuntime } from "@/components/AppRuntime";
import { ProductionErrorBoundary } from "@/components/ProductionErrorBoundary";
import { Telemetry } from "@/security/telemetry";
import { CrashReporting } from "@/security/crashReporting";
import { Platform } from "react-native";
import { rememberColdStartPath } from "@/lib/navigationContinuity";
import { CustomerScreenFrame } from "@/components/customer/CustomerTabBar";

// NAV-001: a web reload / deep link lands on index while the session boots;
// index restores this path once auth is back (native: +native-intent).
if (Platform.OS === "web" && typeof window !== "undefined") {
  rememberColdStartPath(window.location.pathname + window.location.search);
}

/** Customer stack screens (quote, checkout, policy, claim, account...) keep
 * the customer bottom bar and a safe Android back; see CustomerScreenFrame. */
const customerScreenLayout = ({ route, navigation, children }: { route: { name: string; params?: object }; navigation: { canGoBack(): boolean; isFocused(): boolean }; children: React.ReactElement }) => (
  <CustomerScreenFrame route={route} navigation={navigation}>
    {children}
  </CustomerScreenFrame>
);

SplashScreen.preventAutoHideAsync();
Telemetry.installGlobalHandlers();
CrashReporting.init();

export default function RootLayout() {
  const [loaded] = useFonts({
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  });
  const hydrate = useSession((s) => s.hydrate);
  const status = useSession((s) => s.status);
  const workspace = useSession((s) => s.activeWorkspace);
  useEffect(() => {
    void hydrate();
  }, [hydrate]);
  useEffect(() => {
    CrashReporting.setRole(status === "authenticated" ? workspace?.role_code : null);
  }, [status, workspace?.role_code]);
  const pathname = usePathname();
  useEffect(() => {
    Telemetry.setScreen(pathname);
  }, [pathname]);
  useEffect(() => {
    if (!loaded) return;
    SplashScreen.hideAsync();
    Telemetry.launched();
  }, [loaded]);
  if (!loaded) return null;
  const portal =
    status === "authenticated" ? roleToPortal(workspace?.role_code) : null;
  const customer = portal === "customer";
  const agent = portal === "agent";
  const broker = portal === "broker_admin" || portal === "broker_staff";
  // CAR-014: finance/claims/compliance staff use the shared carrier shell for
  // the modules their workspace is granted (each screen is CarrierGate'd and
  // the server scopes every call); no separate table-only duplicate.
  const carrier = carrierShellAllowed(portal, workspace?.permissions);
  const partner = !!portal && WORKSPACE_PORTALS.includes(portal);
  const authenticated = status === "authenticated";
  return (
    <ProductionErrorBoundary>
    <AppRuntime>
      <StatusBar style="dark" />
      <Stack
        screenLayout={customer ? customerScreenLayout : undefined}
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.neutral50 },
          animation: "slide_from_right",
        }}
      >
        {/* index is declared FIRST and never guarded: when a guard removes the
            current screen, expo-router falls back to the first allowed
            declared screen. That must be the router (index → session home),
            never "(auth)/invitation" (see src/lib/navigationContinuity.ts). */}
        <Stack.Screen name="index" />
        {/* Signed-in users never see welcome or the sign-in/up/OTP screens:
            the guard sends them back to index, which routes to their portal
            (or the role picker). */}
        <Stack.Protected guard={status !== "authenticated"}>
          <Stack.Screen name="welcome" />
          <Stack.Screen name="(auth)/sign-in" />
          <Stack.Screen name="(auth)/sign-up" />
          <Stack.Screen name="(auth)/verify" />
          <Stack.Screen name="(auth)/forgot-password" />
        </Stack.Protected>
        {/* Invitation works signed out (carried through sign-in) and signed
            in (accepted immediately), so it is not guarded. */}
        <Stack.Screen name="(auth)/invitation" />
        <Stack.Protected guard={authenticated}>
          <Stack.Screen name="(auth)/role" />
          <Stack.Screen name="sync/index" />
          <Stack.Screen name="account/data-usage" />
          <Stack.Screen name="security/step-up" />
          <Stack.Screen name="security/device-status" />
          {/* Biometric lock + devices are offered to every role (partners
              approve claims and money too), not only customers. */}
          <Stack.Screen name="account/security" />
          <Stack.Screen name="account/devices" />
          {/* Agent account home (AGENT_UI_SPEC_V2) links here too: profile, language,
              notification settings, privacy, KYC and support serve every signed-in role. */}
          <Stack.Screen name="account/profile" />
          <Stack.Screen name="account/language" />
          <Stack.Screen name="account/notifications" />
          <Stack.Screen name="account/privacy" />
          <Stack.Screen name="onboarding/kyc" />
          <Stack.Screen name="support/index" />
          <Stack.Screen name="support/new" />
          <Stack.Screen name="support/[id]" />
          <Stack.Screen name="support/faq" />
          <Stack.Screen name="system/status" />
          {/* REQ-SRC-001: results are permission- and scope-filtered server-side. */}
          <Stack.Screen name="search" />
          {/* Batch 6: customer, agent and broker quotes; the server scopes each quote to its owner. */}
          <Stack.Screen name="quote-comparison/[id]" />
        </Stack.Protected>
        <Stack.Protected guard={customer}>
          <Stack.Screen name="(customer)" />
          <Stack.Screen name="quote/product" />
          <Stack.Screen name="quote/product/[id]" />
          <Stack.Screen name="quote/risk" />
          <Stack.Screen name="quote/offers" />
          <Stack.Screen name="quote/disclosure" />
          <Stack.Screen name="quote/compare" />
          <Stack.Screen name="proposals/index" />
          <Stack.Screen name="proposals/[id]" />
          <Stack.Screen name="proposals/[id]/information" />
          <Stack.Screen name="checkout" />
          <Stack.Screen name="payment" />
          <Stack.Screen name="confirmation" />
          <Stack.Screen name="policy/[id]" />
          <Stack.Screen name="policy/[id]/service" />
          <Stack.Screen name="policy/[id]/documents" />
          <Stack.Screen name="policy/[id]/renew" />
          <Stack.Screen name="policy/[id]/renewal-quote" />
          <Stack.Screen name="policy/[id]/renewal-offers" />
          <Stack.Screen name="policy/[id]/renewal-review" />
          <Stack.Screen name="claim/new" />
          <Stack.Screen name="claim/new/incident" />
          <Stack.Screen name="claim/new/review" />
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
          <Stack.Screen name="claim/[id]/timeline" />
          <Stack.Screen name="claim/[id]/information" />
          <Stack.Screen name="claim/[id]/decision" />
          <Stack.Screen name="account/beneficiaries" />
          <Stack.Screen name="account/payment-methods" />
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
          <Stack.Screen name="documents/view" />
          <Stack.Screen name="services/index" />
          <Stack.Screen name="services/new" />
          <Stack.Screen name="services/[id]" />
          <Stack.Screen name="notifications/index" />
          <Stack.Screen name="notifications/[id]" />
        </Stack.Protected>
        <Stack.Protected guard={agent}>
          <Stack.Screen name="agent/index" />
          <Stack.Screen name="agent/account" />
          <Stack.Screen name="agent/notifications" />
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
          <Stack.Screen name="agent/leads/index" />
          <Stack.Screen name="agent/leads/new" />
          <Stack.Screen name="agent/leads/[id]" />
          <Stack.Screen name="agent/quotes" />
          <Stack.Screen name="agent/quotes/[id]" />
          <Stack.Screen name="agent/catalogue" />
          <Stack.Screen name="agent/policies" />
          <Stack.Screen name="agent/proposals" />
          <Stack.Screen name="agent/claims" />
          <Stack.Screen name="agent/claims/[id]" />
          <Stack.Screen name="agent/claims/new" />
          <Stack.Screen name="agent/policies/[id]" />
          <Stack.Screen name="agent/commissions/[id]" />
          <Stack.Screen name="agent/withdrawals/[id]" />
        </Stack.Protected>
        <Stack.Protected guard={broker}>
          <Stack.Screen name="broker/index" />
          <Stack.Screen name="broker/account" />
          <Stack.Screen name="broker/notifications" />
          <Stack.Screen name="broker/clients" />
          <Stack.Screen name="broker/clients/[id]" />
          <Stack.Screen name="broker/leads/index" />
          <Stack.Screen name="broker/leads/[id]" />
          <Stack.Screen name="broker/production" />
          <Stack.Screen name="broker/renewals" />
          <Stack.Screen name="broker/receivables" />
          <Stack.Screen name="broker/compliance" />
          <Stack.Screen name="broker/publications" />
          <Stack.Screen name="broker/quotes" />
          <Stack.Screen name="broker/quotes/[id]" />
          <Stack.Screen name="broker/catalogue" />
          <Stack.Screen name="broker/policies" />
          <Stack.Screen name="broker/claims" />
          <Stack.Screen name="broker/proposals" />
          <Stack.Screen name="broker/staff" />
          <Stack.Screen name="broker/commissions" />
          {/* Broker record details + creation (BRK-002..010). */}
          <Stack.Screen name="broker/clients/new" />
          <Stack.Screen name="broker/leads/new" />
          <Stack.Screen name="broker/policies/[id]" />
          <Stack.Screen name="broker/claims/new" />
          <Stack.Screen name="broker/claims/[id]" />
          <Stack.Screen name="broker/renewals/[id]" />
          <Stack.Screen name="broker/production/[id]" />
          <Stack.Screen name="broker/commissions/[id]" />
          <Stack.Screen name="broker/receivables/[id]" />
          <Stack.Screen name="broker/compliance/[id]" />
          <Stack.Screen name="broker/staff/[id]" />
        </Stack.Protected>
        <Stack.Protected guard={carrier}>
          <Stack.Screen name="carrier/index" />
          <Stack.Screen name="carrier/account" />
          <Stack.Screen name="carrier/notifications" />
          <Stack.Screen name="carrier/referrals/index" />
          <Stack.Screen name="carrier/referrals/[id]" />
          <Stack.Screen name="carrier/issuance" />
          <Stack.Screen name="carrier/claims" />
          <Stack.Screen name="carrier/settlements" />
          <Stack.Screen name="carrier/settlement/[id]" />
          <Stack.Screen name="carrier/bordereaux" />
          <Stack.Screen name="carrier/products" />
          <Stack.Screen name="carrier/proposals" />
          <Stack.Screen name="carrier/quote-requests/index" />
          <Stack.Screen name="carrier/quote-requests/[id]" />
          <Stack.Screen name="carrier/policies" />
          <Stack.Screen name="carrier/claims/[id]" />
          <Stack.Screen name="carrier/payments" />
          <Stack.Screen name="carrier/partners" />
          {/* CAR-003..011: carrier record details (shared DetailScreen). */}
          <Stack.Screen name="carrier/proposals/[id]" />
          <Stack.Screen name="carrier/products/[id]" />
          <Stack.Screen name="carrier/issuance/[id]" />
          <Stack.Screen name="carrier/policies/[id]" />
          <Stack.Screen name="carrier/payments/[id]" />
          <Stack.Screen name="carrier/partners/[id]" />
          <Stack.Screen name="carrier/bordereaux/[id]" />
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
