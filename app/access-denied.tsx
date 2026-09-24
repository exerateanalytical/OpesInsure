import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { LockKeyhole, LogOut } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { useSession } from "@/store/session";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { colors, type } from "@/theme/tokens";

/** Shown whenever a signed-in account has no portal this app can open (an
 * unknown role code, or a route its workspace is not assigned). Always offers
 * a way out — never a blank screen. */
export default function AccessDenied() {
  const status = useSession((s) => s.status);
  const workspaces = useSession((s) => s.bootstrap?.workspaces ?? []);
  const workspace = useSession((s) => s.activeWorkspace);
  const signOut = useSession((s) => s.signOut);
  return (
    <Screen>
      <Card feature>
        <LockKeyhole size={32} color={colors.danger} />
        <Text style={styles.title}>Access not available</Text>
        <Text style={styles.body}>
          {workspace
            ? `Your ${workspace.role_code.replaceAll("_", " ").toLowerCase()} role at ${workspace.tenant_name} is not available in the mobile app yet. Use the OpesInsure web console, or choose another workspace.`
            : "This area is not assigned to your account. No restricted information has been loaded."}
        </Text>
        <Text style={styles.body}>Think this is a mistake? Contact support:</Text>
        <SupportContactList />
        {status === "authenticated" && workspaces.length > 1 ? (
          <Button
            label="Choose another workspace"
            variant="secondary"
            onPress={() => router.replace("/(auth)/role")}
          />
        ) : null}
        {status === "authenticated" ? (
          <Button
            label="Sign out"
            icon={LogOut}
            onPress={async () => {
              await signOut();
              router.replace("/(auth)/sign-in");
            }}
          />
        ) : (
          <Button label="Go to sign in" onPress={() => router.replace("/(auth)/sign-in")} />
        )}
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
