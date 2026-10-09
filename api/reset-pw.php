<?php
require 'config.php';

$accounts = [
    'juan.delacruz'    => 'super123',
    'maria.clara'      => 'admin123',
    'andres.bonifacio' => 'user123',
];

foreach ($accounts as $username => $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE username = ?');
    $stmt->execute([$hash, $username]);
    echo "Updated <strong>" . htmlspecialchars($username) . "</strong>";
    echo " — password <code>" . htmlspecialchars($password) . "</code>";
    echo " — rows affected: " . $stmt->rowCount() . "<br>";
}
echo "<hr><p><strong>Now delete this file!</strong></p>";