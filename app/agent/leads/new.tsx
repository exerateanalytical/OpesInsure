import React, { useState } from "react";
import { router } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { AgentWorkspaceApi } from "@/api/partner";

const PRODUCTS = ["MOTOR", "HEALTH", "HOME", "TRAVEL", "LIFE"] as const;

export default function NewLead() {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("+237");
  const [city, setCity] = useState("");
  const [product, setProduct] = useState<(typeof PRODUCTS)[number] | null>(null);
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const valid = name.trim().length >= 3 && phone.replace(/\D/g, "").length >= 8;
  return (
    <Screen>
      <AppHeader title="New lead" subtitle="A prospect is not a client until they consent" back />
      <Card>
        <TextField label="Full name" value={name} onChangeText={setName} />
        <TextField label="Phone" keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
        <TextField label="City (optional)" value={city} onChangeText={setCity} />
        <ChoiceChips
          label="Product of interest"
          value={product}
          onChange={setProduct}
          options={PRODUCTS.map((p) => ({ value: p, label: p.charAt(0) + p.slice(1).toLowerCase() }))}
        />
        <TextField label="Notes (optional)" value={notes} onChangeText={setNotes} multiline />
        <Notice text={error} tone="error" />
        <Button
          label="Save lead"
          loading={busy}
          disabled={!valid}
          onPress={async () => {
            setBusy(true);
            setError(null);
            try {
              const lead = await AgentWorkspaceApi.createLead({
                full_name: name.trim(),
                phone_e164: phone.trim(),
                city: city.trim() || undefined,
                product_interest: product ?? undefined,
                notes: notes.trim() || undefined,
              });
              router.replace(`/agent/leads/${lead.id}`);
            } catch (e) {
              setError(errorMessage(e));
            } finally {
              setBusy(false);
            }
          }}
        />
      </Card>
    </Screen>
  );
}
