<?php
include 'config.php';

$sql = 'UPDATE spk SET use_custom_price = 0';
if (mysqli_query($conn, $sql)) {
    echo 'Reset semua SPK ke use_custom_price = 0 berhasil' . PHP_EOL;
    $count = mysqli_affected_rows($conn);
    echo 'Rows updated: ' . $count;
} else {
    echo 'Error: ' . mysqli_error($conn);
}
?>
