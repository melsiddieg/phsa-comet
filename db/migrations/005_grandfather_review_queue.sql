-- Corrective for DBs where an earlier build of 003 flipped every historical
-- phsa_all_maps_hx.approved_by from '' to NULL, which made all pre-existing map
-- changes appear as "pending review" in list_review.php.
--
-- Grandfather existing history back to '' (= already handled, stays out of the
-- review queue). NEW changes written by log_map_hx after this point get NULL
-- and correctly appear as pending. On a fresh deploy (where 003 no longer does
-- the flip) this is a harmless no-op: seeded rows are already '' and no NULLs
-- exist yet.

UPDATE phsa_all_maps_hx
   SET approved_by = ''
 WHERE approved_by IS NULL;
