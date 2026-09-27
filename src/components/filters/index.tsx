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
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Bookmark, CalendarRange, History, X } from "lucide-react-native";
import {
  activeFilterCount,
  emptyFilters,
  FiltersSheet,
  type FilterOption,
  type FilterSection,
  type FilterValues,
} from "@/components/customer/FiltersSheet";
import { FilterButton, SearchBar } from "@/components/SearchBar";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { colors, radius, space, type } from "@/theme/tokens";
import { PERIOD_PRESETS, removeFilter } from "./core";

export {
  applyFilters,
  byDate,
  byNumber,
  byText,
  countBy,
  customPeriod,
  filterQuery,
  filtersFromParams,
  inPeriod,
  matchesText,
  normalizeText,
  optionsFrom,
  periodMatcher,
  periodRange,
  removeFilter,
  runList,
  sortRows,
  totals,
} from "./core";
export type { Matchers, Sorters } from "./core";

export { activeFilterCount, emptyFilters, FiltersSheet };
export type { FilterOption, FilterSection, FilterValues };

export type SavedFilter = { name: string; text: string; values: FilterValues };
type Stored = { saved: SavedFilter[]; recent: SavedFilter[] };

const storageKey = (list: string) => `opes.filters.${list}`;
const MAX_RECENT = 5;
const MAX_SAVED = 10;
const same = (a: SavedFilter, b: SavedFilter) =>
  a.text === b.text && JSON.stringify(a.values) === JSON.stringify(b.values);
const isEmpty = (f: SavedFilter, sections: FilterSection[]) =>
  !f.text.trim() && activeFilterCount(f.values, sections) === 0;

type Tr = (k: CopyKey, p?: Record<string, string | number>) => string;

/** Date-period section (presets + custom range, Africa/Douala days). Match with `periodMatcher(row => row.created_at)`. */
export function periodSection(t: Tr, key = "period", title?: string): FilterSection {
  return {
    key,
    kind: "period",
    single: true,
    title: title ?? t("fltPeriod"),
    options: [
      ...PERIOD_PRESETS.map((p) => ({ value: p, label: t(`fltPeriod_${p}` as CopyKey) })),
      { value: "custom", label: t("fltPeriod_custom"), icon: CalendarRange },
    ],
  };
}

/** Sort section: first option is the default order. Pair with `sortRows(rows, values.sort[0], sorters)`. */
export function sortSection(t: Tr, options: FilterOption[], key = "sort"): FilterSection {
  return { key, kind: "sort", single: true, title: t("filterSort"), options };
}

/** Pill label for a chosen value (custom periods read "2026-09-01 – 2026-09-27"). */
export function valueLabel(section: FilterSection, value: string, t: Tr) {
  if (value.startsWith("custom:")) {
    const [a, b] = value.slice(7).split("..");
    return `${a || "…"} – ${b || "…"}`;
  }
  return section.options.find((o) => o.value === value)?.label ?? (section.kind === "period" ? t("fltPeriod") : value);
}

/** Filter state for one list (keyed by `list`), with saved + recent filters persisted on the device. */
/**
 * NAV-002: the last selection per list, kept for this app session so coming
 * back to a list (back button, tab switch, remount) shows the same filters and
 * search. Session memory only - nothing about results is stored.
 */
export const SEARCH_DEBOUNCE_MS = 250;
const sessionState = new Map<string, { values: FilterValues; text: string }>();

export function useListFilters(list: string, sections: FilterSection[], initial?: { values: FilterValues; text?: string }) {
  const [values, setValues] = useState<FilterValues>(() =>
    initial ? { ...emptyFilters(sections), ...initial.values } : sessionState.get(list)?.values ?? emptyFilters(sections),
  );
  const [text, setText] = useState(() => initial?.text ?? sessionState.get(list)?.text ?? "");
  useEffect(() => {
    sessionState.set(list, { values, text });
  }, [list, values, text]);
  // Debounced search term: the field updates at once, rows re-filter 250 ms after typing stops.
  const [query, setQuery] = useState(text);
  useEffect(() => {
    if (!text) return setQuery("");
    const id = setTimeout(() => setQuery(text), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(id);
  }, [text]);
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
  /** Remove one chosen value (pill "x"). */
  const remove = useCallback((section: FilterSection, value: string) => apply(removeFilter(values, section, value)), [apply, values]);
  /** Selected value of a single-choice section (sort / period), defaulting to its first option. */
  const pick = useCallback((key: string) => values[key]?.[0] ?? sections.find((s) => s.key === key)?.options[0]?.value, [sections, values]);
  const active = count > 0 || !!text.trim();
  return { values, setValues: apply, text, setText, query, pick, remove, active, open, setOpen, count, saved: stored.saved, recent: stored.recent, reapply, save, unsave, clear, remember };
}

export type ListFilters = ReturnType<typeof useListFilters>;

/** Human summary of a selection ("Allianz · Motor · Business"). */
function summary(f: SavedFilter, sections: FilterSection[]) {
  const labels = sections.flatMap((s) =>
    (f.values[s.key] ?? []).filter((v) => !(s.single && v === s.options[0]?.value)).map((v) => (v.startsWith("custom:") ? v.slice(7).replace("..", " – ") : s.options.find((o) => o.value === v)?.label ?? v)),
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
  resultCount,
  filled,
}: {
  filters: ListFilters;
  sections: FilterSection[];
  count: (v: FilterValues) => number;
  placeholder?: string;
  subtitle?: string;
  /** Rows shown after filters + search: renders "12 results" under the toolbar. */
  resultCount?: number;
  /** Soft grey search field (customer screens). */
  filled?: boolean;
}) {
  const { t } = useTranslation();
  const f = filters;
  const active = useMemo(
    () =>
      sections.flatMap((s) =>
        (f.values[s.key] ?? [])
          .filter((v) => !(s.single && v === s.options[0]?.value))
          .map((v) => ({ section: s, value: v, label: valueLabel(s, v, t) })),
      ),
    [f.values, sections, t],
  );
  const current: SavedFilter = { name: "", text: f.text, values: f.values };
  const canSave = !isEmpty(current, sections) && !f.saved.some((s) => same(s, current));
  const recent = f.recent.filter((r) => !f.saved.some((s) => same(s, r)) && !same(r, current)).slice(0, 3);
  // Saved + recent filters live inside the sheet (no extra buttons on the page).
  const memory =
    f.saved.length || recent.length || canSave ? (
      <View style={st.memory}>
        {f.saved.map((s) => (
          <Pill key={`s:${summary(s, sections)}`} icon={Bookmark} label={s.name || summary(s, sections)} onPress={() => { f.reapply(s); f.setOpen(false); }} onRemove={() => f.unsave(s)} removeLabel={t("fltUnsave", { name: s.name || summary(s, sections) })} />
        ))}
        {recent.map((r) => (
          <Pill key={`r:${summary(r, sections)}`} icon={History} label={summary(r, sections)} onPress={() => { f.reapply(r); f.setOpen(false); }} />
        ))}
        {canSave ? <Pill icon={Bookmark} label={t("fltSave")} onPress={() => f.save(summary(current, sections))} /> : null}
      </View>
    ) : null;
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
        filled={filled}
      />
      {/* One filtering type per page: only the removable active pills (one scroll line) sit under the search. */}
      {active.length ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.row} accessibilityLabel={t("fltActive")}>
          {active.map((a) => (
            <Pill
              key={`${a.section.key}:${a.value}`}
              on
              label={a.label}
              removeLabel={t("fltRemove", { name: a.label })}
              onRemove={() => f.remove(a.section, a.value)}
            />
          ))}
          <Pill label={t("fltClearAll")} onPress={f.clear} />
        </ScrollView>
      ) : null}
      {resultCount !== undefined ? (
        <Text accessibilityLiveRegion="polite" style={st.results}>
          {t(resultCount === 1 ? "fltResultsOne" : "fltResults", { count: resultCount })}
        </Text>
      ) : null}
      <FiltersSheet visible={f.open} onClose={() => f.setOpen(false)} sections={sections} value={f.values} onApply={f.setValues} count={count} subtitle={subtitle ?? t("fltSheetSubtitle")} header={memory} />
    </View>
  );
}

/**
 * Pages whose only refinement is an order (offer comparisons, renewal offers):
 * "Sort: Price" plus the single filter icon; the options live in the sheet.
 */
export function SortFilter<K extends string>({ value, options, onChange, count }: { value: K; options: { value: K; label: string; icon?: FilterOption["icon"] }[]; onChange: (v: K) => void; count: number }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const sections = useMemo(() => [sortSection(t, options)], [options, t]);
  const current = options.find((o) => o.value === value)?.label ?? "";
  return (
    <View style={st.sortRow}>
      <Text style={st.sortText}>{`${t("filterSort")}: ${current}`}</Text>
      <FilterButton onPress={() => setOpen(true)} label={t("filtersTitle")} count={value === options[0]?.value ? 0 : 1} />
      <FiltersSheet visible={open} onClose={() => setOpen(false)} sections={sections} value={{ sort: [value] }} onApply={(v) => onChange((v.sort?.[0] ?? options[0]?.value) as K)} count={() => count} />
    </View>
  );
}

const st = StyleSheet.create({
  sortRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  sortText: { ...type.label, color: colors.navy950, flex: 1 },
  wrap: { gap: space.x2 },
  row: { flexDirection: "row", gap: space.x2 },
  memory: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  pill: { flexDirection: "row", alignItems: "center", minHeight: 36, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.neutral200, backgroundColor: colors.white, maxWidth: "100%" },
  pillOn: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  pillMain: { flexDirection: "row", alignItems: "center", gap: 6, paddingLeft: space.x3, paddingRight: space.x2, minHeight: 36, flexShrink: 1 },
  pillX: { paddingRight: space.x3, paddingLeft: 2, minHeight: 36, justifyContent: "center" },
  pillText: { ...type.meta, color: colors.neutral700, flexShrink: 1 },
  pillTextOn: { color: colors.blue700 },
  results: { ...type.meta, color: colors.neutral600 },
});
