-- Vocabulary support tables for the Athena refresh pipeline (Part A1).
-- omop_concept itself already exists (seeded structure); the loader rebuilds
-- it via staging + RENAME swap, so it is not touched here.

CREATE TABLE IF NOT EXISTS `omop_concept_synonym` (
  `concept_id` int UNSIGNED NOT NULL,
  `concept_synonym_name` varchar(1000) NOT NULL,
  `language_concept_id` int UNSIGNED NOT NULL,
  KEY `ind_syn_concept_id` (`concept_id`),
  FULLTEXT KEY `omop_syn_fulltext` (`concept_synonym_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `omop_concept_relationship` (
  `concept_id_1` int UNSIGNED NOT NULL,
  `concept_id_2` int UNSIGNED NOT NULL,
  `relationship_id` varchar(20) NOT NULL,
  `valid_start_date` char(8) NOT NULL,
  `valid_end_date` char(8) NOT NULL,
  `invalid_reason` char(1) DEFAULT NULL,
  PRIMARY KEY (`concept_id_1`,`concept_id_2`,`relationship_id`),
  KEY `ind_rel_concept_id_1` (`concept_id_1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `omop_vocabulary` (
  `vocabulary_id` varchar(20) NOT NULL,
  `vocabulary_name` varchar(255) NOT NULL,
  `vocabulary_reference` varchar(255) DEFAULT NULL,
  `vocabulary_version` varchar(255) DEFAULT NULL,
  `vocabulary_concept_id` int UNSIGNED NOT NULL,
  PRIMARY KEY (`vocabulary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comet_vocab_meta` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `athena_release` varchar(255) NOT NULL,
  `loaded_at` datetime NOT NULL,
  `loaded_by` varchar(100) NOT NULL,
  `concept_count` int UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
