import React, { useState } from "react";
import {
  ActivityIndicator,
  Keyboard,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { usePathname } from "expo-router";
import { CircleCheck, Flag, X } from "lucide-react-native";
import { colors, radius, space, type } from "@/theme/tokens";
import { IssueReportApi } from "@/api/client";

/**
 * A always-available "something is wrong on this screen" affordance, so a
 * problem can be flagged the moment it happens instead of being described
 * from memory later. Reports land in mobile_issue_reports (see
 * MobileIssueReportController) and are reviewable from the admin panel.
 */
export function IssueReportButton() {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string>();

  const close = () => {
    setOpen(false);
    setNote("");
    setSent(false);
    setError(undefined);
  };

  const submit = async () => {
    if (note.trim().length < 3) {
      setError("Say a little more about what happened.");
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      await IssueReportApi.report({ route: pathname, note: note.trim() });
      setSent(true);
      setTimeout(close, 1400);
    } catch {
      setError("Could not send this report. Check your connection and try again.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Report a problem on this screen"
        hitSlop={8}
        onPress={() => setOpen(true)}
        style={({ pressed }) => [styles.fab, pressed && styles.fabPressed]}
      >
        <Flag size={20} color={colors.white} />
      </Pressable>
      <Modal visible={open} transparent animationType="slide" onRequestClose={close}>
        <Pressable style={styles.backdrop} onPress={close} />
        <KeyboardAvoidingView
          behavior={Platform.OS === "ios" ? "padding" : undefined}
          style={styles.sheetWrap}
        >
          <View style={styles.sheet}>
            {sent ? (
              <View style={styles.sentState}>
                <CircleCheck size={32} color={colors.success} />
                <Text style={styles.sentText}>Thanks — this has been reported.</Text>
              </View>
            ) : (
              <>
                <View style={styles.sheetHeader}>
                  <Text style={styles.sheetTitle}>Report a problem</Text>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="Close"
                    hitSlop={8}
                    onPress={close}
                  >
                    <X size={22} color={colors.neutral500} />
                  </Pressable>
                </View>
                <Text style={styles.screenTag} numberOfLines={1}>
                  This screen: {pathname || "/"}
                </Text>
                <TextInput
                  accessibilityLabel="What went wrong"
                  placeholder="What went wrong, or what looks broken?"
                  placeholderTextColor={colors.neutral400}
                  multiline
                  numberOfLines={4}
                  value={note}
                  onChangeText={setNote}
                  style={styles.input}
                  onSubmitEditing={Keyboard.dismiss}
                />
                {error ? <Text style={styles.error}>{error}</Text> : null}
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Send report"
                  disabled={busy}
                  onPress={() => void submit()}
                  style={({ pressed }) => [
                    styles.submit,
                    pressed && styles.fabPressed,
                    busy && styles.disabled,
                  ]}
                >
                  {busy ? (
                    <ActivityIndicator color={colors.white} />
                  ) : (
                    <Text style={styles.submitText}>Send report</Text>
                  )}
                </Pressable>
              </>
            )}
          </View>
        </KeyboardAvoidingView>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  fab: {
    position: "absolute",
    right: space.x4,
    bottom: 110,
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.navy800,
    alignItems: "center",
    justifyContent: "center",
    shadowColor: "#000",
    shadowOpacity: 0.25,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
    elevation: 4,
    zIndex: 50,
  },
  fabPressed: { opacity: 0.85 },
  disabled: { opacity: 0.6 },
  backdrop: { flex: 1, backgroundColor: "rgba(8,10,15,0.4)" },
  sheetWrap: { justifyContent: "flex-end" },
  sheet: {
    backgroundColor: colors.white,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    padding: space.x5,
    gap: space.x3,
  },
  sheetHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  sheetTitle: { ...type.cardTitle, color: colors.navy950 },
  screenTag: { ...type.caption, color: colors.neutral500 },
  input: {
    minHeight: 96,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    padding: space.x3,
    textAlignVertical: "top",
    fontSize: 16,
    fontFamily: "Inter_400Regular",
    color: colors.navy950,
  },
  error: { ...type.meta, color: colors.dangerText },
  submit: {
    minHeight: 50,
    borderRadius: radius.control,
    backgroundColor: colors.blue600,
    alignItems: "center",
    justifyContent: "center",
  },
  submitText: { ...type.label, color: colors.white },
  sentState: { alignItems: "center", gap: space.x3, paddingVertical: space.x6 },
  sentText: { ...type.body, color: colors.navy950 },
});
