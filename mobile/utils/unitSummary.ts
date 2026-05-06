/**
 * Shared types and pure helpers for UnitSummaryScreen.
 * No React imports — safe to use in any context.
 */

// ─── Types ────────────────────────────────────────────────────────────────────

export type SkillLevel = 'strong' | 'okay' | 'practice';

export interface SkillResult {
  emoji: string;
  name: string;
  pct: number;      // 0–100
  color: string;    // accent for bar and pct label
  level: SkillLevel;
}

export interface ActivityResult {
  name: string;
  pct: number;
  isStrength: boolean; // pct >= 70
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Classify a skill percentage into a display level. */
export function getSkillLevel(pct: number): SkillLevel {
  if (pct >= 75) return 'strong';
  if (pct >= 55) return 'okay';
  return 'practice';
}

/** Compute stars earned from an overall unit percentage. */
export function getStars(pct: number): number {
  if (pct >= 85) return 3;
  if (pct >= 70) return 2;
  return 1;
}

/**
 * Generate a dynamic tip sentence from the lowest skill and the first weakness.
 * Use this when the backend does not supply a tipText.
 */
export function generateTip(skills: SkillResult[], weaknesses: string[]): string {
  if (!skills.length) return 'Keep practising to unlock the quiz!';
  const lowest = [...skills].sort((a, b) => a.pct - b.pct)[0];
  const worstActivity = weaknesses[0] ?? 'your weakest activity';
  return `Focus on ${lowest.name} before the quiz — try "${worstActivity}" again to boost your score!`;
}
