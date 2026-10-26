<?php
// check_paths.php - Find the correct paths

echo "<h2>Path Debugging</h2>";

echo "<h3>Current script location:</h3>";
echo "Script path: " . __FILE__ . "<br>";
echo "Script directory: " . __DIR__ . "<br>";

echo "<h3>Testing possible db_connect.php locations:</h3>";

// Test 1: ../include/db_connect.php (relative from product folder)
$path1 = __DIR__ . '/../include/db_connect.php';
echo "Path 1: " . $path1 . " - " . (file_exists($path1) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Test 2: ../../include/db_connect.php (two levels up)
$path2 = __DIR__ . '/../../include/db_connect.php';
echo "Path 2: " . $path2 . " - " . (file_exists($path2) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Test 3: ../db_connect.php (one level up)
$path3 = __DIR__ . '/../db_connect.php';
echo "Path 3: " . $path3 . " - " . (file_exists($path3) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Test 4: include/db_connect.php (in same folder)
$path4 = __DIR__ . '/include/db_connect.php';
echo "Path 4: " . $path4 . " - " . (file_exists($path4) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Test 5: ../config/db_connect.php
$path5 = __DIR__ . '/../config/db_connect.php';
echo "Path 5: " . $path5 . " - " . (file_exists($path5) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Test 6: ../../config/db_connect.php
$path6 = __DIR__ . '/../../config/db_connect.php';
echo "Path 6: " . $path6 . " - " . (file_exists($path6) ? "✅ EXISTS" : "❌ NOT FOUND") . "<br>";

// Show directory structure
echo "<h3>Directory structure:</h3>";
echo "<pre>";

function listFiles($dir, $prefix = '') {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        if (is_dir($dir . '/' . $file)) {
            echo $prefix . "📁 " . $file . "/\n";
            listFiles($dir . '/' . $file, $prefix . '  ');
        } else {
            echo $prefix . "📄 " . $file . "\n";
        }
    }
}

// List the Store directory
$storeDir = __DIR__ . '/..';
echo "Store directory (" . $storeDir . "):\n";
listFiles($storeDir);

echo "</pre>";
?>