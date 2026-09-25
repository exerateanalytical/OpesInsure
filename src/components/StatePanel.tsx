import React, { ReactNode } from "react";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { CloudOff, Inbox, RefreshCw } from "lucide-react-native";
import { Button, Card } from "./ui";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { apiErrorCopyKey } from "@/lib/apiErrors";

export function EmptyState({
  title,
  message,
  action,
  onPress,
}: {
  title: string;
  message: string;
  action?: string;
  onPress?: () => void;
}) {
  return (
    <Card style={styles.panel}>
      <View style={styles.icon}>
        <Inbox size={24} color={colors.neutral600} />
      </View>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.message}>{message}</Text>
      {action && <Button label={action} variant="secondary" onPress={onPress} />}
    </Card>
  );
}

/** Load failure with retry. A known backend code (STALE_RECORD,
 * INTEGRATION_UNAVAILABLE, ...) or a 404 shows its specific message. */
export function ErrorState({ onRetry, error }: { onRetry?: () => void; error?: unknown }) {
  const { t } = useTranslation();
  const e = error as { code?: string; status?: number; message?: string } | null | undefined;
  const specific = e && (apiErrorCopyKey(e.code) || e.status === 404) ? e.message : null;
  return (
    <Card style={styles.panel}>
      <View style={styles.icon}>
        <CloudOff size={24} color={colors.dangerText} />
      </View>
      <Text accessibilityRole="alert" style={styles.title}>
        {t("loadErrorTitle")}
      </Text>
      <Text style={styles.message}>{specific || t("loadErrorBody")}</Text>
      {onRetry ? <Button label={t("retry")} icon={RefreshCw} variant="secondary" onPress={onRetry} /> : null}
    </Card>
  );
}

export function LoadingState({ label: custom }: { label?: string }) {
  const { t } = useTranslation();
  const label = custom ?? t("loading");
  return (
    <View
      accessibilityRole="progressbar"
      accessibilityLabel={label}
      style={styles.loading}
    >
      <ActivityIndicator size="large" color={colors.blue600} />
      <Text style={styles.loadingText}>{label}</Text>
    </View>
  );
}

/**
 * Renders loading / error+retry / empty states for a useLoad() result, and
 * the children only once data is present.
 */
export function StatePanel<T>({
  loading,
  error,
  data,
  onRetry,
  isEmpty,
  emptyTitle,
  emptyMessage,
  loadingLabel,
  children,
}: {
  loading: boolean;
  error: unknown;
  data: T | undefined;
  onRetry: () => void;
  isEmpty?: (data: T) => boolean;
  emptyTitle?: string;
  emptyMessage?: string;
  loadingLabel?: string;
  children: (data: T) => ReactNode;
}) {
  const { t } = useTranslation();
  if (loading && data === undefined) return <LoadingState label={loadingLabel} />;
  if (error && data === undefined) return <ErrorState onRetry={onRetry} error={error} />;
  if (data === undefined) return <LoadingState label={loadingLabel} />;
  const empty = isEmpty
    ? isEmpty(data)
    : Array.isArray(data) && data.length === 0;
  if (empty)
    return (
      <EmptyState
        title={emptyTitle ?? t("emptyDefaultTitle")}
        message={emptyMessage ?? t("emptyDefaultBody")}
        action={t("refresh")}
        onPress={onRetry}
      />
    );
  return <>{children(data)}</>;
}

const styles = StyleSheet.create({
  panel: { alignItems: "center", paddingVertical: space.x8 },
  icon: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: colors.neutral100,
    alignItems: "center",
    justifyContent: "center",
  },
  title: { ...type.cardTitle, color: colors.navy950, textAlign: "center" },
  message: { ...type.body, color: colors.neutral600, textAlign: "center" },
  loading: {
    alignItems: "center",
    justifyContent: "center",
    paddingVertical: space.x10,
    gap: space.x3,
  },
  loadingText: { ...type.meta, color: colors.neutral600 },
});
