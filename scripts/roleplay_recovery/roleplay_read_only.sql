-- Read-only checks. Run against production or a Render recovery database with psql.

-- All roleplay records and dialogue counts.
SELECT id, unit_id, created_at,
       CASE
         WHEN jsonb_typeof(data->'turns') = 'array' THEN jsonb_array_length(data->'turns')
         WHEN jsonb_typeof(data->'dialogue') = 'array' THEN jsonb_array_length(data->'dialogue')
         WHEN jsonb_typeof(data->'dialogs') = 'array' THEN jsonb_array_length(data->'dialogs')
         WHEN jsonb_typeof(data->'lines') = 'array' THEN jsonb_array_length(data->'lines')
         WHEN jsonb_typeof(data->'items') = 'array' THEN jsonb_array_length(data->'items')
         ELSE 0
       END AS turn_count,
       data
FROM activities
WHERE lower(type) = 'roleplay'
ORDER BY id;

-- Revisions that contain more dialogue than the current record.
SELECT a.id AS activity_id, r.id AS revision_id, r.created_at,
       CASE
         WHEN jsonb_typeof(a.data->'turns') = 'array' THEN jsonb_array_length(a.data->'turns')
         WHEN jsonb_typeof(a.data->'dialogue') = 'array' THEN jsonb_array_length(a.data->'dialogue')
         WHEN jsonb_typeof(a.data->'dialogs') = 'array' THEN jsonb_array_length(a.data->'dialogs')
         WHEN jsonb_typeof(a.data->'lines') = 'array' THEN jsonb_array_length(a.data->'lines')
         WHEN jsonb_typeof(a.data->'items') = 'array' THEN jsonb_array_length(a.data->'items')
         ELSE 0
       END AS current_turn_count,
       CASE
         WHEN jsonb_typeof(r.data->'turns') = 'array' THEN jsonb_array_length(r.data->'turns')
         WHEN jsonb_typeof(r.data->'dialogue') = 'array' THEN jsonb_array_length(r.data->'dialogue')
         WHEN jsonb_typeof(r.data->'dialogs') = 'array' THEN jsonb_array_length(r.data->'dialogs')
         WHEN jsonb_typeof(r.data->'lines') = 'array' THEN jsonb_array_length(r.data->'lines')
         WHEN jsonb_typeof(r.data->'items') = 'array' THEN jsonb_array_length(r.data->'items')
         ELSE 0
       END AS revision_turn_count
FROM activities a
JOIN roleplay_revisions r ON r.activity_id = a.id
WHERE lower(a.type) = 'roleplay'
  AND (
    CASE
      WHEN jsonb_typeof(r.data->'turns') = 'array' THEN jsonb_array_length(r.data->'turns')
      WHEN jsonb_typeof(r.data->'dialogue') = 'array' THEN jsonb_array_length(r.data->'dialogue')
      WHEN jsonb_typeof(r.data->'dialogs') = 'array' THEN jsonb_array_length(r.data->'dialogs')
      WHEN jsonb_typeof(r.data->'lines') = 'array' THEN jsonb_array_length(r.data->'lines')
      WHEN jsonb_typeof(r.data->'items') = 'array' THEN jsonb_array_length(r.data->'items')
      ELSE 0
    END
  ) > (
    CASE
      WHEN jsonb_typeof(a.data->'turns') = 'array' THEN jsonb_array_length(a.data->'turns')
      WHEN jsonb_typeof(a.data->'dialogue') = 'array' THEN jsonb_array_length(a.data->'dialogue')
      WHEN jsonb_typeof(a.data->'dialogs') = 'array' THEN jsonb_array_length(a.data->'dialogs')
      WHEN jsonb_typeof(a.data->'lines') = 'array' THEN jsonb_array_length(a.data->'lines')
      WHEN jsonb_typeof(a.data->'items') = 'array' THEN jsonb_array_length(a.data->'items')
      ELSE 0
    END
  )
ORDER BY a.id, r.created_at;

-- Inspect one activity's dialogue (replace 123).
SELECT id, data
FROM activities
WHERE id = 123 AND lower(type) = 'roleplay';

-- Inspect all revisions for one activity (replace 123).
SELECT id, activity_id, created_at, data
FROM roleplay_revisions
WHERE activity_id = 123
ORDER BY created_at, id;
