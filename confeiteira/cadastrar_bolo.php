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


$erro = "";


/*
|--------------------------------------------------------------------------
| CADASTRAR
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
            INSERT INTO bolos
            (
                confeiteira_id,
                nome,
                descricao,
                categoria,
                tamanho,
                rendimento,
                preco_venda,
                disponivel
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                1
            )
        ");

        $stmt->execute([

            $confeiteiraId,

            $nome,

            $descricao,

            $categoria,

            $tamanho,

            $rendimento,

            $preco

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
        Novo Bolo
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
                Novo Bolo
            </h1>

            <p>
                Cadastrar produto
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
                    placeholder="Ex: Bolo de Chocolate"
                    required
                >


                <label>
                    Descrição
                </label>

                <textarea
                    name="descricao"
                    rows="4"
                    placeholder="Descrição do produto"
                ></textarea>


                <label>
                    Categoria
                </label>

                <input
                    type="text"
                    name="categoria"
                    placeholder="Ex: Bolos tradicionais"
                >


                <label>
                    Tamanho
                </label>

                <input
                    type="text"
                    name="tamanho"
                    placeholder="Ex: 1,5 kg"
                >


                <label>
                    Rendimento
                </label>

                <input
                    type="text"
                    name="rendimento"
                    placeholder="Ex: 15 pessoas"
                >


                <label>
                    Preço de venda *
                </label>

                <input
                    type="number"
                    name="preco_venda"
                    step="0.01"
                    min="0"
                    placeholder="Ex: 80.00"
                    required
                >


                <button
                    type="submit"
                    class="botao"
                >
                    Cadastrar Bolo
                </button>


            </form>

        </div>

    </main>

</div>

</body>

</html>