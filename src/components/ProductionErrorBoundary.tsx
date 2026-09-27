import React, { Component, ErrorInfo, ReactNode } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Button, Card } from "@/components/ui";
import { Telemetry } from "@/security/telemetry";
import { CrashReporting } from "@/security/crashReporting";
import { colors, space, type } from "@/theme/tokens";
import { translateNow } from "@/i18n";

export class ProductionErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error: Error, info: ErrorInfo) {
    // Must be a backend-allowed event code: the old "mobile.render_failure"
    // was rejected (422) so render crashes never reached operations.
    void Telemetry.capture("APP_CRASHED", {
      reason: `render:${error.name}${info.componentStack ? "" : ":nostack"}`,
    });
    CrashReporting.captureException(error);
  }
  private goHome = () => {
    this.setState({ failed: false });
    try {
      router.replace("/");
    } catch {
      // Router not ready yet: the reset above re-renders the current tree.
    }
  };
  render() {
    if (!this.state.failed) return this.props.children;
    // A class component cannot use hooks: read the current language directly.
    return (
      <View style={styles.page} accessibilityRole="alert">
        <Card feature>
          <Text accessibilityRole="header" style={styles.title}>{translateNow("crashTitle")}</Text>
          <Text style={styles.body}>{translateNow("crashBody")}</Text>
          <Button label={translateNow("tryAgain")} onPress={() => this.setState({ failed: false })} />
          <Button label={translateNow("goHome")} variant="secondary" onPress={this.goHome} />
        </Card>
      </View>
    );
  }
}
const styles = StyleSheet.create({
  page: { flex: 1, justifyContent: "center", padding: space.x5, backgroundColor: colors.neutral50 },
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
