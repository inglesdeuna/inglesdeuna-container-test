/**
 * The 16 activity configurations for ActivityDoneScreen.
 * Pass one entry (spread or picked by index) as props.
 *
 * Usage:
 *   import { ACTIVITY_CONFIGS } from './activityConfigs';
 *   const cfg = ACTIVITY_CONFIGS[activityIndex - 1]; // 0-based
 *   <ActivityDoneScreen activityIndex={1} totalActivities={16} {...cfg} ... />
 */

export interface ActivityConfig {
  activityName: string;
  completionTitle: string;
  completionSubtitle: string;
  emoji: string;
  accentColor: string;
  totalItems: number;
}

export const ACTIVITY_CONFIGS: ActivityConfig[] = [
  {
    activityName: 'Vocabulary Intro',
    emoji: '📖',
    accentColor: '#F97316',
    completionTitle: 'Words Unlocked!',
    completionSubtitle:
      'You learned all the new vocabulary. Keep building that word bank!',
    totalItems: 10,
  },
  {
    activityName: 'Listen & Repeat',
    emoji: '🎧',
    accentColor: '#7F77DD',
    completionTitle: 'Ears On Fire!',
    completionSubtitle:
      'Perfect listening session. Your ear for English is getting sharper.',
    totalItems: 8,
  },
  {
    activityName: 'Matching Pairs',
    emoji: '🔗',
    accentColor: '#1D9E75',
    completionTitle: 'Perfect Match!',
    completionSubtitle:
      'You connected every word to its meaning. Great memory work!',
    totalItems: 12,
  },
  {
    activityName: 'Fill in the Blanks',
    emoji: '✏️',
    accentColor: '#D85A30',
    completionTitle: 'Gaps Filled!',
    completionSubtitle:
      'Nice work completing the sentences. Context clues are your friend.',
    totalItems: 10,
  },
  {
    activityName: 'Pronunciation',
    emoji: '🗣️',
    accentColor: '#7F77DD',
    completionTitle: 'All Done!',
    completionSubtitle:
      'Great job listening and repeating. Your accent is improving!',
    totalItems: 6,
  },
  {
    activityName: 'Reading Passage',
    emoji: '📄',
    accentColor: '#378ADD',
    completionTitle: 'Page Turned!',
    completionSubtitle:
      'You read through the whole passage. Comprehension is key!',
    totalItems: 8,
  },
  {
    activityName: 'Grammar Rules',
    emoji: '📐',
    accentColor: '#639922',
    completionTitle: 'Rules Mastered!',
    completionSubtitle:
      'You nailed the grammar patterns. Structure makes everything click.',
    totalItems: 10,
  },
  {
    activityName: 'Word Order',
    emoji: '🔤',
    accentColor: '#BA7517',
    completionTitle: 'Perfectly Ordered!',
    completionSubtitle:
      'You put every sentence in the right order. Syntax champion!',
    totalItems: 8,
  },
  {
    activityName: 'Dialogue Practice',
    emoji: '💬',
    accentColor: '#D4537E',
    completionTitle: 'Conversation Done!',
    completionSubtitle:
      "You practiced a real-life dialogue. That's how fluency happens.",
    totalItems: 6,
  },
  {
    activityName: 'Sentence Building',
    emoji: '🧩',
    accentColor: '#F97316',
    completionTitle: 'Sentences Built!',
    completionSubtitle:
      'You assembled every sentence from scratch. Builder mindset!',
    totalItems: 8,
  },
  {
    activityName: 'True or False',
    emoji: '✅',
    accentColor: '#1D9E75',
    completionTitle: 'Truth Detected!',
    completionSubtitle:
      "You spotted what's right and wrong. Critical thinking in action.",
    totalItems: 10,
  },
  {
    activityName: 'Comprehension Quiz',
    emoji: '❓',
    accentColor: '#378ADD',
    completionTitle: 'Quiz Crushed!',
    completionSubtitle:
      'You answered every question about the text. Great focus!',
    totalItems: 10,
  },
  {
    activityName: 'Roleplay',
    emoji: '🎭',
    accentColor: '#D4537E',
    completionTitle: 'Scene Complete!',
    completionSubtitle:
      'You played the role with confidence. Real-world English unlocked.',
    totalItems: 6,
  },
  {
    activityName: 'Spelling Challenge',
    emoji: '🔡',
    accentColor: '#7F77DD',
    completionTitle: 'Spelled It Right!',
    completionSubtitle:
      'Every letter in its place. Your spelling game is strong.',
    totalItems: 12,
  },
  {
    activityName: 'Culture Note',
    emoji: '🌍',
    accentColor: '#639922',
    completionTitle: 'World Explored!',
    completionSubtitle:
      'You absorbed the culture behind the language. Context is everything.',
    totalItems: 5,
  },
  {
    activityName: 'Final Review',
    emoji: '🏆',
    accentColor: '#F97316',
    completionTitle: 'Unit Complete!',
    completionSubtitle:
      'You finished the entire unit. Outstanding work — you earned this!',
    totalItems: 16,
  },
];
