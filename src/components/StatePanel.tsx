import React, { ReactNode } from "react";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { CloudOff, Inbox, RefreshCw } from "lucide-react-native";
import { Button, Card } from "./ui";
import { colors, space, type } from "@/theme/tokens";

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

export function ErrorState({ onRetry }: { onRetry?: () => void }) {
  return (
    <Card style={styles.panel}>
      <View style={styles.icon}>
        <CloudOff size={24} color={colors.dangerText} />
      </View>
      <Text accessibilityRole="alert" style={styles.title}>
        We could not load this information
      </Text>
      <Text style={styles.message}>
        Your information is safe. Check your connection and try again.
      </Text>
      <Button label="Retry" icon={RefreshCw} variant="secondary" onPress={onRetry} />
    </Card>
  );
}

export function LoadingState({ label = "Loading…" }: { label?: string }) {
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
  emptyTitle = "Nothing here yet",
  emptyMessage = "New items will appear here as soon as they are available.",
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
  if (loading && data === undefined) return <LoadingState label={loadingLabel} />;
  if (error && data === undefined) return <ErrorState onRetry={onRetry} />;
  if (data === undefined) return <LoadingState label={loadingLabel} />;
  const empty = isEmpty
    ? isEmpty(data)
    : Array.isArray(data) && data.length === 0;
  if (empty)
    return (
      <EmptyState
        title={emptyTitle}
        message={emptyMessage}
        action="Refresh"
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
