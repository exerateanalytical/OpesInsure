import React, { useEffect, useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { Mail, MessageCircle, Phone } from "lucide-react-native";
import { SupportContactsApi, type SupportContacts } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";

/** Support contacts as managed in the admin panel (GET
 * /public/support-contacts). Cached in memory by the API layer. */
export function useSupportContacts() {
  const [contacts, setContacts] = useState<SupportContacts | null>(null);
  useEffect(() => {
    let live = true;
    void SupportContactsApi.get().then((c) => {
      if (live) setContacts(c);
    });
    return () => {
      live = false;
    };
  }, []);
  return contacts;
}

const waUrl = (c: SupportContacts) =>
  c.whatsapp_url ??
  (c.whatsapp ? `https://wa.me/${c.whatsapp.replace(/\D/g, "")}` : null);

/**
 * Tappable email (mailto), phone (tel) and WhatsApp rows. Rows whose value
 * is null are hidden; if nothing is configured the block renders nothing.
 * `partner` shows the partnerships email instead of the general one.
 */
export function SupportContactList({
  partner = false,
  heading,
}: {
  partner?: boolean;
  heading?: string;
}) {
  const contacts = useSupportContacts();
  if (!contacts) return null;
  const email = partner ? contacts.partner_email ?? contacts.email : contacts.email;
  const wa = waUrl(contacts);
  const rows = [
    email ? { key: "email", icon: Mail, label: email, url: `mailto:${email}` } : null,
    contacts.phone
      ? { key: "phone", icon: Phone, label: contacts.phone, url: `tel:${contacts.phone.replace(/\s/g, "")}` }
      : null,
    wa ? { key: "whatsapp", icon: MessageCircle, label: `WhatsApp ${contacts.whatsapp ?? ""}`.trim(), url: wa } : null,
  ].filter((r): r is NonNullable<typeof r> => r !== null);
  if (rows.length === 0) return null;
  return (
    <View style={styles.list}>
      {heading ? <Text style={styles.heading}>{heading}</Text> : null}
      {rows.map(({ key, icon: Icon, label, url }) => (
        <Pressable
          key={key}
          accessibilityRole="link"
          accessibilityLabel={label}
          style={styles.row}
          onPress={() => void Linking.openURL(url).catch(() => undefined)}
        >
          <Icon size={18} color={colors.blue600} />
          <Text style={styles.label}>{label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  list: { gap: space.x1 },
  heading: { ...type.cardTitle, color: colors.navy950 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 40 },
  label: { ...type.label, color: colors.blue600, flex: 1 },
});
