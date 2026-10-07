<?php

session_start();

require_once "../config/banco.php";


/*
|--------------------------------------------------------------------------
| PROTEÇÃO DA ÁREA DO CLIENTE
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["usuario_id"]) ||
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "cliente" ||
    !isset($_SESSION["cliente_id"])
) {
    header("Location: login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| RECEBER IDs
|--------------------------------------------------------------------------
*/

$confeiteira_id = isset($_GET["confeiteira_id"])
    ? (int) $_GET["confeiteira_id"]
    : 0;

$bolo_id = isset($_GET["bolo_id"])
    ? (int) $_GET["bolo_id"]
    : 0;


if ($confeiteira_id <= 0 || $bolo_id <= 0) {

    header("Location: inicio.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR PRODUTO
|--------------------------------------------------------------------------
|
| Aqui também verificamos se:
|
| 1. A confeiteira existe;
| 2. O usuário da confeiteira está ativo;
| 3. O bolo pertence àquela confeiteira;
| 4. O bolo está ativo.
|
*/

$sql = "
    SELECT
        b.id,
        b.confeiteira_id,
        b.nome,
        b.descricao,
        b.tamanho,
        b.rendimento,
        b.preco_venda,
        b.ativo,

        c.nome_confeitaria,
        c.cidade,
        c.telefone,
        c.descricao AS descricao_confeitaria

    FROM bolos b

    INNER JOIN confeiteiras c
        ON c.id = b.confeiteira_id

    INNER JOIN usuarios u
        ON u.id = c.usuario_id

    WHERE b.id = ?
      AND b.confeiteira_id = ?
      AND b.ativo = 1
      AND u.status = 1

    LIMIT 1
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $bolo_id,
    $confeiteira_id
]);

$produto = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| PRODUTO NÃO ENCONTRADO
|--------------------------------------------------------------------------
*/

if (!$produto) {

    header(
        "Location: catalogo.php?confeiteira_id="
        . $confeiteira_id
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| DADOS
|--------------------------------------------------------------------------
*/

$nomeCliente =
    $_SESSION["cliente_nome"]
    ?? $_SESSION["usuario_nome"]
    ?? "Cliente";


$preco = (float) $produto["preco_venda"];


/*
|--------------------------------------------------------------------------
| MENSAGEM
|--------------------------------------------------------------------------
*/

$erro = "";


/*
|--------------------------------------------------------------------------
| ADICIONAR AO CARRINHO
|--------------------------------------------------------------------------
|
| Nesta etapa vamos utilizar a sessão para armazenar o carrinho.
|
| Estrutura:
|
| $_SESSION["carrinho"]
|
| Cada produto ficará assim:
|
| [
|     "bolo_id" => 1,
|     "confeiteira_id" => 2,
|     "nome" => "...",
|     "preco" => 50,
|     "quantidade" => 2,
|     "observacao" => "..."
| ]
|
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $quantidade = isset($_POST["quantidade"])
        ? (int) $_POST["quantidade"]
        : 0;

    $observacao = trim(
        $_POST["observacao"] ?? ""
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDAR QUANTIDADE
    |--------------------------------------------------------------------------
    */

    if ($quantidade < 1) {

        $erro = "Informe uma quantidade válida.";

    } elseif ($quantidade > 100) {

        $erro = "A quantidade máxima é 100 unidades.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | CRIAR CARRINHO
        |--------------------------------------------------------------------------
        */

        if (!isset($_SESSION["carrinho"])) {

            $_SESSION["carrinho"] = [];
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFICAR SE JÁ EXISTE NO CARRINHO
        |--------------------------------------------------------------------------
        */

        $encontrado = false;


        foreach ($_SESSION["carrinho"] as $indice => $item) {

            if (
                (int)$item["bolo_id"] === $bolo_id &&
                (int)$item["confeiteira_id"] === $confeiteira_id
            ) {

                $_SESSION["carrinho"][$indice]["quantidade"]
                    += $quantidade;


                if ($observacao !== "") {

                    $_SESSION["carrinho"][$indice]["observacao"]
                        = $observacao;
                }


                $encontrado = true;

                break;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | ADICIONAR NOVO ITEM
        |--------------------------------------------------------------------------
        */

        if (!$encontrado) {

            $_SESSION["carrinho"][] = [

                "bolo_id" =>
                    $bolo_id,

                "confeiteira_id" =>
                    $confeiteira_id,

                "nome" =>
                    $produto["nome"],

                "preco" =>
                    $preco,

                "quantidade" =>
                    $quantidade,

                "observacao" =>
                    $observacao

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | IR PARA O CARRINHO
        |--------------------------------------------------------------------------
        */

        header(
            "Location: carrinho.php"
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| TOTAL DE ITENS NO CARRINHO
|--------------------------------------------------------------------------
*/

$totalItensCarrinho = 0;


if (isset($_SESSION["carrinho"])) {

    foreach ($_SESSION["carrinho"] as $item) {

        $totalItensCarrinho +=
            (int)$item["quantidade"];
    }
}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(
            $produto["nome"]
        ) ?>
        - Produto
    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f5f5;

            color: #333;
        }


        /*
        |--------------------------------------------------------------------------
        | TOPO
        |--------------------------------------------------------------------------
        */

        .topo {

            background: white;

            border-bottom:
                1px solid #e5e5e5;

            padding:
                18px 30px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            position: sticky;

            top: 0;

            z-index: 10;
        }


        .logo {

            font-size: 24px;

            font-weight: bold;

            color: #7b2cbf;
        }


        .menu {

            display: flex;

            align-items: center;

            gap: 10px;

            flex-wrap: wrap;
        }


        .menu a {

            text-decoration: none;

            color: #555;

            padding:
                10px 14px;

            border-radius: 8px;

            transition: 0.2s;
        }


        .menu a:hover {

            background: #f1e8f8;

            color: #7b2cbf;
        }


        .menu .carrinho {

            background: #7b2cbf;

            color: white;

            font-weight: bold;
        }


        .menu .carrinho:hover {

            background: #6420a0;

            color: white;
        }


        .menu .sair {

            background: #dc3545;

            color: white;
        }


        .menu .sair:hover {

            background: #bb2d3b;

            color: white;
        }


        /*
        |--------------------------------------------------------------------------
        | CONTAINER
        |--------------------------------------------------------------------------
        */

        .container {

            width: 100%;

            max-width: 1100px;

            margin:
                0 auto;

            padding:
                30px 20px 50px;
        }


        /*
        |--------------------------------------------------------------------------
        | VOLTAR
        |--------------------------------------------------------------------------
        */

        .voltar {

            display: inline-block;

            margin-bottom: 20px;

            color: #7b2cbf;

            text-decoration: none;

            font-weight: bold;
        }


        .voltar:hover {

            text-decoration: underline;
        }


        /*
        |--------------------------------------------------------------------------
        | PRODUTO
        |--------------------------------------------------------------------------
        */

        .produto {

            background: white;

            border-radius: 18px;

            overflow: hidden;

            box-shadow:
                0 5px 20px
                rgba(
                    0,
                    0,
                    0,
                    0.07
                );

            display: grid;

            grid-template-columns:
                1fr 1fr;
        }


        /*
        |--------------------------------------------------------------------------
        | IMAGEM
        |--------------------------------------------------------------------------
        */

        .imagem {

            min-height: 450px;

            background:
                linear-gradient(
                    135deg,
                    #f3e5f5,
                    #eadcf3
                );

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 120px;
        }


        /*
        |--------------------------------------------------------------------------
        | INFORMAÇÕES
        |--------------------------------------------------------------------------
        */

        .informacoes {

            padding: 40px;

            display: flex;

            flex-direction: column;
        }


        .confeitaria {

            color: #7b2cbf;

            font-size: 14px;

            font-weight: bold;

            margin-bottom: 8px;
        }


        .cidade {

            color: #777;

            font-size: 13px;

            margin-bottom: 20px;
        }


        .informacoes h1 {

            font-size: 32px;

            margin-bottom: 15px;

            color: #333;
        }


        .descricao {

            color: #666;

            line-height: 1.7;

            font-size: 15px;

            margin-bottom: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | DETALHES
        |--------------------------------------------------------------------------
        */

        .detalhes {

            display: flex;

            flex-wrap: wrap;

            gap: 10px;

            margin-bottom: 20px;
        }


        .detalhe {

            background: #f5f5f5;

            border-radius: 8px;

            padding:
                9px 12px;

            font-size: 13px;

            color: #555;
        }


        /*
        |--------------------------------------------------------------------------
        | PREÇO
        |--------------------------------------------------------------------------
        */

        .preco {

            color: #7b2cbf;

            font-size: 32px;

            font-weight: bold;

            margin-bottom: 25px;
        }


        /*
        |--------------------------------------------------------------------------
        | FORMULÁRIO
        |--------------------------------------------------------------------------
        */

        .formulario {

            margin-top: auto;
        }


        .campo {

            margin-bottom: 18px;
        }


        .campo label {

            display: block;

            font-weight: bold;

            margin-bottom: 7px;

            font-size: 14px;
        }


        .campo input,
        .campo textarea {

            width: 100%;

            padding: 13px;

            border:
                1px solid #ddd;

            border-radius: 8px;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 14px;

            outline: none;
        }


        .campo input:focus,
        .campo textarea:focus {

            border-color: #7b2cbf;
        }


        .campo textarea {

            resize: vertical;

            min-height: 90px;
        }


        .btn-adicionar {

            width: 100%;

            border: none;

            background: #7b2cbf;

            color: white;

            padding: 15px;

            border-radius: 9px;

            font-size: 16px;

            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;
        }


        .btn-adicionar:hover {

            background: #6420a0;
        }


        /*
        |--------------------------------------------------------------------------
        | ERRO
        |--------------------------------------------------------------------------
        */

        .erro {

            background: #f8d7da;

            color: #842029;

            border:
                1px solid #f1aeb5;

            border-radius: 8px;

            padding: 12px;

            margin-bottom: 18px;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 800px) {

            .topo {

                padding: 15px;

                flex-direction: column;

                align-items: stretch;
            }


            .logo {

                text-align: center;
            }


            .menu {

                justify-content: center;
            }


            .container {

                padding:
                    20px 15px 40px;
            }


            .produto {

                grid-template-columns: 1fr;
            }


            .imagem {

                min-height: 280px;

                font-size: 90px;
            }


            .informacoes {

                padding: 25px 20px;
            }


            .informacoes h1 {

                font-size: 27px;
            }

        }

    </style>

</head>


<body>


<!--
|--------------------------------------------------------------------------
| TOPO
|--------------------------------------------------------------------------
-->

<header class="topo">


    <div class="logo">

        🍰 Sistema de Bolos

    </div>



    <nav class="menu">


        <a href="inicio.php">

            Início

        </a>



        <a href="carrinho.php" class="carrinho">

            🛒 Carrinho

            <?php if ($totalItensCarrinho > 0): ?>

                (<?= $totalItensCarrinho ?>)

            <?php endif; ?>

        </a>



        <a href="meus_pedidos.php">

            Meus pedidos

        </a>



        <a href="minha_conta.php">

            Minha conta

        </a>



        <a
            href="logout.php"
            class="sair"
        >

            Sair

        </a>


    </nav>


</header>



<main class="container">


    <!-- VOLTAR -->

    <a
        href="catalogo.php?confeiteira_id=<?= (int)$confeiteira_id ?>"
        class="voltar"
    >

        ← Voltar para o catálogo

    </a>



    <!-- PRODUTO -->

    <section class="produto">


        <!-- IMAGEM -->

        <div class="imagem">

            🎂

        </div>



        <!-- INFORMAÇÕES -->

        <div class="informacoes">


            <div class="confeitaria">

                <?= htmlspecialchars(
                    $produto["nome_confeitaria"]
                ) ?>

            </div>



            <?php if (!empty($produto["cidade"])): ?>

                <div class="cidade">

                    📍

                    <?= htmlspecialchars(
                        $produto["cidade"]
                    ) ?>

                </div>

            <?php endif; ?>



            <h1>

                <?= htmlspecialchars(
                    $produto["nome"]
                ) ?>

            </h1>



            <?php if (!empty($produto["descricao"])): ?>

                <div class="descricao">

                    <?= nl2br(
                        htmlspecialchars(
                            $produto["descricao"]
                        )
                    ) ?>

                </div>

            <?php else: ?>

                <div class="descricao">

                    Produto preparado
                    especialmente pela confeitaria.

                </div>

            <?php endif; ?>



            <!-- DETALHES -->

            <div class="detalhes">


                <?php if (!empty($produto["tamanho"])): ?>

                    <div class="detalhe">

                        📏

                        <strong>
                            Tamanho:
                        </strong>

                        <?= htmlspecialchars(
                            $produto["tamanho"]
                        ) ?>

                    </div>

                <?php endif; ?>



                <?php if (!empty($produto["rendimento"])): ?>

                    <div class="detalhe">

                        👥

                        <strong>
                            Rendimento:
                        </strong>

                        <?= htmlspecialchars(
                            $produto["rendimento"]
                        ) ?>

                    </div>

                <?php endif; ?>


            </div>



            <!-- PREÇO -->

            <div class="preco">

                R$

                <?= number_format(
                    $preco,
                    2,
                    ",",
                    "."
                ) ?>

            </div>



            <!-- ERRO -->

            <?php if ($erro !== ""): ?>

                <div class="erro">

                    <?= htmlspecialchars($erro) ?>

                </div>

            <?php endif; ?>



            <!-- FORMULÁRIO -->

            <form
                method="POST"
                action=""
                class="formulario"
            >


                <div class="campo">

                    <label for="quantidade">

                        Quantidade

                    </label>


                    <input
                        type="number"
                        id="quantidade"
                        name="quantidade"
                        value="1"
                        min="1"
                        max="100"
                        required
                    >

                </div>



                <div class="campo">

                    <label for="observacao">

                        Observação do pedido

                    </label>


                    <textarea
                        id="observacao"
                        name="observacao"
                        placeholder="Ex.: escrever feliz aniversário, colocar tema específico, etc."
                    ></textarea>

                </div>



                <button
                    type="submit"
                    class="btn-adicionar"
                >

                    🛒 Adicionar ao carrinho

                </button>


            </form>


        </div>


    </section>


</main>


</body>

</html>