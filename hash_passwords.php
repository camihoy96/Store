<?php
require('dbconn.php'); // ensure your DB connection is included

$sql = "SELECT id, Password FROM store.users";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $id = $row['id'];  
    $plainPassword = $row['Password'];  

    // If the password is not already hashed, hash it
    if (!password_get_info($plainPassword)['algo']) {
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        $updateSQL = "UPDATE store.users SET Password = ? WHERE id = ?";
        $stmt = $conn->prepare($updateSQL);
        $stmt->bind_param("si", $hashedPassword, $id);
        $stmt->execute();
    }
}

echo "Password hashing complete!";
?>
