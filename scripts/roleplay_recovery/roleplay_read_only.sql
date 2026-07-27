-- Read-only checks. Run against production or a Render recovery database with psql.

-- All roleplay records and dialogue counts.
SELECT id, unit_id, created_at,
       jsonb_array_length(COALESCE(data->'turns', data->'dialogue', data->'dialogs', data->'lines', data->'items', '[]'::jsonb)) AS turn_count,
       data
FROM activities
WHERE lower(type) = 'roleplay'
ORDER BY id;

-- Revisions that contain more dialogue than the current record.
SELECT a.id AS activity_id, r.id AS revision_id, r.created_at,
       jsonb_array_length(COALESCE(a.data->'turns', a.data->'dialogue', a.data->'dialogs', a.data->'lines', a.data->'items', '[]'::jsonb)) AS current_turn_count,
       jsonb_array_length(COALESCE(r.data->'turns', r.data->'dialogue', r.data->'dialogs', r.data->'lines', r.data->'items', '[]'::jsonb)) AS revision_turn_count
FROM activities a
JOIN roleplay_revisions r ON r.activity_id = a.id
WHERE lower(a.type) = 'roleplay'
  AND jsonb_array_length(COALESCE(r.data->'turns', r.data->'dialogue', r.data->'dialogs', r.data->'lines', r.data->'items', '[]'::jsonb))
      > jsonb_array_length(COALESCE(a.data->'turns', a.data->'dialogue', a.data->'dialogs', a.data->'lines', a.data->'items', '[]'::jsonb))
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
