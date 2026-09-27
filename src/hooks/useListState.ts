import { useCallback, useRef } from "react";
import type { FlatList, NativeScrollEvent, NativeSyntheticEvent, ScrollView } from "react-native";
import { useFocusEffect } from "expo-router";

/**
 * NAV-002: list -> detail -> back keeps the list where it was and current.
 * - Scroll offset is remembered per list key for this app session and restored
 *   when the list remounts (tab switch, deep link back, reload).
 * - `reload` runs whenever the list regains focus (not on the first focus), so a
 *   mutation made on the detail page shows up on return.
 * Filters and search are kept by useListFilters (src/components/filters).
 */
const offsets = new Map<string, number>();

export function useListState(key: string, reload?: () => unknown) {
  const ref = useRef<FlatList<any> & ScrollView>(null);
  const restored = useRef(false);
  const first = useRef(true);

  useFocusEffect(
    useCallback(() => {
      if (first.current) first.current = false;
      else void reload?.();
    }, [reload]),
  );

  const onScroll = useCallback(
    (e: NativeSyntheticEvent<NativeScrollEvent>) => {
      offsets.set(key, e.nativeEvent.contentOffset.y);
    },
    [key],
  );

  /** Restores once, after the content is tall enough to scroll to the saved offset. */
  const onContentSizeChange = useCallback(
    (_w: number, h: number) => {
      const y = offsets.get(key);
      if (restored.current || !y || h < y) return;
      restored.current = true;
      const node = ref.current as unknown as { scrollToOffset?: (o: { offset: number; animated: boolean }) => void; scrollTo?: (o: { y: number; animated: boolean }) => void } | null;
      if (node?.scrollToOffset) node.scrollToOffset({ offset: y, animated: false });
      else node?.scrollTo?.({ y, animated: false });
    },
    [key],
  );

  return { ref, onScroll, onContentSizeChange, scrollEventThrottle: 100 } as const;
}
