<?php

require_once "config/banco.php";

$nome = "Administrador";
$email = "admin@sistema.com";
$senha = "admin123";

$senhaHash = password_hash(
    $senha,
    PASSWORD_DEFAULT
);

$sql = "INSERT INTO usuarios
        (nome, email, senha, tipo, status)
        VALUES (?, ?, ?, 'admin', 1)";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $nome,
    $email,
    $senhaHash
]);

echo "Administrador criado com sucesso!";