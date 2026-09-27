import React, { ReactNode } from "react";
import { StyleSheet, Text } from "react-native";
import { LockKeyhole } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { TintedIcon } from "@/components/design";
import { useSession } from "@/store/session";
import { canUseCarrierModule, CarrierModule, hasPermission } from "@/lib/carrierAccess";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export const useWorkspacePermissions = () => useSession((s) => s.activeWorkspace?.permissions ?? null);

/** True when the active workspace holds `permission` (or "*"). Presentation
 * only — the backend enforces the same permission on the request. */
export const usePermission = (permission: string) => hasPermission(useWorkspacePermissions(), permission);

/**
 * Direct-route guard for a carrier module (CAR-013): a deep link to a module
 * the workspace is not granted shows the 403 state instead of a screen that
 * would fail on load. The server still rejects the API call regardless.
 */
export function CarrierGate({ module, children }: { module: CarrierModule; children: ReactNode }) {
  const { t } = useTranslation();
  const perms = useWorkspacePermissions();
  if (canUseCarrierModule(perms, module)) return <>{children}</>;
  return (
    <Screen>
      <AppHeader title={t("dtForbiddenTitle")} back />
      <Card style={s.panel}>
        <TintedIcon icon={LockKeyhole} tint="neutral" size={56} />
        <Text accessibilityRole="alert" style={s.title}>
          {t("dtForbiddenTitle")}
        </Text>
        <Text style={s.body}>{t("dtForbiddenBody")}</Text>
      </Card>
    </Screen>
  );
}

const s = StyleSheet.create({
  panel: { alignItems: "center", paddingVertical: space.x8, borderRadius: radius.feature },
  title: { ...type.cardTitle, color: colors.navy950, textAlign: "center" },
  body: { ...type.body, color: colors.neutral600, textAlign: "center" },
});
