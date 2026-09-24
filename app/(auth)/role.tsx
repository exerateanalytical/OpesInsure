import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { Redirect, router } from "expo-router";
import {
  BriefcaseBusiness,
  Building2,
  ChevronRight,
  Gavel,
  Landmark,
  LogOut,
  ReceiptText,
  Shield,
  Store,
  UserRound,
} from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { portalRoute, roleToPortal, useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

const icons: Record<string, any> = {
  customer: UserRound,
  agent: BriefcaseBusiness,
  broker_admin: Store,
  broker_staff: Store,
  carrier: Shield,
  platform_admin: Building2,
  compliance: Gavel,
  finance: Landmark,
  claims: ReceiptText,
};

const labels: Record<string, [string, string]> = {
  customer: ["Customer", "Buy and manage my insurance"],
  agent: ["Insurance agent", "Serve clients and track commissions"],
  broker_admin: ["Broker administrator", "Manage brokerage operations"],
  broker_staff: ["Broker staff", "Work with authorised clients"],
  carrier: ["Insurance company", "Manage products and referrals"],
  platform_admin: ["Platform operations", "Secure administration"],
  compliance: ["Compliance", "Oversight and regulatory checks"],
  finance: ["Finance", "Collections, settlements and reconciliation"],
  claims: ["Claims operations", "Assess and settle claims"],
};

export default function RoleSelect() {
  const status = useSession((s) => s.status);
  const bootstrap = useSession((s) => s.bootstrap);
  const active = useSession((s) => s.activeWorkspace);
  const selectWorkspace = useSession((s) => s.selectWorkspace);
  const signOut = useSession((s) => s.signOut);
  const workspaces = bootstrap?.workspaces ?? [];
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const select = async (workspace: (typeof workspaces)[number]) => {
    setBusy(workspace.membership_id);
    setError(null);
    try {
      await selectWorkspace(workspace);
      // replace, so back never returns to the OTP screen. Unknown roles land
      // on the "Access not available" screen rather than doing nothing.
      router.replace(portalRoute(workspace));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Workspace could not be opened.");
    } finally {
      setBusy(null);
    }
  };

  // Only one workspace: nothing to choose (completeAuthentication already
  // selected it) — go straight to the portal.
  const only = workspaces.length === 1 ? workspaces[0] : undefined;
  useEffect(() => {
    if (status === "authenticated" && only) void select(only);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, only?.membership_id]);

  if (status === "anonymous") return <Redirect href="/(auth)/sign-in" />;
  if (only) return <Screen><AppHeader title="Opening your workspace…" /></Screen>;

  return (
    <Screen>
      <AppHeader
        title="Choose your workspace"
        subtitle="Workspaces assigned securely by OpesInsure"
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {workspaces.length === 0 ? (
        <Card>
          <Text style={styles.title}>No active workspace</Text>
          <Text style={styles.subtitle}>
            Your account is verified, but no active customer or partner
            membership has been assigned. Contact support.
          </Text>
        </Card>
      ) : (
        workspaces.map((workspace) => {
          const portal = roleToPortal(workspace.role_code);
          const Icon = icons[portal ?? ""] ?? Building2;
          const [label, subtitle] = labels[portal ?? ""] ?? [
            workspace.role_code,
            "Authorised workspace",
          ];
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${label}, ${workspace.tenant_name}`}
              key={workspace.membership_id}
              disabled={!!busy}
              onPress={() => void select(workspace)}
            >
              <Card>
                <View style={styles.row}>
                  <View style={styles.icon}>
                    <Icon size={22} color={colors.blue600} />
                  </View>
                  <View style={styles.copy}>
                    <Text style={styles.title}>{label}</Text>
                    <Text style={styles.subtitle}>
                      {workspace.tenant_name} · {subtitle}
                      {active?.membership_id === workspace.membership_id ? " · current" : ""}
                    </Text>
                  </View>
                  <ChevronRight size={20} color={colors.neutral500} />
                </View>
              </Card>
            </Pressable>
          );
        })
      )}
      <Button
        label="Sign out"
        icon={LogOut}
        variant="tertiary"
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  icon: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
  },
  copy: { flex: 1 },
  title: { ...type.label, color: colors.navy950 },
  subtitle: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
