-- =====================================================
-- Migration: Add harga_satuan & hpp_satuan to spk_items
-- Run once in phpMyAdmin or MySQL CLI
-- =====================================================

ALTER TABLE spk_items 
  ADD COLUMN harga_satuan DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER qty,
  ADD COLUMN hpp_satuan DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER harga_satuan;
