/**
 * Universal list-filter framework (FLT-001..006, CUST-001/002).
 *
 * One toolbar for every partner / carrier / customer list: search field with
 * the filter button, the shared FiltersSheet (src/components/customer), active
 * filters as removable chips, and saved + recent filters kept on the device.
 *
 * Filters are a presentation aid only: the server scopes every list to what the
 * caller may see (origin lock, book scope, broker company). `filterQuery` turns
 * the selection into query params so endpoints that support server-side
 * filtering receive them; `applyFilters` narrows what was already returned.
 * A saved filter stores only the selection (never results), so reapplying it
 * can never widen access.
 */
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Bookmark, History, X } from "lucide-react-native";
import {
  activeFilterCount,
  emptyFilters,
  FiltersSheet,
  type FilterOption,
  type FilterSection,
  type FilterValues,
} from "@/components/customer/FiltersSheet";
import { SearchBar } from "@/components/SearchBar";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export { activeFilterCount, emptyFilters, FiltersSheet };
export type { FilterOption, FilterSection, FilterValues };

export type SavedFilter = { name: string; text: string; values: FilterValues };
type Stored = { saved: SavedFilter[]; recent: SavedFilter[] };

/** Row predicate per section key: true when `row` matches the chosen `value`. */
export type Matchers<T> = Record<string, (row: T, value: string) => boolean>;

const storageKey = (list: string) => `opes.filters.${list}`;
const MAX_RECENT = 5;
const MAX_SAVED = 10;
const same = (a: SavedFilter, b: SavedFilter) =>
  a.text === b.text && JSON.stringify(a.values) === JSON.stringify(b.values);
const isEmpty = (f: SavedFilter, sections: FilterSection[]) =>
  !f.text.trim() && activeFilterCount(f.values, sections) === 0;

/** Distinct options from loaded rows (insurers, products, cities...), sorted by label. */
export function optionsFrom<T>(rows: T[], pick: (row: T) => { value: string | null | undefined; label?: string | null } | null): FilterOption[] {
  const seen = new Map<string, FilterOption>();
  for (const r of rows) {
    const o = pick(r);
    if (o?.value && !seen.has(o.value)) seen.set(o.value, { value: o.value, label: o.label || o.value });
  }
  return [...seen.values()].sort((a, b) => a.label.localeCompare(b.label));
}

/**
 * Rows matching the free text (any of `text(row)`) and every section with a
 * selection (OR inside a section, AND across sections). Sections without a
 * matcher are server-side only and ignored here.
 */
export function applyFilters<T>(rows: T[], values: FilterValues, matchers: Matchers<T>, text = "", haystack?: (row: T) => (string | null | undefined)[]): T[] {
  const needle = text.trim().toLowerCase();
  return rows.filter((row) => {
    if (needle && haystack && !haystack(row).some((v) => v && v.toLowerCase().includes(needle))) return false;
    return Object.entries(values).every(([key, chosen]) => {
      const m = matchers[key];
      if (!m || !chosen?.length || chosen[0] === "ALL") return true;
      return chosen.some((v) => m(row, v));
    });
  });
}

/** Query string for server-side filtering (`carrier_id=a&carrier_id=b&q=...`). Empty when nothing is chosen. */
export function filterQuery(values: FilterValues, text = ""): string {
  const qs = new URLSearchParams();
  if (text.trim()) qs.set("q", text.trim());
  for (const [k, vs] of Object.entries(values)) for (const v of vs ?? []) if (v && v !== "ALL") qs.append(k, v);
  const s = qs.toString();
  return s ? `?${s}` : "";
}

/** Filter state for one list (keyed by `list`), with saved + recent filters persisted on the device. */
/**
 * NAV-002: the last selection per list, kept for this app session so coming
 * back to a list (back button, tab switch, remount) shows the same filters and
 * search. Session memory only - nothing about results is stored.
 */
const sessionState = new Map<string, { values: FilterValues; text: string }>();

/** DASH-003: `?f_status=DUE,OVERDUE&q=...` pre-filters a list opened from a KPI. */
export function filtersFromParams(params: Record<string, string | string[] | undefined>): { values: FilterValues; text?: string } | undefined {
  const values: FilterValues = {};
  for (const [k, v] of Object.entries(params)) {
    if (!k.startsWith("f_") || v == null) continue;
    values[k.slice(2)] = (Array.isArray(v) ? v : String(v).split(",")).filter(Boolean);
  }
  const text = typeof params.q === "string" ? params.q : undefined;
  return Object.keys(values).length || text ? { values, text } : undefined;
}

export function useListFilters(list: string, sections: FilterSection[], initial?: { values: FilterValues; text?: string }) {
  const [values, setValues] = useState<FilterValues>(() =>
    initial ? { ...emptyFilters(sections), ...initial.values } : sessionState.get(list)?.values ?? emptyFilters(sections),
  );
  const [text, setText] = useState(() => initial?.text ?? sessionState.get(list)?.text ?? "");
  useEffect(() => {
    sessionState.set(list, { values, text });
  }, [list, values, text]);
  const [open, setOpen] = useState(false);
  const [stored, setStored] = useState<Stored>({ saved: [], recent: [] });
  useEffect(() => {
    let alive = true;
    AsyncStorage.getItem(storageKey(list))
      .then((raw) => {
        if (!alive || !raw) return;
        const v = JSON.parse(raw) as Partial<Stored>;
        setStored({ saved: v.saved ?? [], recent: v.recent ?? [] });
      })
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [list]);
  const persist = useCallback(
    (next: Stored) => {
      setStored(next);
      AsyncStorage.setItem(storageKey(list), JSON.stringify(next)).catch(() => undefined);
    },
    [list],
  );
  const remember = useCallback(
    (f: SavedFilter) => {
      if (isEmpty(f, sections)) return;
      setStored((cur) => {
        const next = { ...cur, recent: [f, ...cur.recent.filter((r) => !same(r, f))].slice(0, MAX_RECENT) };
        AsyncStorage.setItem(storageKey(list), JSON.stringify(next)).catch(() => undefined);
        return next;
      });
    },
    [list, sections],
  );
  const apply = useCallback(
    (v: FilterValues) => {
      setValues(v);
      remember({ name: "", text, values: v });
    },
    [remember, text],
  );
  const reapply = useCallback((f: SavedFilter) => {
    // Only keys this list still offers are restored (stale saved keys drop out).
    const keys = new Set(sections.map((s) => s.key));
    setValues({ ...emptyFilters(sections), ...Object.fromEntries(Object.entries(f.values).filter(([k]) => keys.has(k))) });
    setText(f.text);
  }, [sections]);
  const save = useCallback(
    (name: string) => {
      const f = { name, text, values };
      if (isEmpty(f, sections)) return;
      persist({ ...stored, saved: [f, ...stored.saved.filter((s) => !same(s, f))].slice(0, MAX_SAVED) });
    },
    [persist, sections, stored, text, values],
  );
  const unsave = useCallback((f: SavedFilter) => persist({ ...stored, saved: stored.saved.filter((s) => !same(s, f)) }), [persist, stored]);
  const clear = useCallback(() => {
    setValues(emptyFilters(sections));
    setText("");
  }, [sections]);
  const count = activeFilterCount(values, sections);
  return { values, setValues: apply, text, setText, open, setOpen, count, saved: stored.saved, recent: stored.recent, reapply, save, unsave, clear, remember };
}

export type ListFilters = ReturnType<typeof useListFilters>;

/** Human summary of a selection ("Allianz · Motor · Business"). */
function summary(f: SavedFilter, sections: FilterSection[]) {
  const labels = sections.flatMap((s) =>
    (f.values[s.key] ?? []).filter((v) => !(s.single && v === s.options[0]?.value)).map((v) => s.options.find((o) => o.value === v)?.label ?? v),
  );
  return [f.text.trim() ? `"${f.text.trim()}"` : null, ...labels].filter(Boolean).join(" · ");
}

function Pill({ label, icon: Icon, onPress, onRemove, removeLabel, on }: { label: string; icon?: typeof X; onPress?: () => void; onRemove?: () => void; removeLabel?: string; on?: boolean }) {
  return (
    <View style={[st.pill, on && st.pillOn]}>
      <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress} disabled={!onPress} hitSlop={4} style={st.pillMain}>
        {Icon ? <Icon size={14} color={on ? colors.blue700 : colors.neutral700} /> : null}
        <Text numberOfLines={1} style={[st.pillText, on && st.pillTextOn]}>{label}</Text>
      </Pressable>
      {onRemove ? (
        <Pressable accessibilityRole="button" accessibilityLabel={removeLabel ?? label} onPress={onRemove} hitSlop={8} style={st.pillX}>
          <X size={14} color={on ? colors.blue700 : colors.neutral700} />
        </Pressable>
      ) : null}
    </View>
  );
}

/**
 * Search + filter button + active-filter chips + saved/recent filters, and the
 * FiltersSheet itself. `count` returns the live result count for a draft
 * selection (shown in the sheet's bottom bar).
 */
export function FilterToolbar({
  filters,
  sections,
  count,
  placeholder,
  subtitle,
}: {
  filters: ListFilters;
  sections: FilterSection[];
  count: (v: FilterValues) => number;
  placeholder?: string;
  subtitle?: string;
}) {
  const { t } = useTranslation();
  const f = filters;
  const active = useMemo(
    () =>
      sections.flatMap((s) =>
        (f.values[s.key] ?? [])
          .filter((v) => !(s.single && v === s.options[0]?.value))
          .map((v) => ({ section: s, value: v, label: s.options.find((o) => o.value === v)?.label ?? v })),
      ),
    [f.values, sections],
  );
  const current: SavedFilter = { name: "", text: f.text, values: f.values };
  const canSave = !isEmpty(current, sections) && !f.saved.some((s) => same(s, current));
  const recent = f.recent.filter((r) => !f.saved.some((s) => same(s, r)) && !same(r, current)).slice(0, 3);
  return (
    <View style={st.wrap}>
      <SearchBar
        value={f.text}
        onChangeText={f.setText}
        onSubmit={() => f.remember(current)}
        placeholder={placeholder ?? t("fltSearchPlaceholder")}
        label={placeholder ?? t("fltSearchPlaceholder")}
        clearLabel={t("clearSearch")}
        onFilter={() => f.setOpen(true)}
        filterLabel={t("filtersTitle")}
        filterCount={f.count}
      />
      {active.length ? (
        <View style={st.row} accessibilityLabel={t("fltActive")}>
          {active.map((a) => (
            <Pill
              key={`${a.section.key}:${a.value}`}
              on
              label={a.label}
              removeLabel={t("fltRemove", { name: a.label })}
              onRemove={() =>
                f.setValues({
                  ...f.values,
                  [a.section.key]: a.section.single ? [a.section.options[0]?.value ?? ""] : (f.values[a.section.key] ?? []).filter((v) => v !== a.value),
                })
              }
            />
          ))}
          <Pill label={t("fltClearAll")} onPress={f.clear} />
        </View>
      ) : null}
      {f.saved.length || recent.length || canSave ? (
        <View style={st.row}>
          {f.saved.map((s) => (
            <Pill key={`s:${summary(s, sections)}`} icon={Bookmark} label={s.name || summary(s, sections)} onPress={() => f.reapply(s)} onRemove={() => f.unsave(s)} removeLabel={t("fltUnsave", { name: s.name || summary(s, sections) })} />
          ))}
          {recent.map((r) => (
            <Pill key={`r:${summary(r, sections)}`} icon={History} label={summary(r, sections)} onPress={() => f.reapply(r)} />
          ))}
          {canSave ? <Pill icon={Bookmark} label={t("fltSave")} onPress={() => f.save(summary(current, sections))} /> : null}
        </View>
      ) : null}
      <FiltersSheet visible={f.open} onClose={() => f.setOpen(false)} sections={sections} value={f.values} onApply={f.setValues} count={count} subtitle={subtitle ?? t("fltSheetSubtitle")} />
    </View>
  );
}

const st = StyleSheet.create({
  wrap: { gap: space.x2 },
  row: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  pill: { flexDirection: "row", alignItems: "center", minHeight: 36, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, maxWidth: "100%" },
  pillOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  pillMain: { flexDirection: "row", alignItems: "center", gap: 6, paddingLeft: space.x3, paddingRight: space.x2, minHeight: 36, flexShrink: 1 },
  pillX: { paddingRight: space.x3, paddingLeft: 2, minHeight: 36, justifyContent: "center" },
  pillText: { ...type.meta, color: colors.neutral700, flexShrink: 1 },
  pillTextOn: { color: colors.blue700 },
});
