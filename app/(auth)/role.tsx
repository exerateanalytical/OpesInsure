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

import { useTranslation } from "@/i18n";
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


export default function RoleSelect() {
  const { t, td } = useTranslation();
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
      setError(e instanceof Error ? e.message : t("roleOpenFailed"));
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
  if (only) return <Screen><AppHeader title={t("roleOpening")} /></Screen>;

  return (
    <Screen>
      <AppHeader
        title={t("roleChoose")}
        subtitle={t("roleChooseSubtitle")}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      {workspaces.length === 0 ? (
        <Card>
          <Text style={styles.title}>{t("roleNone")}</Text>
          <Text style={styles.subtitle}>{t("roleNoneBody")}</Text>
        </Card>
      ) : (
        workspaces.map((workspace) => {
          const portal = roleToPortal(workspace.role_code);
          const Icon = icons[portal ?? ""] ?? Building2;
          const label = portal ? td(`role_${portal}`, workspace.role_code) : workspace.role_code;
          const subtitle = portal ? td(`role_${portal}_sub`, t("roleAuthorised")) : t("roleAuthorised");
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
                    <Icon size={30} color={colors.blue600} />
                  </View>
                  <View style={styles.copy}>
                    <Text style={styles.title}>{label}</Text>
                    <Text style={styles.subtitle}>
                      {workspace.tenant_name} · {subtitle}
                      {active?.membership_id === workspace.membership_id ? ` · ${t("roleCurrent")}` : ""}
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
        label={t("signOut")}
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
    alignItems: "center",
    justifyContent: "center",
  },
  copy: { flex: 1 },
  title: { ...type.label, color: colors.navy950 },
  subtitle: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
