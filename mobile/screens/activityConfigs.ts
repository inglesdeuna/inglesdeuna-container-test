/**
 * Source-of-truth config for all 16 activities in "Conocer Inglés".
 *
 * TYPE A — scored (ring shows %, stars, score row)
 * TYPE B — completion-only (ring always full, ✓ checkmark, no stars, no score row)
 *
 * Array is sorted by id (1–16) so ACTIVITY_CONFIGS[id - 1] works.
 *
 * Usage:
 *   import { ACTIVITY_CONFIGS, getActivityConfig } from './activityConfigs';
 *   const cfg = getActivityConfig(activityId);
 *   <ActivityDoneScreen config={cfg} ... />
 */

export type ActivityType = 'A' | 'B';

export interface ActivityConfig {
  id: number;
  name: string;
  type: ActivityType;
  emoji: string;
  accentColor: string;
  completionTitle: string;
  completionSubtitle: string;
  /** TYPE A only — total graded items */
  totalItems?: number;
  /** TYPE B only — fixed unit progress bonus (%) awarded on completion */
  progressBonus?: number;
}

export const ACTIVITY_CONFIGS: ActivityConfig[] = [
  // ── id 1 ── TYPE B
  {
    id: 1,
    name: 'Vocabulary Intro',
    type: 'B',
    emoji: '📖',
    accentColor: '#F97316',
    completionTitle: 'Words Unlocked!',
    completionSubtitle:
      'You learned all the new vocabulary. Keep building that word bank!',
    progressBonus: 3,
  },
  // ── id 2 ── TYPE B
  {
    id: 2,
    name: 'Listen & Repeat',
    type: 'B',
    emoji: '🎧',
    accentColor: '#7F77DD',
    completionTitle: 'Ears On Fire!',
    completionSubtitle:
      'Perfect listening session. Your ear for English is getting sharper.',
    progressBonus: 3,
  },
  // ── id 3 ── TYPE A
  {
    id: 3,
    name: 'Matching Pairs',
    type: 'A',
    emoji: '🔗',
    accentColor: '#1D9E75',
    completionTitle: 'Perfect Match!',
    completionSubtitle:
      'You connected every word to its meaning. Great memory work!',
    totalItems: 12,
  },
  // ── id 4 ── TYPE A
  {
    id: 4,
    name: 'Fill in the Blanks',
    type: 'A',
    emoji: '✏️',
    accentColor: '#D85A30',
    completionTitle: 'Gaps Filled!',
    completionSubtitle:
      'Nice work completing the sentences. Context clues are your friend.',
    totalItems: 10,
  },
  // ── id 5 ── TYPE B
  {
    id: 5,
    name: 'Pronunciation',
    type: 'B',
    emoji: '🗣️',
    accentColor: '#7F77DD',
    completionTitle: 'All Done!',
    completionSubtitle:
      'Great job listening and repeating. Your accent is improving!',
    progressBonus: 3,
  },
  // ── id 6 ── TYPE B
  {
    id: 6,
    name: 'Reading Passage',
    type: 'B',
    emoji: '📄',
    accentColor: '#378ADD',
    completionTitle: 'Page Turned!',
    completionSubtitle:
      'You read through the whole passage. Comprehension is key!',
    progressBonus: 3,
  },
  // ── id 7 ── TYPE A
  {
    id: 7,
    name: 'Grammar Rules',
    type: 'A',
    emoji: '📐',
    accentColor: '#639922',
    completionTitle: 'Rules Mastered!',
    completionSubtitle:
      'You nailed the grammar patterns. Structure makes everything click.',
    totalItems: 10,
  },
  // ── id 8 ── TYPE A
  {
    id: 8,
    name: 'Word Order',
    type: 'A',
    emoji: '🔤',
    accentColor: '#BA7517',
    completionTitle: 'Perfectly Ordered!',
    completionSubtitle:
      'You put every sentence in the right order. Syntax champion!',
    totalItems: 8,
  },
  // ── id 9 ── TYPE B
  {
    id: 9,
    name: 'Dialogue Practice',
    type: 'B',
    emoji: '💬',
    accentColor: '#D4537E',
    completionTitle: 'Conversation Done!',
    completionSubtitle:
      "You practiced a real-life dialogue. That's how fluency happens.",
    progressBonus: 3,
  },
  // ── id 10 ── TYPE A
  {
    id: 10,
    name: 'Sentence Building',
    type: 'A',
    emoji: '🧩',
    accentColor: '#F97316',
    completionTitle: 'Sentences Built!',
    completionSubtitle:
      'You assembled every sentence from scratch. Builder mindset!',
    totalItems: 8,
  },
  // ── id 11 ── TYPE A
  {
    id: 11,
    name: 'True or False',
    type: 'A',
    emoji: '✅',
    accentColor: '#1D9E75',
    completionTitle: 'Truth Detected!',
    completionSubtitle:
      "You spotted what's right and wrong. Critical thinking in action.",
    totalItems: 10,
  },
  // ── id 12 ── TYPE A
  {
    id: 12,
    name: 'Comprehension Quiz',
    type: 'A',
    emoji: '❓',
    accentColor: '#378ADD',
    completionTitle: 'Quiz Crushed!',
    completionSubtitle:
      'You answered every question about the text. Great focus!',
    totalItems: 10,
  },
  // ── id 13 ── TYPE B
  {
    id: 13,
    name: 'Roleplay',
    type: 'B',
    emoji: '🎭',
    accentColor: '#D4537E',
    completionTitle: 'Scene Complete!',
    completionSubtitle:
      'You played the role with confidence. Real-world English unlocked.',
    progressBonus: 3,
  },
  // ── id 14 ── TYPE A
  {
    id: 14,
    name: 'Spelling Challenge',
    type: 'A',
    emoji: '🔡',
    accentColor: '#7F77DD',
    completionTitle: 'Spelled It Right!',
    completionSubtitle:
      'Every letter in its place. Your spelling game is strong.',
    totalItems: 12,
  },
  // ── id 15 ── TYPE B
  {
    id: 15,
    name: 'Culture Note',
    type: 'B',
    emoji: '🌍',
    accentColor: '#639922',
    completionTitle: 'World Explored!',
    completionSubtitle:
      'You absorbed the culture behind the language. Context is everything.',
    progressBonus: 2,
  },
  // ── id 16 ── TYPE A
  {
    id: 16,
    name: 'Final Review',
    type: 'A',
    emoji: '🏆',
    accentColor: '#F97316',
    completionTitle: 'Unit Complete!',
    completionSubtitle:
      'You finished the entire unit. Outstanding work — you earned this!',
    totalItems: 16,
  },
];

/** Convenience lookup — throws if id is out of range */
export function getActivityConfig(id: number): ActivityConfig {
  const cfg = ACTIVITY_CONFIGS[id - 1];
  if (!cfg) throw new RangeError(`No activity config for id ${id}`);
  return cfg;
}

/** Pre-computed sets for fast type checks at call sites */
export const TYPE_A_IDS = new Set(
  ACTIVITY_CONFIGS.filter(c => c.type === 'A').map(c => c.id),
);
export const TYPE_B_IDS = new Set(
  ACTIVITY_CONFIGS.filter(c => c.type === 'B').map(c => c.id),
);
