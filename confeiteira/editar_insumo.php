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
| ID DO INSUMO
|--------------------------------------------------------------------------
*/

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;


if ($id <= 0) {

    header("Location: insumos.php");

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR INSUMO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *

    FROM insumos

    WHERE id = ?

    AND confeiteira_id = ?

    LIMIT 1
");

$stmt->execute([
    $id,
    $confeiteiraId
]);

$insumo = $stmt->fetch();


if (!$insumo) {

    die("Insumo não encontrado.");

}


$erro = "";


/*
|--------------------------------------------------------------------------
| ATUALIZAR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"]);

    $tipo = $_POST["tipo"];

    $unidade = trim($_POST["unidade"]);

    $quantidadeEmbalagem =
        (float) $_POST["quantidade_embalagem"];

    $precoEmbalagem =
        (float) $_POST["preco_embalagem"];

    $estoque =
        (float) $_POST["estoque"];

    $estoqueMinimo =
        (float) $_POST["estoque_minimo"];


    $tiposPermitidos = [
        "ingrediente",
        "material",
        "embalagem",
        "decoracao",
        "outros"
    ];


    if ($nome === "") {

        $erro =
            "Digite o nome do insumo.";

    } elseif (
        !in_array(
            $tipo,
            $tiposPermitidos,
            true
        )
    ) {

        $erro =
            "Tipo inválido.";

    } elseif ($unidade === "") {

        $erro =
            "Digite a unidade.";

    } elseif (
        $quantidadeEmbalagem <= 0
    ) {

        $erro =
            "A quantidade da embalagem deve ser maior que zero.";

    } elseif (
        $precoEmbalagem < 0
    ) {

        $erro =
            "O preço não pode ser negativo.";

    } elseif (
        $estoque < 0 ||
        $estoqueMinimo < 0
    ) {

        $erro =
            "O estoque não pode ser negativo.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | CUSTO UNITÁRIO
        |--------------------------------------------------------------------------
        */

        $custoUnitario =
            $precoEmbalagem
            / $quantidadeEmbalagem;


        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE insumos

            SET

                nome = ?,

                tipo = ?,

                unidade = ?,

                quantidade_embalagem = ?,

                preco_embalagem = ?,

                custo_unitario = ?,

                estoque = ?,

                estoque_minimo = ?

            WHERE id = ?

            AND confeiteira_id = ?
        ");


        $stmt->execute([

            $nome,

            $tipo,

            $unidade,

            $quantidadeEmbalagem,

            $precoEmbalagem,

            $custoUnitario,

            $estoque,

            $estoqueMinimo,

            $id,

            $confeiteiraId

        ]);


        header(
            "Location: insumos.php"
        );

        exit;
    }
}

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
        Editar Insumo
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
                Editar Insumo
            </h1>

            <p>
                Alterar informações
            </p>

        </div>


        <a href="insumos.php">
            Voltar
        </a>

    </header>


    <main>


        <div class="formulario-container">


            <?php if ($erro): ?>

                <div class="erro">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>


            <form method="POST">


                <label>
                    Nome *
                </label>

                <input
                    type="text"
                    name="nome"
                    value="<?= htmlspecialchars(
                        $insumo["nome"]
                    ) ?>"
                    required
                >


                <label>
                    Tipo *
                </label>

                <select
                    name="tipo"
                    required
                >

                    <option
                        value="ingrediente"
                        <?= $insumo["tipo"] === "ingrediente"
                            ? "selected"
                            : "" ?>
                    >
                        Ingrediente
                    </option>

                    <option
                        value="material"
                        <?= $insumo["tipo"] === "material"
                            ? "selected"
                            : "" ?>
                    >
                        Material
                    </option>

                    <option
                        value="embalagem"
                        <?= $insumo["tipo"] === "embalagem"
                            ? "selected"
                            : "" ?>
                    >
                        Embalagem
                    </option>

                    <option
                        value="decoracao"
                        <?= $insumo["tipo"] === "decoracao"
                            ? "selected"
                            : "" ?>
                    >
                        Decoração
                    </option>

                    <option
                        value="outros"
                        <?= $insumo["tipo"] === "outros"
                            ? "selected"
                            : "" ?>
                    >
                        Outros
                    </option>

                </select>


                <label>
                    Unidade *
                </label>

                <select
                    name="unidade"
                    required
                >

                    <option
                        value="g"
                        <?= $insumo["unidade"] === "g"
                            ? "selected"
                            : "" ?>
                    >
                        Gramas (g)
                    </option>

                    <option
                        value="kg"
                        <?= $insumo["unidade"] === "kg"
                            ? "selected"
                            : "" ?>
                    >
                        Quilograma (kg)
                    </option>

                    <option
                        value="ml"
                        <?= $insumo["unidade"] === "ml"
                            ? "selected"
                            : "" ?>
                    >
                        Mililitros (ml)
                    </option>

                    <option
                        value="l"
                        <?= $insumo["unidade"] === "l"
                            ? "selected"
                            : "" ?>
                    >
                        Litros (L)
                    </option>

                    <option
                        value="un"
                        <?= $insumo["unidade"] === "un"
                            ? "selected"
                            : "" ?>
                    >
                        Unidade (un)
                    </option>

                    <option
                        value="m"
                        <?= $insumo["unidade"] === "m"
                            ? "selected"
                            : "" ?>
                    >
                        Metro (m)
                    </option>

                </select>


                <label>
                    Quantidade da embalagem *
                </label>

                <input
                    type="number"
                    name="quantidade_embalagem"
                    step="0.001"
                    min="0.001"
                    value="<?= $insumo[
                        "quantidade_embalagem"
                    ] ?>"
                    required
                >


                <label>
                    Preço da embalagem *
                </label>

                <input
                    type="number"
                    name="preco_embalagem"
                    step="0.01"
                    min="0"
                    value="<?= $insumo[
                        "preco_embalagem"
                    ] ?>"
                    required
                >


                <label>
                    Estoque atual
                </label>

                <input
                    type="number"
                    name="estoque"
                    step="0.001"
                    min="0"
                    value="<?= $insumo[
                        "estoque"
                    ] ?>"
                >


                <label>
                    Estoque mínimo
                </label>

                <input
                    type="number"
                    name="estoque_minimo"
                    step="0.001"
                    min="0"
                    value="<?= $insumo[
                        "estoque_minimo"
                    ] ?>"
                >


                <button
                    type="submit"
                    class="botao"
                >
                    Salvar Alterações
                </button>


            </form>

        </div>

    </main>

</div>

</body>

</html>