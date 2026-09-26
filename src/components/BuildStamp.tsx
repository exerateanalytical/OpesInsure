import React from "react";
import { StyleSheet, Text } from "react-native";
import { environmentConfig } from "@/config/environment";
import * as Updates from "expo-updates";
import { colors, space, type } from "@/theme/tokens";

/**
 * "OpesInsure 1.3.0 (build 12) · update 8246536d · 25 Sept 14:08" at the foot
 * of the account screens, so anyone can confirm which over-the-air bundle the
 * app is running (embedded bundle shows "embedded").
 */
export function BuildStamp() {
  const version = environmentConfig.appVersion;
  const build = environmentConfig.buildVersion;
  const update = Updates.isEmbeddedLaunch || !Updates.updateId ? "embedded" : Updates.updateId.slice(0, 8);
  const when = Updates.createdAt
    ? new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(Updates.createdAt)
    : null;
  return (
    <Text style={styles.stamp} selectable>
      OpesInsure {version}
      {build ? ` (build ${build})` : ""} · update {update}
      {when ? ` · ${when}` : ""}
      {"\n"}Opesware Technologies
    </Text>
  );
}

const styles = StyleSheet.create({
  stamp: { ...type.meta, color: colors.neutral500, textAlign: "center", paddingTop: space.x2, paddingBottom: space.x2 },
});
