-- Migration 022: Add index on review_section_scores(review_id) for batch-fetch queries
ALTER TABLE review_section_scores
  ADD INDEX idx_rss_review_id (review_id);
