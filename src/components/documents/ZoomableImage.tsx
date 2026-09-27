import React, { useMemo, useRef } from "react";
import { Animated, Image, PanResponder, StyleSheet, View, type GestureResponderEvent } from "react-native";

const MIN = 1;
const MAX = 5;
const distance = (e: GestureResponderEvent) => {
  const [a, b] = e.nativeEvent.touches;
  return a && b ? Math.hypot(a.pageX - b.pageX, a.pageY - b.pageY) : 0;
};

/** Native image fitted to width with pinch-zoom, pan and double-tap reset (no WebView). */
export function ZoomableImage({ uri, accessibilityLabel }: { uri: string; accessibilityLabel?: string }) {
  const scale = useRef(new Animated.Value(1)).current;
  const tx = useRef(new Animated.Value(0)).current;
  const ty = useRef(new Animated.Value(0)).current;
  const state = useRef({ scale: 1, x: 0, y: 0, startDist: 0, startScale: 1, lastTap: 0 }).current;

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => true,
        onMoveShouldSetPanResponder: (e, g) => e.nativeEvent.touches.length > 1 || (state.scale > 1 && (Math.abs(g.dx) > 2 || Math.abs(g.dy) > 2)),
        onPanResponderGrant: (e) => {
          state.startDist = distance(e);
          state.startScale = state.scale;
          const now = Date.now();
          if (e.nativeEvent.touches.length === 1 && now - state.lastTap < 280) {
            const next = state.scale > 1 ? 1 : 2.5;
            state.scale = next;
            state.x = 0;
            state.y = 0;
            Animated.parallel([
              Animated.spring(scale, { toValue: next, useNativeDriver: true }),
              Animated.spring(tx, { toValue: 0, useNativeDriver: true }),
              Animated.spring(ty, { toValue: 0, useNativeDriver: true }),
            ]).start();
          }
          state.lastTap = now;
        },
        onPanResponderMove: (e, g) => {
          if (e.nativeEvent.touches.length > 1) {
            const d = distance(e);
            if (!state.startDist) state.startDist = d;
            const s = Math.min(MAX, Math.max(MIN, (state.startScale * d) / (state.startDist || d)));
            scale.setValue(s);
            state.scale = s;
          } else if (state.scale > 1) {
            tx.setValue(state.x + g.dx);
            ty.setValue(state.y + g.dy);
          }
        },
        onPanResponderRelease: (_e, g) => {
          if (state.scale <= 1.01) {
            state.scale = 1;
            state.x = 0;
            state.y = 0;
            Animated.parallel([
              Animated.spring(scale, { toValue: 1, useNativeDriver: true }),
              Animated.spring(tx, { toValue: 0, useNativeDriver: true }),
              Animated.spring(ty, { toValue: 0, useNativeDriver: true }),
            ]).start();
          } else {
            state.x += g.dx;
            state.y += g.dy;
          }
          state.startDist = 0;
        },
      }),
    [scale, tx, ty, state],
  );

  return (
    <View style={styles.frame} {...responder.panHandlers}>
      <Animated.View style={[styles.fill, { transform: [{ translateX: tx }, { translateY: ty }, { scale }] }]}>
        <Image source={{ uri }} style={styles.fill} resizeMode="contain" accessibilityLabel={accessibilityLabel} accessible />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  frame: { flex: 1, overflow: "hidden" },
  fill: { flex: 1, width: "100%" },
});
