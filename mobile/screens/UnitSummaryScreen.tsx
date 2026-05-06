/**
 * UnitSummaryScreen
 * Shown after a student completes all 16 activities in a unit.
 * Fully driven by props — works for all units in "Conocer Inglés".
 *
 * Dependencies:
 *   expo-linear-gradient · react-native-svg · react-native Animated API
 *   Fonts loaded at app level:
 *     @expo-google-fonts/fredoka  → Fredoka_500Medium, Fredoka_600SemiBold
 *     @expo-google-fonts/nunito  → Nunito_500Medium, Nunito_700Bold
 */

import React, { useEffect, useRef } from 'react';
import {
  Animated,
  Easing,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Svg, { Circle, G } from 'react-native-svg';
import type { SkillResult } from '../utils/unitSummary';

// ─── Constants ────────────────────────────────────────────────────────────────

const RING_R = 40;
const CIRCUMFERENCE = 2 * Math.PI * RING_R; // ≈ 251.33

const AnimatedCircle = Animated.createAnimatedComponent(Circle);

const LEVEL_BADGE = {
  strong:   { bg: '#DCFCE7', text: '#166534', label: 'Strong'   },
  okay:     { bg: '#FFF0E6', text: '#C2460A', label: 'Okay'     },
  practice: { bg: '#FBEAF0', text: '#D4537E', label: 'Practice' },
} as const;

// ─── Props ────────────────────────────────────────────────────────────────────

export interface UnitSummaryProps {
  unitIndex: number;
  unitTitle: string;
  allUnits: { index: number; label: string }[];

  overallPct: number;
  passMark: number;
  correctItems: number;
  totalItems: number;
  totalActivities: number;
  starsEarned: number;

  skills: SkillResult[];
  strengths: string[];  // activity names where pct >= 70
  weaknesses: string[]; // activity names where pct <  60
  tipText: string;

  onBack: () => void;
  onMyCourses: () => void;
  onStartQuiz: () => void;
  onTabPress: (unitIndex: number) => void;
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function UnitSummaryScreen({
  unitIndex,
  unitTitle,
  allUnits,
  overallPct,
  passMark,
  correctItems,
  totalItems,
  totalActivities,
  starsEarned,
  skills,
  strengths,
  weaknesses,
  tipText,
  onBack,
  onMyCourses,
  onStartQuiz,
  onTabPress,
}: UnitSummaryProps) {
  const isPassed = overallPct >= passMark;
  const errors   = totalItems - correctItems;

  // ── Animated values ─────────────────────────────────────────
  // Score ring — starts empty, fills to overallPct on mount
  const ringAnim = useRef(new Animated.Value(CIRCUMFERENCE)).current;

  // One value per skill bar, each animates 0 → skill.pct
  const skillBarAnims = useRef(
    skills.map(() => new Animated.Value(0)),
  ).current;

  // Four stats pills — fade + slide up
  const pillAnims = useRef(
    [0, 1, 2, 3].map(() => ({
      opacity:    new Animated.Value(0),
      translateY: new Animated.Value(10),
    })),
  ).current;

  // Focus-area item fade-ins (independent stagger per column)
  const strengthAnims = useRef(
    strengths.map(() => new Animated.Value(0)),
  ).current;
  const weaknessAnims = useRef(
    weaknesses.map(() => new Animated.Value(0)),
  ).current;

  // ── Mount animations ─────────────────────────────────────────
  useEffect(() => {
    // Score ring
    Animated.timing(ringAnim, {
      toValue: CIRCUMFERENCE * (1 - overallPct / 100),
      duration: 900,
      easing: Easing.inOut(Easing.ease),
      useNativeDriver: false, // SVG prop — native driver not supported
    }).start();

    // Skill bars — staggered by 100ms per skill
    skills.forEach((skill, i) => {
      Animated.timing(skillBarAnims[i], {
        toValue: skill.pct,
        duration: 800,
        delay: i * 100,
        easing: Easing.inOut(Easing.ease),
        useNativeDriver: false, // width % — native driver not supported
      }).start();
    });

    // Stats pills — fade + slide, staggered 50ms
    pillAnims.forEach(({ opacity, translateY }, i) => {
      Animated.parallel([
        Animated.timing(opacity, {
          toValue: 1,
          duration: 400,
          delay: i * 50,
          useNativeDriver: true,
        }),
        Animated.timing(translateY, {
          toValue: 0,
          duration: 400,
          delay: i * 50,
          useNativeDriver: true,
        }),
      ]).start();
    });

    // Strengths — staggered 40ms
    strengthAnims.forEach((anim, i) => {
      Animated.timing(anim, {
        toValue: 1,
        duration: 400,
        delay: i * 40,
        useNativeDriver: true,
      }).start();
    });

    // Weaknesses — staggered 40ms (independent from strengths)
    weaknessAnims.forEach((anim, i) => {
      Animated.timing(anim, {
        toValue: 1,
        duration: 400,
        delay: i * 40,
        useNativeDriver: true,
      }).start();
    });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Derived data ─────────────────────────────────────────────
  const statsPills = [
    { label: 'Correct', value: String(correctItems),    color: '#1D9E75' },
    { label: 'Errors',  value: String(errors),          color: '#D4537E' },
    { label: 'Done',    value: String(totalActivities), color: '#F97316' },
    { label: 'Stars',   value: `${starsEarned}⭐`,     color: '#7F77DD' },
  ];

  // ── Render ───────────────────────────────────────────────────
  return (
    <View style={styles.root}>

      {/* ══ 1. TopBar ══ */}
      <View style={styles.topBar}>
        <TouchableOpacity onPress={onBack} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
          <Text style={styles.topBarBack}>← Back</Text>
        </TouchableOpacity>
        <Text style={styles.topBarTitle}>Unit Summary</Text>
        <TouchableOpacity hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
          <Text style={styles.topBarPdf}>📄 PDF</Text>
        </TouchableOpacity>
      </View>

      {/* ══ 2. Unit tabs ══ */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.tabsContent}
        style={styles.tabsRow}
      >
        {allUnits.map(unit => {
          const isActive = unit.index === unitIndex;
          return (
            <TouchableOpacity
              key={unit.index}
              onPress={() => onTabPress(unit.index)}
              activeOpacity={0.78}
              style={[styles.tabPill, isActive && styles.tabPillActive]}
            >
              <Text style={[styles.tabText, isActive && styles.tabTextActive]}>
                {unit.label}
              </Text>
            </TouchableOpacity>
          );
        })}
      </ScrollView>

      {/* ══ 3. Scrollable content ══ */}
      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.scrollContent}
      >

        {/* ── A. Hero result card ── */}
        <View style={styles.heroCard}>
          <Text style={styles.heroEmoji}>🏁</Text>
          <Text style={styles.heroTitle}>Unit Complete!</Text>
          <Text style={styles.heroSubtitle}>
            You finished all {totalActivities} activities in {unitTitle}
          </Text>

          {/* Score row: ring ← → badge + stats */}
          <View style={styles.scoreRow}>

            {/* Ring */}
            <View style={styles.ringWrap}>
              <Svg width={100} height={100} viewBox="0 0 100 100">
                <Circle
                  cx={50} cy={50} r={RING_R}
                  stroke="#EDE9FA" strokeWidth={9} fill="none"
                />
                <G rotation={-90} originX={50} originY={50}>
                  <AnimatedCircle
                    cx={50} cy={50} r={RING_R}
                    stroke="#F97316" strokeWidth={9} strokeLinecap="round"
                    strokeDasharray={CIRCUMFERENCE}
                    strokeDashoffset={ringAnim}
                    fill="none"
                  />
                </G>
              </Svg>
              <View style={styles.ringCenter}>
                <Text style={styles.ringPct}>{overallPct}%</Text>
                <Text style={styles.ringLabel}>overall</Text>
              </View>
            </View>

            {/* Badge + stat lines */}
            <View style={styles.scoreRight}>
              {isPassed ? (
                <LinearGradient
                  colors={['#7F77DD', '#F97316']}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 0 }}
                  style={styles.passBadge}
                >
                  <Text style={styles.passBadgeText}>✓ PASS</Text>
                </LinearGradient>
              ) : (
                <View style={styles.failBadge}>
                  <Text style={styles.failBadgeText}>✗ Not yet</Text>
                </View>
              )}

              <Text style={styles.statLine}>Minimum: {passMark}%</Text>

              <Text style={styles.statLine}>
                {'Errors: '}
                <Text style={styles.statError}>{errors}</Text>
                {' / '}
                <Text style={styles.statError}>{totalItems}</Text>
              </Text>

              <Text style={styles.statLine}>
                {'Activities: '}
                <Text style={styles.statGood}>{totalActivities}</Text>
                {' / '}
                <Text style={styles.statGood}>{totalActivities}</Text>
              </Text>
            </View>
          </View>

          {/* Stats pills */}
          <View style={styles.statsPillsRow}>
            {statsPills.map((pill, i) => (
              <Animated.View
                key={pill.label}
                style={[
                  styles.statPill,
                  {
                    opacity:   pillAnims[i].opacity,
                    transform: [{ translateY: pillAnims[i].translateY }],
                  },
                ]}
              >
                <Text style={[styles.statPillValue, { color: pill.color }]}>
                  {pill.value}
                </Text>
                <Text style={styles.statPillLabel}>{pill.label}</Text>
              </Animated.View>
            ))}
          </View>

          {/* Quiz unlock / locked box */}
          {isPassed ? (
            <LinearGradient
              colors={['#FFF0E6', '#EEEDFE']}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={styles.quizBox}
            >
              <Text style={styles.quizBoxIcon}>🔓</Text>
              <View style={styles.quizBoxBody}>
                <Text style={styles.quizBoxTitle}>Quiz Unlocked!</Text>
                <Text style={styles.quizBoxSub}>
                  You passed {overallPct}% — the final quiz is now available
                </Text>
              </View>
              <TouchableOpacity
                style={styles.quizBoxBtn}
                onPress={onStartQuiz}
                activeOpacity={0.82}
              >
                <Text style={styles.quizBoxBtnText}>Start Quiz →</Text>
              </TouchableOpacity>
            </LinearGradient>
          ) : (
            <View style={styles.quizBoxLocked}>
              <Text style={styles.quizBoxIcon}>🔒</Text>
              <View style={styles.quizBoxBody}>
                <Text style={styles.quizBoxTitleLocked}>Quiz Locked</Text>
                <Text style={styles.quizBoxSubLocked}>
                  Reach {passMark}% to unlock the final quiz
                </Text>
              </View>
            </View>
          )}
        </View>

        {/* ── B. Skill breakdown label ── */}
        <Text style={styles.sectionLabel}>YOUR SKILL BREAKDOWN</Text>

        {/* ── C. Skill breakdown card ── */}
        <View style={styles.skillsCard}>
          {skills.map((skill, i) => {
            const barWidthAnim = skillBarAnims[i].interpolate({
              inputRange:  [0, 100],
              outputRange: ['0%', '100%'],
            });
            const badge = LEVEL_BADGE[skill.level];
            return (
              <View
                key={skill.name}
                style={[
                  styles.skillRow,
                  i < skills.length - 1 && styles.skillRowDivider,
                ]}
              >
                <Text style={styles.skillEmoji}>{skill.emoji}</Text>
                <View style={styles.skillInfo}>
                  <Text style={styles.skillName}>{skill.name}</Text>
                  <View style={styles.skillTrack}>
                    <Animated.View
                      style={[
                        styles.skillFill,
                        { width: barWidthAnim, backgroundColor: skill.color },
                      ]}
                    />
                  </View>
                </View>
                <Text style={[styles.skillPct, { color: skill.color }]}>
                  {skill.pct}%
                </Text>
                <View style={[styles.levelBadge, { backgroundColor: badge.bg }]}>
                  <Text style={[styles.levelBadgeText, { color: badge.text }]}>
                    {badge.label}
                  </Text>
                </View>
              </View>
            );
          })}
        </View>

        {/* ── D. Focus areas label ── */}
        <Text style={styles.sectionLabel}>FOCUS AREAS</Text>

        {/* ── E. Strengths / Weaknesses grid ── */}
        <View style={styles.focusGrid}>
          {/* Strengths column */}
          <View style={styles.strengthCard}>
            <Text style={styles.strengthTitle}>💪 Strengths</Text>
            {strengths.length > 0 ? (
              strengths.map((name, i) => (
                <Animated.View
                  key={i}
                  style={[styles.focusItem, { opacity: strengthAnims[i] }]}
                >
                  <View style={styles.dotGreen} />
                  <Text style={styles.focusItemText} numberOfLines={2}>{name}</Text>
                </Animated.View>
              ))
            ) : (
              <Text style={styles.focusEmpty}>Keep going!</Text>
            )}
          </View>

          {/* Weaknesses column */}
          <View style={styles.weaknessCard}>
            <Text style={styles.weaknessTitle}>🎯 To improve</Text>
            {weaknesses.length > 0 ? (
              weaknesses.map((name, i) => (
                <Animated.View
                  key={i}
                  style={[styles.focusItem, { opacity: weaknessAnims[i] }]}
                >
                  <View style={styles.dotPink} />
                  <Text style={styles.focusItemText} numberOfLines={2}>{name}</Text>
                </Animated.View>
              ))
            ) : (
              <Text style={styles.focusEmptyGreen}>No weak spots!</Text>
            )}
          </View>
        </View>

        {/* ── F. Tip box ── */}
        <View style={styles.tipBox}>
          <Text style={styles.tipIcon}>💡</Text>
          <View style={styles.tipBody}>
            <Text style={styles.tipTitle}>Quick tip</Text>
            <Text style={styles.tipText}>{tipText}</Text>
          </View>
        </View>

      </ScrollView>

      {/* ══ 4. BottomBar (absolute) ══ */}
      <View style={styles.bottomBar}>
        <TouchableOpacity
          style={styles.coursesBtn}
          onPress={onMyCourses}
          activeOpacity={0.82}
        >
          <Text style={styles.coursesBtnText}>← My Courses</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.quizBtn, !isPassed && styles.quizBtnDisabled]}
          onPress={isPassed ? onStartQuiz : undefined}
          disabled={!isPassed}
          activeOpacity={0.82}
        >
          <Text style={[styles.quizBtnText, !isPassed && styles.quizBtnTextDisabled]}>
            Start Quiz 🏆
          </Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

// ─── Styles ───────────────────────────────────────────────────────────────────

const CARD_SHADOW = {
  shadowColor: '#7F77DD',
  shadowOffset: { width: 0, height: 4 },
  shadowOpacity: 0.13,
  shadowRadius: 10,
  elevation: 6,
} as const;

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#F7F5FF',
  },

  // ── TopBar ──────────────────────────────────────────────────
  topBar: {
    height: 52,
    backgroundColor: '#fff',
    borderBottomWidth: 1.5,
    borderBottomColor: '#F0EEF8',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
  },
  topBarBack: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 15,
    color: '#7F77DD',
  },
  topBarTitle: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 18,
    color: '#F97316',
  },
  topBarPdf: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#aaa',
  },

  // ── Unit tabs ────────────────────────────────────────────────
  tabsRow: {
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#F0EEF8',
    flexShrink: 0,
  },
  tabsContent: {
    paddingHorizontal: 16,
    paddingVertical: 8,
    gap: 8,
    flexDirection: 'row',
    alignItems: 'center',
  },
  tabPill: {
    paddingHorizontal: 14,
    paddingVertical: 6,
    borderRadius: 20,
    backgroundColor: '#fff',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
  },
  tabPillActive: {
    backgroundColor: '#7F77DD',
    borderColor: '#7F77DD',
  },
  tabText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 12,
    color: '#aaa',
  },
  tabTextActive: {
    color: '#fff',
  },

  // ── ScrollView ───────────────────────────────────────────────
  scrollContent: {
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 90,
    gap: 12,
  },

  // ── Hero card ────────────────────────────────────────────────
  heroCard: {
    backgroundColor: '#fff',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
    borderRadius: 24,
    paddingTop: 24,
    paddingHorizontal: 20,
    paddingBottom: 20,
    alignItems: 'center',
    ...CARD_SHADOW,
  },
  heroEmoji: {
    fontSize: 32,
    marginBottom: 4,
  },
  heroTitle: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 26,
    color: '#F97316',
    marginBottom: 4,
  },
  heroSubtitle: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 13,
    color: '#aaa',
    textAlign: 'center',
    marginBottom: 18,
  },

  // Score row
  scoreRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 20,
    marginBottom: 4,
  },

  // Ring
  ringWrap: {
    width: 100,
    height: 100,
    alignItems: 'center',
    justifyContent: 'center',
  },
  ringCenter: {
    position: 'absolute',
    alignItems: 'center',
  },
  ringPct: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 28,
    color: '#F97316',
  },
  ringLabel: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 10,
    color: '#aaa',
  },

  // Right side of score row
  scoreRight: {
    flex: 1,
    gap: 4,
  },
  passBadge: {
    alignSelf: 'flex-start',
    borderRadius: 20,
    paddingVertical: 5,
    paddingHorizontal: 16,
    marginBottom: 6,
  },
  passBadgeText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#fff',
  },
  failBadge: {
    alignSelf: 'flex-start',
    backgroundColor: '#FBEAF0',
    borderWidth: 1,
    borderColor: '#F4C0D1',
    borderRadius: 20,
    paddingVertical: 5,
    paddingHorizontal: 16,
    marginBottom: 6,
  },
  failBadgeText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#D4537E',
  },
  statLine: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#aaa',
    lineHeight: 19,
  },
  statError: {
    fontFamily: 'Nunito_700Bold',
    color: '#D4537E',
  },
  statGood: {
    fontFamily: 'Nunito_700Bold',
    color: '#1D9E75',
  },

  // Stats pills
  statsPillsRow: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: 8,
    marginTop: 12,
    marginBottom: 16,
  },
  statPill: {
    backgroundColor: '#F7F5FF',
    borderRadius: 12,
    paddingVertical: 8,
    paddingHorizontal: 14,
    alignItems: 'center',
    minWidth: 60,
  },
  statPillValue: {
    fontFamily: 'Fredoka_600SemiBold',
    fontSize: 18,
  },
  statPillLabel: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 10,
    color: '#aaa',
    marginTop: 2,
  },

  // Quiz unlock box
  quizBox: {
    width: '100%',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
    borderRadius: 14,
    paddingVertical: 12,
    paddingHorizontal: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  quizBoxLocked: {
    width: '100%',
    backgroundColor: '#F7F5FF',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
    borderRadius: 14,
    paddingVertical: 12,
    paddingHorizontal: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  quizBoxIcon: {
    fontSize: 22,
  },
  quizBoxBody: {
    flex: 1,
  },
  quizBoxTitle: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 14,
    color: '#F97316',
    marginBottom: 2,
  },
  quizBoxSub: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 11,
    color: '#7F77DD',
    lineHeight: 16,
  },
  quizBoxTitleLocked: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 14,
    color: '#aaa',
    marginBottom: 2,
  },
  quizBoxSubLocked: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 11,
    color: '#aaa',
    lineHeight: 16,
  },
  quizBoxBtn: {
    backgroundColor: '#F97316',
    borderRadius: 10,
    paddingVertical: 8,
    paddingHorizontal: 14,
  },
  quizBoxBtnText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#fff',
  },

  // ── Section labels ───────────────────────────────────────────
  sectionLabel: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#7F77DD',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
    marginTop: 4,
  },

  // ── Skills card ──────────────────────────────────────────────
  skillsCard: {
    backgroundColor: '#fff',
    borderWidth: 1.5,
    borderColor: '#EDE9FA',
    borderRadius: 20,
    padding: 16,
    ...CARD_SHADOW,
  },
  skillRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingVertical: 10,
  },
  skillRowDivider: {
    borderBottomWidth: 1,
    borderBottomColor: '#F0EEF8',
  },
  skillEmoji: {
    fontSize: 18,
    width: 28,
    textAlign: 'center',
  },
  skillInfo: {
    flex: 1,
  },
  skillName: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 13,
    color: '#3D3768',
    marginBottom: 5,
  },
  skillTrack: {
    height: 6,
    backgroundColor: '#EDE9FA',
    borderRadius: 6,
    overflow: 'hidden',
  },
  skillFill: {
    height: 6,
    borderRadius: 6,
  },
  skillPct: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    minWidth: 36,
    textAlign: 'right',
  },
  levelBadge: {
    borderRadius: 8,
    paddingVertical: 3,
    paddingHorizontal: 8,
  },
  levelBadgeText: {
    fontFamily: 'Nunito_700Bold',
    fontSize: 11,
  },

  // ── Focus areas ──────────────────────────────────────────────
  focusGrid: {
    flexDirection: 'row',
    gap: 10,
  },
  strengthCard: {
    flex: 1,
    backgroundColor: '#E1F5EE',
    borderWidth: 1.5,
    borderColor: '#9FE1CB',
    borderRadius: 16,
    padding: 12,
    paddingHorizontal: 13,
  },
  weaknessCard: {
    flex: 1,
    backgroundColor: '#FBEAF0',
    borderWidth: 1.5,
    borderColor: '#F4C0D1',
    borderRadius: 16,
    padding: 12,
    paddingHorizontal: 13,
  },
  strengthTitle: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#0F6E56',
    marginBottom: 8,
  },
  weaknessTitle: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 13,
    color: '#D4537E',
    marginBottom: 8,
  },
  focusItem: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    marginBottom: 6,
  },
  dotGreen: {
    width: 7,
    height: 7,
    borderRadius: 50,
    backgroundColor: '#1D9E75',
    marginTop: 4,
    flexShrink: 0,
  },
  dotPink: {
    width: 7,
    height: 7,
    borderRadius: 50,
    backgroundColor: '#D4537E',
    marginTop: 4,
    flexShrink: 0,
  },
  focusItemText: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#3D3768',
    flex: 1,
  },
  focusEmpty: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#aaa',
    fontStyle: 'italic',
  },
  focusEmptyGreen: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#1D9E75',
    fontStyle: 'italic',
  },

  // ── Tip box ──────────────────────────────────────────────────
  tipBox: {
    backgroundColor: '#EEEDFE',
    borderWidth: 1.5,
    borderColor: '#AFA9EC',
    borderRadius: 16,
    paddingVertical: 13,
    paddingHorizontal: 14,
    flexDirection: 'row',
    gap: 10,
    alignItems: 'flex-start',
  },
  tipIcon: {
    fontSize: 20,
  },
  tipBody: {
    flex: 1,
  },
  tipTitle: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 14,
    color: '#534AB7',
    marginBottom: 3,
  },
  tipText: {
    fontFamily: 'Nunito_500Medium',
    fontSize: 12,
    color: '#534AB7',
    lineHeight: 18,
  },

  // ── BottomBar (absolute) ─────────────────────────────────────
  bottomBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 66,
    backgroundColor: '#fff',
    borderTopWidth: 1.5,
    borderTopColor: '#F0EEF8',
    flexDirection: 'row',
    gap: 10,
    paddingTop: 10,
    paddingHorizontal: 16,
    paddingBottom: 14,
  },
  coursesBtn: {
    flex: 1,
    height: 46,
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: '#7F77DD',
    backgroundColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  coursesBtnText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 15,
    color: '#7F77DD',
  },
  quizBtn: {
    flex: 1,
    height: 46,
    borderRadius: 14,
    backgroundColor: '#F97316',
    alignItems: 'center',
    justifyContent: 'center',
  },
  quizBtnDisabled: {
    backgroundColor: '#EDE9FA',
  },
  quizBtnText: {
    fontFamily: 'Fredoka_500Medium',
    fontSize: 15,
    color: '#fff',
  },
  quizBtnTextDisabled: {
    color: '#bbb',
  },
});
