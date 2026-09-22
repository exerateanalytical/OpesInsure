import React, { Component, ErrorInfo, ReactNode } from "react";
import { StyleSheet, Text, View } from "react-native";
import { Button, Card } from "@/components/ui";
import { Telemetry } from "@/security/telemetry";
import { colors, space, type } from "@/theme/tokens";

export class ProductionErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  componentDidCatch(error: Error, info: ErrorInfo) {
    void Telemetry.capture("mobile.render_failure", {
      error_type: error.name,
      component_hash: info.componentStack ? "present" : "absent",
    });
  }
  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <View style={styles.page} accessibilityRole="alert">
        <Card feature>
          <Text accessibilityRole="header" style={styles.title}>Something went wrong</Text>
          <Text style={styles.body}>No payment or insurance decision has been assumed. Restart this screen and verify the latest status.</Text>
          <Button label="Try again" onPress={() => this.setState({ failed: false })} />
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
