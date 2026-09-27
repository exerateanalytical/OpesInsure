import React, { ReactNode, useEffect, useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { usePathname } from "expo-router";
import {
  Check,
  CloudOff,
  FileQuestion,
  History,
  LockKeyhole,
  LucideIcon,
  RefreshCw,
  X,
} from "lucide-react-native";
import { AppHeader, Button, Card, Screen, SectionTitle } from "@/components/ui";
import { TintedIcon } from "@/components/design";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { useResilience } from "@/store/resilience";
import { handleStepUpRequired } from "@/security/step-up";
import { errorMessage } from "@/components/portal/Workspace";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Shared, role-aware record detail layout (CAR-003..011, reusable by every
 * portal). One component owns loading / missing (404) / forbidden (403) /
 * offline / stale / sync-pending / API-error / retry, so each record screen
 * only renders its sections. Actions are driven by capabilities the API
 * returned; hiding an action is presentation only — the server re-checks
 * authority on every mutation.
 */

export type DetailQuery<T> = {
  data: T | undefined;
  loading: boolean;
  error: unknown;
  reload: () => void | Promise<void>;
};

type ErrShape = { status?: number; code?: string } | null | undefined;
const statusOf = (e: unknown) => (e as ErrShape)?.status;
const isOffline = (e: unknown) => {
  const code = (e as ErrShape)?.code;
  return code === "NETWORK_UNAVAILABLE" || code === "REQUEST_TIMEOUT";
};

/** Classify a load failure (exported for tests and other portals). */
export function detailErrorKind(e: unknown): "forbidden" | "missing" | "offline" | "error" {
  const s = statusOf(e);
  if (s === 403) return "forbidden";
  if (s === 404) return "missing";
  if (isOffline(e)) return "offline";
  return "error";
}

function StateCard({
  icon,
  tint,
  title,
  body,
  onRetry,
}: {
  icon: LucideIcon;
  tint: "red" | "neutral";
  title: string;
  body: string;
  onRetry?: () => void;
}) {
  const { t } = useTranslation();
  return (
    <Card style={s.panel}>
      <TintedIcon icon={icon} tint={tint} size={56} />
      <Text accessibilityRole="alert" style={s.title}>
        {title}
      </Text>
      <Text style={s.message}>{body}</Text>
      {onRetry ? <Button label={t("retry")} icon={RefreshCw} variant="secondary" onPress={onRetry} /> : null}
    </Card>
  );
}

export function DetailScreen<T>({
  title,
  subtitle,
  query,
  loadingLabel,
  isMissing,
  syncPending,
  staleAfterMs = 5 * 60_000,
  footer,
  children,
}: {
  title: string;
  subtitle?: string | ((data: T) => string | undefined);
  query: DetailQuery<T>;
  loadingLabel?: string;
  /** The API answered but the record is not there (e.g. picked from a list). */
  isMissing?: (data: T) => boolean;
  /** Local changes for this record are still queued for sync. */
  syncPending?: boolean;
  staleAfterMs?: number;
  footer?: ReactNode;
  children: (data: T) => ReactNode;
}) {
  const { t } = useTranslation();
  const online = useResilience((x) => x.online);
  const { data, loading, error, reload } = query;
  const [loadedAt, setLoadedAt] = useState<number | null>(null);
  const [, tick] = useState(0);
  const prev = useRef<T | undefined>(undefined);
  useEffect(() => {
    if (data !== undefined && data !== prev.current) setLoadedAt(Date.now());
    prev.current = data;
  }, [data]);
  useEffect(() => {
    const h = setInterval(() => tick((n) => n + 1), 60_000);
    return () => clearInterval(h);
  }, []);
  const retry = () => void reload();
  const sub = typeof subtitle === "function" ? (data !== undefined ? subtitle(data) : undefined) : subtitle;

  let body: ReactNode;
  if (data === undefined) {
    if (loading || !error) body = <LoadingState label={loadingLabel} />;
    else {
      const kind = detailErrorKind(error);
      body =
        kind === "forbidden" ? (
          <StateCard icon={LockKeyhole} tint="neutral" title={t("dtForbiddenTitle")} body={t("dtForbiddenBody")} />
        ) : kind === "missing" ? (
          <StateCard icon={FileQuestion} tint="neutral" title={t("dtMissingTitle")} body={t("dtMissingBody")} onRetry={retry} />
        ) : kind === "offline" ? (
          <StateCard icon={CloudOff} tint="red" title={t("dtOfflineTitle")} body={t("dtOfflineBody")} onRetry={retry} />
        ) : (
          <ErrorState error={error} onRetry={retry} />
        );
    }
  } else if (isMissing?.(data)) {
    body = <StateCard icon={FileQuestion} tint="neutral" title={t("dtMissingTitle")} body={t("dtMissingBody")} onRetry={retry} />;
  } else {
    const stale = !!error || !online || (loadedAt !== null && Date.now() - loadedAt > staleAfterMs);
    body = (
      <>
        {syncPending ? (
          <Banner icon={History} text={t("dtSyncPending")} />
        ) : null}
        {stale ? (
          <Banner
            icon={CloudOff}
            text={
              loadedAt
                ? t("dtStale", { time: formatDisplayDate(new Date(loadedAt).toISOString(), true) })
                : t("dtStaleNoTime")
            }
            action={t("refresh")}
            busy={loading}
            onPress={retry}
          />
        ) : null}
        {children(data)}
      </>
    );
  }
  return (
    <Screen footer={footer}>
      <AppHeader title={title} subtitle={sub} back />
      {body}
    </Screen>
  );
}

function Banner({
  icon: Icon,
  text,
  action,
  onPress,
  busy,
}: {
  icon: LucideIcon;
  text: string;
  action?: string;
  onPress?: () => void;
  busy?: boolean;
}) {
  return (
    <View style={s.banner} accessibilityRole="alert">
      <View style={s.bannerRow}>
        <Icon size={18} color={colors.warningText} />
        <Text style={s.bannerText}>{text}</Text>
      </View>
      {action ? <Button label={action} icon={RefreshCw} size="small" variant="secondary" loading={busy} onPress={onPress} /> : null}
    </View>
  );
}

/** A titled group of label/value rows; null/empty values are skipped. */
export function DetailSection({
  title,
  rows,
  children,
}: {
  title: string;
  rows?: [string, ReactNode | null | undefined][];
  children?: ReactNode;
}) {
  const visible = (rows ?? []).filter(([, v]) => v !== null && v !== undefined && v !== "");
  if (!visible.length && !children) return null;
  return (
    <>
      <SectionTitle title={title} />
      <Card>
        {visible.map(([label, value]) => (
          <View key={label} style={s.field} accessible accessibilityLabel={`${label}: ${typeof value === "string" || typeof value === "number" ? value : ""}`}>
            <Text style={s.fieldLabel}>{label}</Text>
            {typeof value === "string" || typeof value === "number" ? (
              <Text style={s.fieldValue} maxFontSizeMultiplier={1.8}>
                {value}
              </Text>
            ) : (
              value
            )}
          </View>
        ))}
        {children}
      </Card>
    </>
  );
}

/** A section the API does not expose yet — says so instead of inventing data. */
export function UnavailableSection({ title, message }: { title: string; message?: string }) {
  const { t } = useTranslation();
  return (
    <>
      <SectionTitle title={title} />
      <Card>
        <Text style={s.message}>{message ?? t("dtNotAvailableYet")}</Text>
      </Card>
    </>
  );
}

export type DetailAction = {
  key: string;
  label: string;
  icon?: LucideIcon;
  variant?: "primary" | "secondary" | "danger";
  /** Capability returned by the API for this record and user. */
  allowed: boolean;
  /** Why an allowed-but-not-ready action is disabled (e.g. reason missing). */
  disabled?: boolean;
  /** Ask before running (high-consequence actions). */
  confirm?: string;
  /** Step-up purpose; a STEP_UP_REQUIRED answer routes to re-authentication. */
  stepUpPurpose?: string;
  /** May resolve to a message that replaces `successMessage`. */
  run: () => Promise<unknown>;
  successMessage?: string;
};

/** Capability-driven action bar with inline confirmation (works on web and
 * native), busy state, step-up redirect and result notice. Callers refetch
 * in `run` so the detail/list state stays current. */
export function DetailActions({ actions, title }: { actions: DetailAction[]; title?: string }) {
  const { t } = useTranslation();
  const path = usePathname();
  const [confirming, setConfirming] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const visible = actions.filter((a) => a.allowed);
  if (!visible.length) return null;
  const exec = async (a: DetailAction) => {
    setConfirming(null);
    setBusy(a.key);
    setMsg(null);
    try {
      const out = await a.run();
      setMsg({ text: typeof out === "string" ? out : (a.successMessage ?? t("dtActionDone")), tone: "ok" });
    } catch (e) {
      if (a.stepUpPurpose && handleStepUpRequired(e, a.stepUpPurpose, path)) return;
      setMsg({ text: errorMessage(e), tone: "error" });
    } finally {
      setBusy(null);
    }
  };
  return (
    <>
      {title ? <SectionTitle title={title} /> : null}
      <Card>
        {visible.map((a) =>
          confirming === a.key ? (
            <View key={a.key} style={s.confirm}>
              <Text style={s.confirmText} accessibilityRole="alert">
                {a.confirm}
              </Text>
              <Button label={t("dtConfirm")} icon={Check} variant={a.variant === "danger" ? "danger" : "primary"} onPress={() => void exec(a)} />
              <Button label={t("cancel")} icon={X} variant="tertiary" onPress={() => setConfirming(null)} />
            </View>
          ) : (
            <Button
              key={a.key}
              label={a.label}
              icon={a.icon}
              variant={a.variant ?? "primary"}
              loading={busy === a.key}
              disabled={a.disabled || (busy !== null && busy !== a.key)}
              onPress={() => (a.confirm ? setConfirming(a.key) : void exec(a))}
            />
          ),
        )}
        {msg ? (
          <Text accessibilityRole="alert" accessibilityLiveRegion="polite" style={[s.notice, msg.tone === "error" ? s.noticeError : s.noticeOk]}>
            {msg.text}
          </Text>
        ) : null}
      </Card>
    </>
  );
}

/** Ordered history entries (decisions, status changes, approvals). */
export function DetailHistory({
  title,
  items,
}: {
  title: string;
  items: { key: string; when?: string | null; text: string; by?: string | null }[];
}) {
  if (!items.length) return null;
  return (
    <DetailSection title={title}>
      {items.map((i) => (
        <View key={i.key} style={s.historyRow}>
          <Text style={s.fieldLabel}>{i.when ? formatDisplayDate(i.when, true) : "—"}</Text>
          <Text style={s.fieldValue}>
            {i.text}
            {i.by ? ` · ${i.by}` : ""}
          </Text>
        </View>
      ))}
    </DetailSection>
  );
}

const s = StyleSheet.create({
  panel: { alignItems: "center", paddingVertical: space.x8, borderRadius: radius.feature },
  title: { ...type.cardTitle, color: colors.navy950, textAlign: "center" },
  message: { ...type.body, color: colors.neutral600 },
  banner: {
    backgroundColor: colors.warningSoft,
    borderRadius: radius.control,
    padding: space.x3,
    gap: space.x2,
  },
  bannerRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  bannerText: { ...type.meta, color: colors.warningText, flex: 1 },
  field: { paddingVertical: space.x1, gap: 2 },
  fieldLabel: { ...type.meta, color: colors.neutral600 },
  fieldValue: { ...type.body, color: colors.navy950 },
  historyRow: { paddingVertical: space.x1, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.neutral200 },
  confirm: { gap: space.x2 },
  confirmText: { ...type.body, color: colors.navy950 },
  notice: { ...type.meta, paddingTop: space.x2 },
  noticeOk: { color: colors.successText },
  noticeError: { color: colors.dangerText },
});
