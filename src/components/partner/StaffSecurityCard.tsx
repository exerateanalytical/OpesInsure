import React, { useCallback, useEffect, useState } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { LogOut, ShieldAlert, ShieldCheck, UserX } from "lucide-react-native";
import { ApiError } from "@/api/client";
import { StaffSecurityApi, type StaffSecurityStatus } from "@/api/partner";
import { Button, Card, StatusChip, TextField } from "@/components/ui";
import { SectionHeading, TintedIcon } from "@/components/design";
import { Notice, errorMessage } from "@/components/portal/Workspace";
import { usePermission } from "@/components/carrier/CarrierGate";
import { useCapability } from "@/store/capabilities";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/** Capability when the server sent it, otherwise the workspace permission. */
const useStaffGate = (action: string, permission: string) => {
  const cap = useCapability("staff", action);
  const perm = usePermission(permission);
  return cap ?? perm;
};

/**
 * Mobile audit B4: an organisation admin's view of a colleague's account
 * security with "Suspend access" and "Force re-authentication". Shown only
 * when capabilities / permissions allow; a 403/404 (not a colleague in scope,
 * older backend) hides the card entirely. The server re-checks everything.
 */
export function StaffSecurityCard({ userId, isMe }: { userId: string; isMe?: boolean }) {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const canRead = useStaffGate("view_security", "staff.security.read");
  const canSuspend = useStaffGate("suspend_access", "staff.security.manage");
  const canReauth = useStaffGate("force_reauth", "staff.security.manage");
  const [data, setData] = useState<StaffSecurityStatus | null>(null);
  const [hidden, setHidden] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState<"suspend" | "reauth" | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await StaffSecurityApi.show(userId));
    } catch (e) {
      if (e instanceof ApiError && [403, 404, 405].includes(e.status)) setHidden(true);
      else setMsg({ text: errorMessage(e), tone: "error" });
    }
  }, [userId]);
  useEffect(() => {
    if (canRead && userId) void load();
  }, [canRead, userId, load]);

  if (!canRead || hidden || !userId) return null;

  const run = (kind: "suspend" | "reauth") => {
    const suspend = kind === "suspend";
    Alert.alert(t(suspend ? "stSecSuspendTitle" : "stSecReauthTitle"), t(suspend ? "stSecSuspendConfirm" : "stSecReauthConfirm"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t(suspend ? "stSecSuspend" : "stSecReauth"),
        style: "destructive",
        onPress: async () => {
          setBusy(kind);
          setMsg(null);
          try {
            if (suspend) await StaffSecurityApi.suspendAccess(userId, reason.trim());
            else await StaffSecurityApi.forceReauth(userId, reason);
            setReason("");
            setMsg({ text: t(suspend ? "stSecSuspended" : "stSecReauthDone"), tone: "ok" });
            await load();
          } catch (e) {
            setMsg({ text: errorMessage(e), tone: "error" });
          } finally {
            setBusy(null);
          }
        },
      },
    ]);
  };

  const state = (data?.security_state ?? "").toUpperCase();
  const suspended = state === "SUSPENDED" || (data?.status ?? "").toUpperCase() === "SUSPENDED";
  const actions = !isMe && (canSuspend || canReauth);
  const tint = state === "NORMAL" ? "green" : state === "AT_RISK" ? "gold" : "red";
  const tone = state === "NORMAL" ? "success" : state === "AT_RISK" ? "warning" : "danger";
  return (
    <>
      <SectionHeading title={t("stSecTitle")} />
      <Card style={s.card}>
        {data ? (
          <>
            <View style={s.row}>
              <TintedIcon icon={state === "NORMAL" ? ShieldCheck : ShieldAlert} tint={tint} size={44} />
              <View style={s.flex}>
                <StatusChip label={td(`stSecState_${state}`, state.replaceAll("_", " "))} tone={tone} />
                <Text style={s.meta}>{t("stSecLastSignIn", { date: data.last_sign_in_at ? f.dateTime(data.last_sign_in_at) : "-" })}</Text>
                <Text style={s.meta}>{t("stSecSessions", { count: data.active_sessions })}</Text>
                {data.recent_failed_sign_ins ? <Text style={s.meta}>{t("stSecFailed", { count: data.recent_failed_sign_ins })}</Text> : null}
              </View>
            </View>
            {actions && canSuspend && !suspended ? (
              <>
                <TextField label={t("stSecReason")} hint={t("stSecReasonHint")} value={reason} onChangeText={setReason} maxLength={500} multiline />
                <Button
                  label={t("stSecSuspend")}
                  icon={UserX}
                  variant="danger"
                  loading={busy === "suspend"}
                  disabled={!!busy || reason.trim().length < 5}
                  onPress={() => run("suspend")}
                />
              </>
            ) : null}
            {actions && canReauth ? (
              <Button label={t("stSecReauth")} icon={LogOut} variant="secondary" loading={busy === "reauth"} disabled={!!busy} onPress={() => run("reauth")} />
            ) : null}
          </>
        ) : !msg ? (
          <Text style={s.meta}>{t("loading")}</Text>
        ) : null}
        <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      </Card>
    </>
  );
}

const s = StyleSheet.create({
  card: { gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1, gap: space.x1 },
  meta: { ...type.meta, color: colors.neutral600 },
});
