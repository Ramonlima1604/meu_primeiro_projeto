<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("admin");

$totalConfeiteiras = $pdo
    ->query("SELECT COUNT(*) FROM confeiteiras")
    ->fetchColumn();

$confeiteirasAtivas = $pdo
    ->query("
        SELECT COUNT(*)
        FROM confeiteiras c
        INNER JOIN usuarios u ON u.id = c.usuario_id
        WHERE u.status = 1
    ")
    ->fetchColumn();

$totalBolos = $pdo
    ->query("SELECT COUNT(*) FROM bolos")
    ->fetchColumn();

$totalInsumos = $pdo
    ->query("SELECT COUNT(*) FROM insumos")
    ->fetchColumn();

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Painel Administrativo</title>

    <link rel="stylesheet"
          href="../assets/style.css">

</head>

<body>

<div class="admin-container">

    <header class="topo">

        <div>
            <h1>Painel Administrativo</h1>

            <p>
                Olá,
                <?= htmlspecialchars($_SESSION["nome"]) ?>
            </p>
        </div>

        <a href="../logout.php">
            Sair
        </a>

    </header>


    <main>

        <h2>Visão geral</h2>


        <div class="cards">


            <div class="card">

                <h3>Confeiteiras</h3>

                <strong>
                    <?= $totalConfeiteiras ?>
                </strong>

                <p>
                    cadastradas
                </p>

            </div>


            <div class="card">

                <h3>Ativas</h3>

                <strong>
                    <?= $confeiteirasAtivas ?>
                </strong>

                <p>
                    confeiteiras ativas
                </p>

            </div>


            <div class="card">

                <h3>Bolos</h3>

                <strong>
                    <?= $totalBolos ?>
                </strong>

                <p>
                    cadastrados
                </p>

            </div>


            <div class="card">

                <h3>Insumos</h3>

                <strong>
                    <?= $totalInsumos ?>
                </strong>

                <p>
                    cadastrados
                </p>

            </div>

        </div>


        <div class="menu-admin">

            <a href="confeiteiras.php">
                Gerenciar Confeiteiras
            </a>

        </div>

    </main>

</div>

</body>

</html>