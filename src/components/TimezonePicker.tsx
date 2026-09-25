import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { Card } from "@/components/ui";
import { MasterSelectField } from "@/components/masterData/MasterSelectField";
import { SettingsApi } from "@/api/client";
import { translate, useTranslation } from "@/i18n";
import { useTimezone } from "@/store/timezone";
import type { MasterValue } from "@/lib/masterFields";
import { colors, type } from "@/theme/tokens";

const DEFAULT = "__DEFAULT__";

/** Timezones (GET /settings/timezones) as picker values; "Africa/Douala" -> "Africa / Douala (CM)". */
export function timezoneValues(rows: { timezone: string; countries?: string[] }[], defaultLabel: { en: string; fr: string }): MasterValue[] {
  return [
    { code: DEFAULT, label: defaultLabel, common: true },
    ...rows.map((r) => {
      const text = `${r.timezone.replaceAll("_", " ").replaceAll("/", " / ")}${r.countries?.length ? ` (${r.countries.join(", ")})` : ""}`;
      return { code: r.timezone, label: { en: text, fr: text }, aliases: r.countries ?? [], common: r.timezone.startsWith("Africa/") };
    }),
  ];
}

/** Profile setting: display time zone (PATCH /me/settings), organisation default when unset. */
export function TimezonePicker() {
  const { t, language } = useTranslation();
  const own = useTimezone((s) => s.own);
  const business = useTimezone((s) => s.business);
  const current = useTimezone((s) => s.timezone);
  const save = useTimezone((s) => s.save);
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null);
  const defaultText = { en: translate("en", "timezoneDefault", { tz: business }), fr: translate("fr", "timezoneDefault", { tz: business }) };

  return (
    <Card>
      <Text style={s.title}>{t("timezoneTitle")}</Text>
      <Text style={s.meta}>{t("timezoneBody")}</Text>
      <MasterSelectField
        label={t("timezoneTitle")}
        required
        domain="settings"
        list="timezones"
        suggest={false}
        otherAllowed={false}
        value={own ?? DEFAULT}
        loaderKey={`${business}|${language}`}
        loader={async () => timezoneValues(await SettingsApi.timezones(), defaultText)}
        onChange={(v) => {
          setNotice(null);
          save(v === DEFAULT ? null : v)
            .then(() => setNotice({ ok: true, text: t("timezoneSaved") }))
            .catch((e: unknown) => setNotice({ ok: false, text: e instanceof Error ? e.message : t("actionFailed") }));
        }}
      />
      <Text style={s.meta}>{t("timezoneCurrent", { tz: current })}</Text>
      {notice ? <Text accessibilityLiveRegion="polite" style={notice.ok ? s.ok : s.error}>{notice.text}</Text> : null}
    </Card>
  );
}

const s = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  ok: { ...type.meta, color: colors.successText },
  error: { ...type.meta, color: colors.dangerText },
});
