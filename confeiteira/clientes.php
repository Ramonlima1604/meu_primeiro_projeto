<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| BUSCAR ID DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $_SESSION["usuario_id"]
]);

$confeiteira = $stmt->fetch();


if (!$confeiteira) {

    die("Perfil da confeiteira não encontrado.");

}


$confeiteiraId = $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| EXCLUIR CLIENTE
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["excluir"]) &&
    is_numeric($_GET["excluir"])
) {

    $clienteId = (int) $_GET["excluir"];


    /*
    |--------------------------------------------------------------
    | Antes de excluir, verificar se o cliente pertence
    | à confeiteira logada.
    |--------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM clientes
        WHERE id = ?
        AND confeiteira_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $clienteId,
        $confeiteiraId
    ]);


    $cliente = $stmt->fetch();


    if ($cliente) {

        /*
        |----------------------------------------------------------
        | Verificar se o cliente possui pedidos.
        | Não vamos excluir um cliente que já possui histórico.
        |----------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM pedidos
            WHERE cliente_id = ?
            AND confeiteira_id = ?
        ");

        $stmt->execute([
            $clienteId,
            $confeiteiraId
        ]);

        $totalPedidos = $stmt->fetchColumn();


        if ($totalPedidos > 0) {

            header(
                "Location: clientes.php?erro=possui_pedidos"
            );

            exit;

        }


        /*
        |----------------------------------------------------------
        | Exclusão
        |----------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            DELETE FROM clientes
            WHERE id = ?
            AND confeiteira_id = ?
        ");

        $stmt->execute([
            $clienteId,
            $confeiteiraId
        ]);

    }


    header("Location: clientes.php");

    exit;

}


/*
|--------------------------------------------------------------------------
| ATIVAR / DESATIVAR CLIENTE
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["alternar"]) &&
    is_numeric($_GET["alternar"])
) {

    $clienteId = (int) $_GET["alternar"];


    $stmt = $pdo->prepare("
        UPDATE clientes
        SET ativo = IF(ativo = 1, 0, 1)
        WHERE id = ?
        AND confeiteira_id = ?
    ");

    $stmt->execute([
        $clienteId,
        $confeiteiraId
    ]);


    header("Location: clientes.php");

    exit;

}


/*
|--------------------------------------------------------------------------
| BUSCAR CLIENTES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        c.*,

        (
            SELECT COUNT(*)
            FROM pedidos p
            WHERE p.cliente_id = c.id
            AND p.confeiteira_id = c.confeiteira_id
        ) AS total_pedidos

    FROM clientes c

    WHERE c.confeiteira_id = ?

    ORDER BY
        c.ativo DESC,
        c.nome ASC
");

$stmt->execute([
    $confeiteiraId
]);

$clientes = $stmt->fetchAll();


?>

<!DOCTYPE html>

<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Clientes
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <!-- TOPO -->

    <header class="topo">

        <div>

            <h1>
                <?= htmlspecialchars(
                    $confeiteira["nome_confeitaria"]
                ) ?>
            </h1>

            <p>
                Clientes
            </p>

        </div>


        <a href="index.php">
            Voltar ao painel
        </a>

    </header>


    <!-- CONTEÚDO -->

    <main>

        <div
            style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:15px;
                flex-wrap:wrap;
                margin-bottom:25px;
            "
        >

            <div>

                <h2>
                    👥 Meus Clientes
                </h2>

                <p>
                    Cadastre e gerencie os clientes da confeitaria.
                </p>

            </div>


            <a
                href="cadastrar_cliente.php"
                class="btn"
            >
                ➕ Novo Cliente
            </a>

        </div>


        <!-- MENSAGEM DE ERRO -->

        <?php if (
            isset($_GET["erro"]) &&
            $_GET["erro"] === "possui_pedidos"
        ): ?>

            <div
                class="card"
                style="
                    margin-bottom:20px;
                    border-left:5px solid #dc3545;
                "
            >

                <strong>
                    Não é possível excluir este cliente.
                </strong>

                <p>
                    Este cliente possui pedidos registrados.
                    Para preservar o histórico, desative o cliente
                    em vez de excluí-lo.
                </p>

            </div>

        <?php endif; ?>


        <!-- LISTA -->

        <?php if (!$clientes): ?>

            <div class="card">

                <h3>
                    Nenhum cliente cadastrado
                </h3>

                <p>
                    Comece cadastrando seu primeiro cliente.
                </p>

                <br>

                <a
                    href="cadastrar_cliente.php"
                    class="btn"
                >
                    ➕ Cadastrar primeiro cliente
                </a>

            </div>

        <?php else: ?>


            <div class="cards">


                <?php foreach ($clientes as $cliente): ?>


                    <div class="card">

                        <h3>
                            <?= htmlspecialchars(
                                $cliente["nome"]
                            ) ?>
                        </h3>


                        <?php if (
                            (int) $cliente["ativo"] === 1
                        ): ?>

                            <span>
                                🟢 Ativo
                            </span>

                        <?php else: ?>

                            <span>
                                🔴 Inativo
                            </span>

                        <?php endif; ?>


                        <p>

                            📱

                            <?= $cliente["telefone"]
                                ? htmlspecialchars(
                                    $cliente["telefone"]
                                )
                                : "Telefone não informado"
                            ?>

                        </p>


                        <?php if (
                            !empty($cliente["email"])
                        ): ?>

                            <p>

                                ✉️

                                <?= htmlspecialchars(
                                    $cliente["email"]
                                ) ?>

                            </p>

                        <?php endif; ?>


                        <p>

                            📋

                            <?= (int) $cliente["total_pedidos"] ?>

                            pedido(s)

                        </p>


                        <?php if (
                            !empty($cliente["cidade"])
                        ): ?>

                            <p>

                                📍

                                <?= htmlspecialchars(
                                    $cliente["cidade"]
                                ) ?>

                            </p>

                        <?php endif; ?>


                        <div
                            style="
                                display:flex;
                                gap:8px;
                                flex-wrap:wrap;
                                margin-top:15px;
                            "
                        >

                            <a
                                href="editar_cliente.php?id=<?= $cliente["id"] ?>"
                                class="btn"
                            >
                                ✏️ Editar
                            </a>


                            <a
                                href="clientes.php?alternar=<?= $cliente["id"] ?>"
                                class="btn"
                            >
                                <?= (int) $cliente["ativo"] === 1
                                    ? "🔴 Desativar"
                                    : "🟢 Ativar"
                                ?>
                            </a>


                            <?php if (
                                (int) $cliente["total_pedidos"] === 0
                            ): ?>

                                <a
                                    href="clientes.php?excluir=<?= $cliente["id"] ?>"
                                    class="btn"
                                    onclick="
                                        return confirm(
                                            'Tem certeza que deseja excluir este cliente?'
                                        );
                                    "
                                >
                                    🗑️ Excluir
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>


                <?php endforeach; ?>


            </div>


        <?php endif; ?>


    </main>

</div>

</body>

</html>