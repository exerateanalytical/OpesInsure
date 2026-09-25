import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { LockKeyhole, LogOut } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { useSession } from "@/store/session";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** Shown whenever a signed-in account has no portal this app can open (an
 * unknown role code, or a route its workspace is not assigned). Always offers
 * a way out — never a blank screen. */
export default function AccessDenied() {
  const { t } = useTranslation();
  const status = useSession((s) => s.status);
  const workspaces = useSession((s) => s.bootstrap?.workspaces ?? []);
  const workspace = useSession((s) => s.activeWorkspace);
  const signOut = useSession((s) => s.signOut);
  return (
    <Screen>
      <Card feature>
        <LockKeyhole size={32} color={colors.danger} />
        <Text accessibilityRole="header" style={styles.title}>{t("adTitle")}</Text>
        <Text style={styles.body}>
          {workspace
            ? t("adRoleBody", { role: workspace.role_code.replaceAll("_", " ").toLowerCase(), tenant: workspace.tenant_name })
            : t("adNoneBody")}
        </Text>
        <Text style={styles.body}>{t("adMistake")}</Text>
        <SupportContactList />
        {status === "authenticated" && workspaces.length > 1 ? (
          <Button label={t("adChooseWorkspace")} variant="secondary" onPress={() => router.replace("/(auth)/role")} />
        ) : null}
        {status === "authenticated" ? (
          <Button
            label={t("adSignOut")}
            icon={LogOut}
            onPress={async () => {
              await signOut();
              router.replace("/(auth)/sign-in");
            }}
          />
        ) : (
          <Button label={t("adGoSignIn")} onPress={() => router.replace("/(auth)/sign-in")} />
        )}
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
