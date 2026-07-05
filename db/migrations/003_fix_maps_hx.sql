-- The seed dump's phsa_all_maps_hx DDL declares every column NOT NULL with no
-- default, which breaks the app under MySQL 8.4 strict mode:
--   * log_map_hx() omits target_concept_name / target_vocabulary_id /
--     approved_* -> every audit insert fails silently (legacy PDO has no
--     exception mode), so NO map change history was being written locally.
--   * The review workflow (list_review.php, edit_review_item.php) filters on
--     "approved_by IS NULL" for pending changes, which requires the approved_*
--     columns to be nullable (NULL = not yet reviewed).
-- Seeded rows carry '0000-00-00 00:00:00' zero-dates, so relax sql_mode while
-- converting them. Making approved_by nullable lets NEW log_map_hx rows (which
-- don't set it) default to NULL = "pending review". Existing history keeps
-- approved_by = '' and stays OUT of the review queue (list_review.php filters
-- on approved_by IS NULL), so this does NOT retroactively flood reviewers with
-- already-made historical changes.

SET SESSION sql_mode = '';

UPDATE phsa_all_maps_hx
   SET approved_dttm = '1970-01-01 00:00:00'
 WHERE approved_dttm = '0000-00-00 00:00:00';

ALTER TABLE `phsa_all_maps_hx`
  MODIFY `target_concept_name` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  MODIFY `target_vocabulary_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  MODIFY `before_update_target_concept_id` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  MODIFY `approved_by` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  MODIFY `approved_dttm` datetime NULL DEFAULT NULL;

UPDATE phsa_all_maps_hx
   SET approved_dttm = NULL
 WHERE approved_dttm = '1970-01-01 00:00:00';

SET SESSION sql_mode = DEFAULT;
