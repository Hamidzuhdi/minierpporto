<?php
/**
 * Migration: Add SPK Revision Tracking Columns
 * Date: 2026-04-19
 * Description: Add columns to track SPK revisions (revision_of_spk_id, revision_number)
 */

require_once __DIR__ . '/../config.php';

try {
    // Check if columns already exist
    $check_query = "SHOW COLUMNS FROM spk LIKE 'revision_of_spk_id'";
    $result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($result) > 0) {
        echo "✓ Columns already exist. Skipping migration.\n";
        exit(0);
    }
    
    // Add revision_of_spk_id column (link to original SPK)
    $sql1 = "ALTER TABLE spk ADD COLUMN revision_of_spk_id INT NULL AFTER id;";
    if (!mysqli_query($conn, $sql1)) {
        throw new Exception("Error adding revision_of_spk_id: " . mysqli_error($conn));
    }
    echo "✓ Added column: revision_of_spk_id\n";
    
    // Add revision_number column (0 for original, 1 for REV1, 2 for REV2, etc)
    $sql2 = "ALTER TABLE spk ADD COLUMN revision_number INT DEFAULT 0 AFTER revision_of_spk_id;";
    if (!mysqli_query($conn, $sql2)) {
        throw new Exception("Error adding revision_number: " . mysqli_error($conn));
    }
    echo "✓ Added column: revision_number\n";
    
    // Add foreign key constraint
    $sql3 = "ALTER TABLE spk ADD CONSTRAINT fk_revision_of_spk_id 
             FOREIGN KEY (revision_of_spk_id) REFERENCES spk(id) ON DELETE SET NULL;";
    if (!mysqli_query($conn, $sql3)) {
        throw new Exception("Error adding foreign key: " . mysqli_error($conn));
    }
    echo "✓ Added foreign key constraint\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
