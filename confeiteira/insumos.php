<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| ID DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $_SESSION["usuario_id"]
]);

$confeiteiraId = $stmt->fetchColumn();


if (!$confeiteiraId) {

    die("Confeiteira não encontrada.");

}


/*
|--------------------------------------------------------------------------
| ATIVAR / DESATIVAR
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["acao"]) &&
    isset($_GET["id"])
) {

    $id = (int) $_GET["id"];

    if ($_GET["acao"] === "desativar") {

        $stmt = $pdo->prepare("
            UPDATE insumos

            SET ativo = 0

            WHERE id = ?

            AND confeiteira_id = ?
        ");

        $stmt->execute([
            $id,
            $confeiteiraId
        ]);
    }


    if ($_GET["acao"] === "ativar") {

        $stmt = $pdo->prepare("
            UPDATE insumos

            SET ativo = 1

            WHERE id = ?

            AND confeiteira_id = ?
        ");

        $stmt->execute([
            $id,
            $confeiteiraId
        ]);
    }


    header("Location: insumos.php");

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR INSUMOS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *

    FROM insumos

    WHERE confeiteira_id = ?

    ORDER BY tipo, nome
");

$stmt->execute([
    $confeiteiraId
]);

$insumos = $stmt->fetchAll();

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
        Meus Insumos
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <header class="topo">

        <div>

            <h1>
                Meus Insumos
            </h1>

            <p>
                Ingredientes, materiais e decorações
            </p>

        </div>


        <a href="index.php">
            Dashboard
        </a>

    </header>


    <main>


        <div class="pagina-acoes">

            <a
                href="cadastrar_insumo.php"
                class="botao"
            >
                + Novo Insumo
            </a>

        </div>


        <div class="tabela-container">

            <table>

                <thead>

                    <tr>

                        <th>
                            Nome
                        </th>

                        <th>
                            Tipo
                        </th>

                        <th>
                            Unidade
                        </th>

                        <th>
                            Embalagem
                        </th>

                        <th>
                            Preço
                        </th>

                        <th>
                            Custo unitário
                        </th>

                        <th>
                            Estoque
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Ações
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php foreach ($insumos as $insumo): ?>


                    <tr>


                        <td>

                            <?= htmlspecialchars(
                                $insumo["nome"]
                            ) ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                ucfirst(
                                    $insumo["tipo"]
                                )
                            ) ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $insumo["unidade"]
                            ) ?>

                        </td>


                        <td>

                            <?= number_format(
                                $insumo["quantidade_embalagem"],
                                3,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <td>

                            R$

                            <?= number_format(
                                $insumo["preco_embalagem"],
                                2,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <td>

                            R$

                            <?= number_format(
                                $insumo["custo_unitario"],
                                4,
                                ",",
                                "."
                            ) ?>

                        </td>


                        <td>

                            <?= number_format(
                                $insumo["estoque"],
                                3,
                                ",",
                                "."
                            ) ?>

                            <?= htmlspecialchars(
                                $insumo["unidade"]
                            ) ?>

                        </td>


                        <td>

                            <?php if (
                                $insumo["ativo"] == 1
                            ): ?>

                                <span
                                    class="status ativo"
                                >
                                    Ativo
                                </span>

                            <?php else: ?>

                                <span
                                    class="status inativo"
                                >
                                    Inativo
                                </span>

                            <?php endif; ?>


                        </td>


                        <td>


                            <a
                                href="editar_insumo.php?id=<?= $insumo["id"] ?>"
                                class="acao"
                            >
                                Editar
                            </a>


                            <?php if (
                                $insumo["ativo"] == 1
                            ): ?>

                                <a
                                    href="insumos.php?acao=desativar&id=<?= $insumo["id"] ?>"
                                    class="acao perigo"
                                    onclick="return confirm('Deseja desativar este insumo?')"
                                >
                                    Desativar
                                </a>

                            <?php else: ?>

                                <a
                                    href="insumos.php?acao=ativar&id=<?= $insumo["id"] ?>"
                                    class="acao sucesso"
                                >
                                    Ativar
                                </a>

                            <?php endif; ?>


                        </td>


                    </tr>


                <?php endforeach; ?>


                <?php if (
                    count($insumos) === 0
                ): ?>

                    <tr>

                        <td colspan="9">

                            Nenhum insumo cadastrado.

                        </td>

                    </tr>

                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </main>

</div>

</body>

</html>