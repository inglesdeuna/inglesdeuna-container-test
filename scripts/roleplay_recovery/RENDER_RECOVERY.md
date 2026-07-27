# Roleplay recovery procedure

This procedure is restricted to `type = 'roleplay'`. It does not inspect or modify
`roleplay_kids`.

1. In Render, identify the production Postgres service and create a temporary
   database from a backup or point-in-time restore immediately before the
   deployment containing PR #269 (PR #269 merged at `2026-07-27 22:09:43 UTC`).
   Do not use a snapshot after PR #269 or after PR #270 (`2026-07-27 22:22:33
   UTC`). Keep the temporary database read-only after creation.
2. Copy the temporary database's **internal** connection URL into a local
   environment variable; never commit it:

   ```sh
   export DATABASE_URL='production-url'
   export ROLEPLAY_HISTORICAL_DATABASE_URL='temporary-recovery-url'
   ```

3. Inspect production without changing it:

   ```sh
   php scripts/roleplay_recovery/inspect_roleplay.php > /tmp/roleplay-current.json
   php scripts/roleplay_recovery/compare_roleplay_revisions.php > /tmp/roleplay-revisions.json
   php scripts/roleplay_recovery/compare_roleplay_databases.php > /tmp/roleplay-history.json
   ```

4. Review only records reported as `likely_loss: true`. Use `--ids=...` to
   narrow every command to an explicit allow-list. The comparison tools are
   read-only and report counts and hashes rather than changing either database.

5. Run the restoration in dry-run mode first (dry-run is also the default):

   ```sh
   php scripts/roleplay_recovery/restore_roleplay.php --ids=123,456 --dry-run
   ```

   The tool restores only the historical dialogue when the historical copy has
   strictly more turns. Current scene and unrelated JSON fields are preserved.
   It never updates records absent from both databases and its SQL update also
   requires `lower(type) = 'roleplay'`.

6. After an explicit review and a fresh production backup, apply only the
   approved IDs:

   ```sh
   php scripts/roleplay_recovery/restore_roleplay.php --ids=123,456 --apply
   ```

7. Re-run the inspection and comparison commands, verify the restored dialogue
   in the application, and retain the temporary database until verification is
   complete. The script does not delete revisions or historical data.
