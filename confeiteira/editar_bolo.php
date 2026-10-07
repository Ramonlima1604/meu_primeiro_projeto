<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");


/*
|--------------------------------------------------------------------------
| CONFEITEIRA
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
| BOLO
|--------------------------------------------------------------------------
*/

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;


if ($id <= 0) {

    header("Location: bolos.php");

    exit;
}


$stmt = $pdo->prepare("
    SELECT *

    FROM bolos

    WHERE id = ?

    AND confeiteira_id = ?

    LIMIT 1
");

$stmt->execute([
    $id,
    $confeiteiraId
]);

$bolo = $stmt->fetch();


if (!$bolo) {

    die("Bolo não encontrado.");

}


$erro = "";


/*
|--------------------------------------------------------------------------
| SALVAR
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome =
        trim($_POST["nome"]);

    $descricao =
        trim($_POST["descricao"]);

    $categoria =
        trim($_POST["categoria"]);

    $tamanho =
        trim($_POST["tamanho"]);

    $rendimento =
        trim($_POST["rendimento"]);

    $preco =
        (float) $_POST["preco_venda"];


    if ($nome === "") {

        $erro =
            "Informe o nome do bolo.";

    } elseif ($preco < 0) {

        $erro =
            "O preço não pode ser negativo.";

    } else {

        $stmt = $pdo->prepare("
            UPDATE bolos

            SET
                nome = ?,
                descricao = ?,
                categoria = ?,
                tamanho = ?,
                rendimento = ?,
                preco_venda = ?

            WHERE id = ?

            AND confeiteira_id = ?
        ");

        $stmt->execute([

            $nome,

            $descricao,

            $categoria,

            $tamanho,

            $rendimento,

            $preco,

            $id,

            $confeiteiraId

        ]);


        header(
            "Location: bolos.php"
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
        Editar Bolo
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
                Editar Bolo
            </h1>

            <p>
                Alterar produto
            </p>

        </div>


        <a href="bolos.php">
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
                    Nome do bolo *
                </label>

                <input
                    type="text"
                    name="nome"
                    value="<?= htmlspecialchars(
                        $bolo["nome"]
                    ) ?>"
                    required
                >


                <label>
                    Descrição
                </label>

                <textarea
                    name="descricao"
                    rows="4"
                ><?= htmlspecialchars(
                    $bolo["descricao"]
                ) ?></textarea>


                <label>
                    Categoria
                </label>

                <input
                    type="text"
                    name="categoria"
                    value="<?= htmlspecialchars(
                        $bolo["categoria"]
                    ) ?>"
                >


                <label>
                    Tamanho
                </label>

                <input
                    type="text"
                    name="tamanho"
                    value="<?= htmlspecialchars(
                        $bolo["tamanho"]
                    ) ?>"
                >


                <label>
                    Rendimento
                </label>

                <input
                    type="text"
                    name="rendimento"
                    value="<?= htmlspecialchars(
                        $bolo["rendimento"]
                    ) ?>"
                >


                <label>
                    Preço de venda *
                </label>

                <input
                    type="number"
                    name="preco_venda"
                    step="0.01"
                    min="0"
                    value="<?= $bolo[
                        "preco_venda"
                    ] ?>"
                    required
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