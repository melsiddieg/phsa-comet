-- Named, versioned map releases for reproducible exports.
--
-- Distinct from phsa_maps_snapshot, which the review workflow uses for
-- per-approval before/after comparison. A release is an explicit, named,
-- vocabulary-tagged freeze of all current maps that ETL consumers can pin to.

CREATE TABLE IF NOT EXISTS `comet_map_releases` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `vocab_release` varchar(255) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL,
  `map_count` int UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_release_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comet_map_release_maps` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `release_id` int UNSIGNED NOT NULL,
  `src_data_id` int UNSIGNED DEFAULT NULL,
  `source_code` varchar(60) NOT NULL,
  `source_vocabulary_id` varchar(25) NOT NULL,
  `source_code_description` text,
  `target_concept_id` varchar(25) NOT NULL,
  `target_concept_name` text,
  `target_vocabulary_id` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ind_release_id` (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
