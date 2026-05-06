/**
 * ActivityDoneScreen
 * Shown after completing any of the 16 activities in "Conocer Inglés".
 *
 * Dependencies:
 *   expo-linear-gradient · react-native-svg
 *   Fonts loaded at app level:
 *     @expo-google-fonts/fredoka  → Fredoka_500Medium, Fredoka_600SemiBold
 *     @expo-google-fonts/nunito  → Nunito_500Medium, Nunito_700Bold, Nunito_800ExtraBold
 */

import React, { useEffect, useRef } from 'react';
import {
  Animated,
  Dimensions,
  Easing,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Svg, { Circle, G } from 'react-native-svg';

// ─── Constants ────────────────────────────────────────────────────────────────

const { width: SCREEN_WIDTH } = Dimensions.get('window');
const RING_R = 44;
const CIRCUMFERENCE = 2 * Math.PI * RING_R; // ≈ 276.46
const CONFETTI_COLORS = ['#F97316', '#7F77DD', '#FFD700', '#E1F5EE', '#FFF0E6'];
const STAR_DELAYS = [100, 250, 400];

const AnimatedCircle = Animated.createAnimatedComponent(Circle);

// ─── Types ────────────────────────────────────────────────────────────────────

export interface ActivityDoneProps {
  activityIndex: number;
  totalActivities: number;
  activityName: string;
  completionTitle: string;
  completionSubtitle: string;
  emoji: string;
  accentColor: string;
  scoreValue: number;
  correctItems: number;
  totalItems: number;
  unitName: string;
  unitProgressBefore: number;
  unitProgressAfter: number;
  onRestart: () => void;
  onBack: () => void;
  onNext: () => void;
  onPrevious: () => void;
}

interface ConfettiItem {
  translateY: Animated.Value;
  opacity: Animated.Value;
  x: number;
  color: string;
  delay: number;
  duration: number;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function starsEarned(score: number): number {
  if (score >= 85) return 3;
  if (score >= 60) return 2;
  return 1;
}

function makeConfettiItems(): ConfettiItem[] {
  return Array.from({ length: 20 }, () => ({
    translateY: new Animated.Value(0),
    opacity: new Animated.Value(1),
    x: Math.random() * (SCREEN_WIDTH - 7),
    color: CONFETTI_COLORS[Math.floor(Math.random() * CONFETTI_COLORS.length)],
    delay: Math.random() * 500,
    duration: 900 + Math.random() * 600,
  }));
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function ActivityDoneScreen({
  activityIndex,
  totalActivities,
  activityName,
  completionTitle,
  completionSubtitle,
  emoji,
  accentColor,
  scoreValue,
  correctItems,
  totalItems,
  unitName,
  unitProgressBefore,
  unitProgressAfter,
  onRestart,
  onBack,
  onNext,
  onPrevious,
}: ActivityDoneProps) {
  const earned = starsEarned(scoreValue);
  const progressDelta = unitProgressAfter - unitProgressBefore;

  // ── Animated values ──────────────────────────────────────────
  const ringOffset = useRef(new Animated.Value(CIRCUMFERENCE)).current;

  const barAnim = useRef(new Animated.Value(unitProgressBefore)).current;

  const starAnims = useRef(
    STAR_DELAYS.map(() => ({
      scale: new Animated.Value(0.4),
      opacity: new Animated.Value(0),
    })),
  ).current;

  const confettiItems = useRef<ConfettiItem[]>(makeConfettiItems()).current;

  // ── Mount animations ─────────────────────────────────────────
  useEffect(() => {
    // Score ring: start empty, fill to scoreValue %
    Animated.timing(ringOffset, {
      toValue: CIRCUMFERENCE * (1 - scoreValue / 100),
      duration: 900,
      easing: Easing.inOut(Easing.ease),
      useNativeDriver: false, // SVG props cannot use native driver
    }).start();

    // Unit progress bar
    Animated.timing(barAnim, {
      toValue: unitProgressAfter,
      duration: 800,
      delay: 200,
      easing: Easing.inOut(Easing.ease),
      useNativeDriver: false,
    }).start();

    // Stars — staggered spring
    starAnims.forEach(({ scale, opacity }, i) => {
      Animated.parallel([
        Animated.spring(scale, {
          toValue: 1,
          delay: STAR_DELAYS[i],
          useNativeDriver: true,
        }),
        Animated.timing(opacity, {
          toValue: 1,
          duration: 200,
          delay: STAR_DELAYS[i],
          useNativeDriver: true,
        }),
      ]).start();
    });

    // Confetti — random stagger, fall + fade
    confettiItems.forEach(({ translateY, opacity, delay, duration }) => {
      Animated.parallel([
        Animated.timing(translateY, {
          toValue: 200,
          duration,
          delay,
          useNativeDriver: true,
        }),
        Animated.timing(opacity, {
          toValue: 0,
          duration,
          delay,
          useNativeDriver: true,
        }),
      ]).start();
    });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const barWidthPct = barAnim.interpolate({
    inputRange: [0, 100],
    outputRange: ['0%', '100%'],
  });

  // ── Render ───────────────────────────────────────────────────
  return (
    <View style={styles.root}>
      {/* ── TopBar ── */}
      <View style={styles.topBar}>
        <View style={styles.topBarLeft}>
          <Text style={styles.topBarLabel}>Activity presentation </Text>
          <LinearGradient
            colors={['#F97316', '#7F77DD']}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 0 }}
            style={styles.topBarDash}
          />
        </View>
        <View style={styles.topBarPill}>
          <Text style={styles.topBarPillText}>
            ACTIVITY {activityIndex} / {totalActivities}
          </Text>
        </View>
      </View>

      {/* ── Confetti layer (absolute, above scroll) ── */}
      <View pointerEvents="none" style={styles.confettiLayer}>
        {confettiItems.map((item, i) => (
          <Animated.View
            key={i}
            style={[
              styles.confettiDot,
              {
                left: item.x,
                backgroundColor: item.color,
                transform: [{ translateY: item.translateY }],
                opacity: item.opacity,
              },
            ]}
          />
        ))}
      </View>

      {/* ── Scrollable content ── */}
      <ScrollView
        contentContainerStyle={styles.scrollContent}
        showsVerticalScrollIndicator={false}
      >
        <View style={styles.card}>
          {/* a) Kicker pill */}
          <View style={styles.kickerPill}>
            <Text style={styles.kickerText}>ACTIVITY COMPLETE</Text>
          </View>

          {/* b) Big title */}
          <Text style={styles.bigTitle}>{completionTitle}</Text>

          {/* c) Emoji badge */}
          <Text style={styles.emojiText}>{emoji}</Text>

          {/* d) Activity name tag */}
          <Text style={styles.activityTag}>{activityName}</Text>

          {/* e) Subtitle */}
          <Text style={styles.subtitle}>{completionSubtitle}</Text>

          {/* f) Score row */}
          <Text style={styles.scoreRow}>
            Score: {correctItems} / {totalItems} ({scoreValue}%)
          </Text>

          {/* g) Score ring */}
          <View style={styles.ringWrap}>
            <Svg width={110} height={110} viewBox="0 0 110 110">
              {/* Background track */}
              <Circle
                cx={55}
                cy={55}
                r={RING_R}
                stroke="#EDE9FA"
                strokeWidth={9}
                fill="none"
              />
              {/* Animated progress arc — rotated so 0° is at 12 o'clock */}
              <G rotation={-90} originX={55} originY={55}>
                <AnimatedCircle
                  cx={55}
                  cy={55}
                  r={RING_R}
                  stroke={accentColor}
                  strokeWidth={9}
                  strokeLinecap="round"
                  strokeDasharray={CIRCUMFERENCE}
                  strokeDashoffset={ringOffset}
                  fill="none"
                />
              </G>
            </Svg>
            {/* Center label */}
            <View style={styles.ringCenter}>
              <Text style={[styles.ringScore, { color: accentColor }]}>
                {scoreValue}%
              </Text>
              <Text style={styles.ringLabel}>score</Text>
            </View>
          </View>

          {/* h) Stars row */}
          <View style={styles.starsRow}>
            {starAnims.map(({ scale, opacity }, i) => (
              <Animated.Text
                key={i}
                style={[
                  styles.star,
                  i >= earned && styles.starEmpty,
                  { transform: [{ scale }], opacity },
                ]}
              >
                {i < earned ? '⭐' : '☆'}
              </Animated.Text>
            ))}
          </View>

          {/* i) Unit progress box */}
          <View style={styles.progressBox}>
            <Text style={styles.progressLabel}>
              {unitName.toUpperCase()} PROGRESS
            </Text>
            <View style={styles.progressBarRow}>
              <View style={styles.progressTrack}>
                <Animated.View style={[styles.progressFill, { width: barWidthPct }]}>
                  <LinearGradient
                    colors={['#F97316', '#7F77DD']}
                    start={{ x: 0, y: 0 }}
                    end={{ x: 1, y: 0 }}
                    style={StyleSheet.absoluteFill}
                  />
                </Animated.View>
              </View>
              <Text style={styles.progressPct}>{unitProgressAfter}%</Text>
            </View>
            <Text style={styles.progressSub}>
              +{progressDelta}% from this activity
            </Text>
          </View>

          {/* j) Action buttons */}
          <View style={styles.buttonsRow}>
            <TouchableOpacity
              style={[styles.actionBtn, { backgroundColor: '#F97316' }]}
              onPress={onRestart}
              activeOpacity={0.82}
            >
              <Text style={styles.actionBtnText}>↺ Restart</Text>
            </TouchableOpacity>
            <TouchableOpacity
              style={[styles.actionBtn, { backgroundColor: '#7F77DD' }]}
              onPress={onBack}
              activeOpacity={0.82}
            >
              <Text style={styles.actionBtnText}>← Back</Text>
            </TouchableOpacity>
          </View>
        </View>
      </ScrollView>

      {/* ── BottomBar ── */}
      <View style={styles.bottomBar}>
        <TouchableOpacity onPress={onPrevious} activeOpacity={0.82}>
          <LinearGradient
            colors={['#F97316', '#7F77DD']}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 0 }}
            style={styles.navBtn}
          >
            <Text style={styles.navBtnText}>← Previous</Text>
          </LinearGradient>
        </TouchableOpacity>

        <Text style={styles.navCounter}>
          {activityIndex} / {totalActivities}
        </Text>

        <TouchableOpacity
          style={[styles.navBtn, { backgroundColor: '#F97316' }]}
          onPress={onNext}
          activeOpacity={0.82}
        >
          <Text style={styles.navBtnText}>Next →</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

// ─── Styles ───────────────────────────────────────────────────────────────────

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#1a1a2e',
  },

  // ── TopBar ──────────────────────────────────────────────────
  topBar: {
    height: 52,
    backgroundColor: '#111',
    borderBottomWidth: 1,
    borderBottomColor: '#222',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
  },
  topBarLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  topBarLabel: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 17,
    color: '#F97316',
  },
  topBarDash: {
    width: 32,
    height: 2,
    borderRadius: 1,
  },
  topBarPill: {
    borderWidth: 1.5,
    borderColor: '#7F77DD',
    borderRadius: 20,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  topBarPillText: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 11,
    color: '#7F77DD',
  },

  // ── Confetti ────────────────────────────────────────────────
  confettiLayer: {
    position: 'absolute',
    top: 52,
    left: 0,
    right: 0,
    height: 240,
    zIndex: 20,
  },
  confettiDot: {
    position: 'absolute',
    top: 0,
    width: 7,
    height: 7,
    borderRadius: 3.5,
  },

  // ── ScrollView content ──────────────────────────────────────
  scrollContent: {
    alignItems: 'center',
    paddingTop: 24,
    paddingBottom: 80,
    paddingHorizontal: 16,
  },

  // ── Card ────────────────────────────────────────────────────
  card: {
    width: '100%',
    maxWidth: 480,
    backgroundColor: '#fff',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
    borderRadius: 24,
    paddingTop: 32,
    paddingHorizontal: 28,
    paddingBottom: 28,
    alignItems: 'center',
    shadowColor: '#7F77DD',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.13,
    shadowRadius: 12,
    elevation: 6,
  },

  // ── Kicker pill ─────────────────────────────────────────────
  kickerPill: {
    backgroundColor: '#FFF0E6',
    borderRadius: 8,
    paddingHorizontal: 10,
    paddingVertical: 4,
    marginBottom: 12,
  },
  kickerText: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 11,
    color: '#F97316',
    textTransform: 'uppercase',
    letterSpacing: 0.6,
  },

  // ── Titles / labels ─────────────────────────────────────────
  bigTitle: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 38,
    color: '#F97316',
    textAlign: 'center',
    lineHeight: 46,
    marginBottom: 8,
  },
  emojiText: {
    fontSize: 36,
    textAlign: 'center',
    marginBottom: 4,
  },
  activityTag: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 13,
    color: '#aaa',
    textAlign: 'center',
    marginBottom: 4,
  },
  subtitle: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 14,
    color: '#7F77DD',
    lineHeight: 21,
    textAlign: 'center',
    marginBottom: 20,
  },
  scoreRow: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 13,
    color: '#7F77DD',
    textAlign: 'center',
    marginBottom: 6,
  },

  // ── Score ring ──────────────────────────────────────────────
  ringWrap: {
    width: 110,
    height: 110,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  ringCenter: {
    position: 'absolute',
    alignItems: 'center',
    justifyContent: 'center',
  },
  ringScore: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 26,
  },
  ringLabel: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 11,
    color: '#aaa',
  },

  // ── Stars ───────────────────────────────────────────────────
  starsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    marginBottom: 20,
  },
  star: {
    fontSize: 28,
  },
  starEmpty: {
    color: '#ccc',
  },

  // ── Unit progress box ───────────────────────────────────────
  progressBox: {
    width: '100%',
    backgroundColor: '#F7F5FF',
    borderRadius: 14,
    paddingVertical: 14,
    paddingHorizontal: 16,
  },
  progressLabel: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 12,
    color: '#7F77DD',
    textTransform: 'uppercase',
    marginBottom: 8,
  },
  progressBarRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  progressTrack: {
    flex: 1,
    height: 8,
    borderRadius: 8,
    backgroundColor: '#EDE9FA',
    overflow: 'hidden',
  },
  progressFill: {
    height: 8,
    borderRadius: 8,
    overflow: 'hidden',
  },
  progressPct: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 16,
    color: '#7F77DD',
  },
  progressSub: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 11,
    color: '#aaa',
    marginTop: 4,
  },

  // ── Action buttons ──────────────────────────────────────────
  buttonsRow: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 22,
    width: '100%',
  },
  actionBtn: {
    flex: 1,
    height: 48,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionBtnText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 16,
    color: '#fff',
  },

  // ── BottomBar ───────────────────────────────────────────────
  bottomBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 56,
    backgroundColor: '#111',
    borderTopWidth: 1,
    borderTopColor: '#222',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
  },
  navBtn: {
    borderRadius: 12,
    paddingVertical: 9,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  navBtnText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 14,
    color: '#fff',
  },
  navCounter: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 14,
    color: '#666',
  },
});
