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
| GARANTIR CARRINHO
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["carrinho"])) {

    $_SESSION["carrinho"] = [];
}


/*
|--------------------------------------------------------------------------
| MENSAGEM
|--------------------------------------------------------------------------
*/

$mensagem = "";

$erro = "";


/*
|--------------------------------------------------------------------------
| REMOVER ITEM
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["acao"]) &&
    $_POST["acao"] === "remover"
) {

    $indice = isset($_POST["indice"])
        ? (int) $_POST["indice"]
        : -1;


    if (
        isset($_SESSION["carrinho"][$indice])
    ) {

        unset(
            $_SESSION["carrinho"][$indice]
        );


        $_SESSION["carrinho"] =
            array_values(
                $_SESSION["carrinho"]
            );


        $mensagem =
            "Produto removido do carrinho.";
    }
}


/*
|--------------------------------------------------------------------------
| ATUALIZAR QUANTIDADES
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["acao"]) &&
    $_POST["acao"] === "atualizar"
) {

    $quantidades =
        $_POST["quantidade"] ?? [];


    foreach (
        $_SESSION["carrinho"]
        as $indice => $item
    ) {

        if (
            !isset(
                $quantidades[$indice]
            )
        ) {
            continue;
        }


        $quantidade =
            (int)$quantidades[$indice];


        /*
        |--------------------------------------------------------------
        | QUANTIDADE INVÁLIDA
        |--------------------------------------------------------------
        */

        if ($quantidade < 1) {

            unset(
                $_SESSION["carrinho"][$indice]
            );

            continue;
        }


        /*
        |--------------------------------------------------------------
        | LIMITE
        |--------------------------------------------------------------
        */

        if ($quantidade > 100) {

            $quantidade = 100;
        }


        $_SESSION["carrinho"][$indice]["quantidade"]
            = $quantidade;
    }


    $_SESSION["carrinho"] =
        array_values(
            $_SESSION["carrinho"]
        );


    $mensagem =
        "Carrinho atualizado.";
}


/*
|--------------------------------------------------------------------------
| PEGAR CARRINHO
|--------------------------------------------------------------------------
*/

$carrinho =
    $_SESSION["carrinho"];


/*
|--------------------------------------------------------------------------
| TOTAL
|--------------------------------------------------------------------------
*/

$total = 0;

$totalItens = 0;


/*
|--------------------------------------------------------------------------
| CONFEITEIRA DO CARRINHO
|--------------------------------------------------------------------------
*/

$confeiteira_id = null;


/*
|--------------------------------------------------------------------------
| CALCULAR VALORES
|--------------------------------------------------------------------------
*/

foreach (
    $carrinho
    as $item
) {

    $quantidade =
        (int)$item["quantidade"];


    $preco =
        (float)$item["preco"];


    $subtotal =
        $quantidade * $preco;


    $total += $subtotal;


    $totalItens +=
        $quantidade;


    if (
        $confeiteira_id === null
    ) {

        $confeiteira_id =
            (int)$item["confeiteira_id"];
    }
}


/*
|--------------------------------------------------------------------------
| BUSCAR NOME DA CONFEITEIRA
|--------------------------------------------------------------------------
*/

$nomeConfeitaria = "";


if ($confeiteira_id !== null) {

    $sql = "
        SELECT
            nome_confeitaria,
            cidade
        FROM confeiteiras
        WHERE id = ?
        LIMIT 1
    ";


    $stmt =
        $pdo->prepare($sql);


    $stmt->execute([
        $confeiteira_id
    ]);


    $confeitaria =
        $stmt->fetch();


    if ($confeitaria) {

        $nomeConfeitaria =
            $confeitaria["nome_confeitaria"];
    }
}


/*
|--------------------------------------------------------------------------
| NOME DO CLIENTE
|--------------------------------------------------------------------------
*/

$nomeCliente =
    $_SESSION["cliente_nome"]
    ?? $_SESSION["usuario_nome"]
    ?? "Cliente";

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
        Meu Carrinho - Sistema de Bolos
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


        .menu .ativo {

            background: #7b2cbf;

            color: white;

            font-weight: bold;
        }


        .menu .ativo:hover {

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

            max-width: 1200px;

            margin:
                0 auto;

            padding:
                30px 20px 50px;
        }


        /*
        |--------------------------------------------------------------------------
        | TÍTULO
        |--------------------------------------------------------------------------
        */

        .titulo {

            margin-bottom: 25px;
        }


        .titulo h1 {

            font-size: 30px;

            margin-bottom: 8px;
        }


        .titulo p {

            color: #777;

            font-size: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | ALERTAS
        |--------------------------------------------------------------------------
        */

        .mensagem {

            background: #d1e7dd;

            color: #0f5132;

            border:
                1px solid #a3cfbb;

            padding: 12px 15px;

            border-radius: 8px;

            margin-bottom: 20px;
        }


        .erro {

            background: #f8d7da;

            color: #842029;

            border:
                1px solid #f1aeb5;

            padding: 12px 15px;

            border-radius: 8px;

            margin-bottom: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | CONTEÚDO
        |--------------------------------------------------------------------------
        */

        .layout {

            display: grid;

            grid-template-columns:
                minmax(
                    0,
                    1fr
                )
                350px;

            gap: 25px;

            align-items: start;
        }


        /*
        |--------------------------------------------------------------------------
        | LISTA
        |--------------------------------------------------------------------------
        */

        .lista {

            display: flex;

            flex-direction: column;

            gap: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | CONFEITEIRA
        |--------------------------------------------------------------------------
        */

        .confeiteira {

            background: white;

            border-radius: 12px;

            padding: 18px 20px;

            box-shadow:
                0 3px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.05
                );

            margin-bottom: 5px;
        }


        .confeiteira small {

            display: block;

            color: #777;

            margin-bottom: 5px;

            font-size: 12px;
        }


        .confeiteira strong {

            color: #7b2cbf;

            font-size: 18px;
        }


        /*
        |--------------------------------------------------------------------------
        | ITEM
        |--------------------------------------------------------------------------
        */

        .item {

            background: white;

            border-radius: 14px;

            padding: 20px;

            display: grid;

            grid-template-columns:
                80px
                minmax(
                    0,
                    1fr
                )
                auto;

            gap: 18px;

            align-items: center;

            box-shadow:
                0 3px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.05
                );
        }


        /*
        |--------------------------------------------------------------------------
        | ÍCONE DO PRODUTO
        |--------------------------------------------------------------------------
        */

        .imagem {

            width: 80px;

            height: 80px;

            border-radius: 12px;

            background:
                linear-gradient(
                    135deg,
                    #f3e5f5,
                    #eadcf3
                );

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 35px;
        }


        /*
        |--------------------------------------------------------------------------
        | INFORMAÇÕES
        |--------------------------------------------------------------------------
        */

        .informacoes h3 {

            font-size: 18px;

            margin-bottom: 7px;
        }


        .preco-unitario {

            color: #777;

            font-size: 13px;

            margin-bottom: 8px;
        }


        .observacao {

            color: #666;

            font-size: 13px;

            line-height: 1.4;
        }


        /*
        |--------------------------------------------------------------------------
        | QUANTIDADE
        |--------------------------------------------------------------------------
        */

        .quantidade {

            display: flex;

            align-items: center;

            gap: 8px;

            margin-top: 12px;
        }


        .quantidade label {

            font-size: 13px;

            font-weight: bold;

            color: #555;
        }


        .quantidade input {

            width: 70px;

            padding: 9px;

            border:
                1px solid #ddd;

            border-radius: 7px;

            text-align: center;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | LADO DIREITO
        |--------------------------------------------------------------------------
        */

        .item-direita {

            text-align: right;

            min-width: 120px;
        }


        .subtotal {

            font-size: 19px;

            font-weight: bold;

            color: #7b2cbf;

            margin-bottom: 10px;
        }


        .btn-remover {

            border: none;

            background: transparent;

            color: #dc3545;

            cursor: pointer;

            font-size: 13px;

            padding: 5px;
        }


        .btn-remover:hover {

            text-decoration: underline;
        }


        /*
        |--------------------------------------------------------------------------
        | RESUMO
        |--------------------------------------------------------------------------
        */

        .resumo {

            background: white;

            border-radius: 14px;

            padding: 25px;

            box-shadow:
                0 4px 15px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );

            position: sticky;

            top: 100px;
        }


        .resumo h2 {

            font-size: 21px;

            margin-bottom: 20px;
        }


        .linha {

            display: flex;

            justify-content: space-between;

            gap: 15px;

            margin-bottom: 12px;

            color: #666;

            font-size: 14px;
        }


        .linha.total {

            border-top:
                1px solid #eee;

            padding-top: 17px;

            margin-top: 17px;

            color: #333;

            font-size: 18px;

            font-weight: bold;
        }


        .linha.total strong {

            color: #7b2cbf;

            font-size: 23px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÕES
        |--------------------------------------------------------------------------
        */

        .acoes {

            display: flex;

            flex-direction: column;

            gap: 10px;

            margin-top: 22px;
        }


        .btn {

            display: block;

            width: 100%;

            text-align: center;

            padding: 13px;

            border-radius: 9px;

            text-decoration: none;

            font-weight: bold;

            cursor: pointer;

            border: none;

            font-size: 14px;
        }


        .btn-atualizar {

            background: #eee;

            color: #555;
        }


        .btn-atualizar:hover {

            background: #ddd;
        }


        .btn-finalizar {

            background: #7b2cbf;

            color: white;
        }


        .btn-finalizar:hover {

            background: #6420a0;
        }


        .btn-continuar {

            background: transparent;

            color: #7b2cbf;

            border:
                1px solid #7b2cbf;
        }


        .btn-continuar:hover {

            background: #f5eafa;
        }


        /*
        |--------------------------------------------------------------------------
        | CARRINHO VAZIO
        |--------------------------------------------------------------------------
        */

        .vazio {

            background: white;

            border-radius: 15px;

            padding:
                60px 25px;

            text-align: center;

            box-shadow:
                0 3px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.05
                );
        }


        .vazio .icone {

            font-size: 60px;

            margin-bottom: 15px;
        }


        .vazio h2 {

            margin-bottom: 10px;

            color: #444;
        }


        .vazio p {

            color: #777;

            margin-bottom: 22px;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 850px) {

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


            .layout {

                grid-template-columns: 1fr;
            }


            .resumo {

                position: static;
            }

        }


        @media (max-width: 600px) {

            .container {

                padding:
                    20px 15px 40px;
            }


            .item {

                grid-template-columns:
                    65px
                    1fr;
            }


            .imagem {

                width: 65px;

                height: 65px;

                font-size: 28px;
            }


            .item-direita {

                grid-column:
                    1 / -1;

                text-align: left;

                border-top:
                    1px solid #eee;

                padding-top: 12px;

                display: flex;

                justify-content:
                    space-between;

                align-items: center;
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



        <a
            href="carrinho.php"
            class="ativo"
        >

            🛒 Carrinho

            <?php if ($totalItens > 0): ?>

                (<?= $totalItens ?>)

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


    <!--
    |--------------------------------------------------------------------------
    | TÍTULO
    |--------------------------------------------------------------------------
    -->

    <div class="titulo">

        <h1>

            🛒 Meu carrinho

        </h1>


        <p>

            Olá,
            <?= htmlspecialchars($nomeCliente) ?>.
            Confira seu pedido antes de continuar.

        </p>

    </div>



    <!--
    |--------------------------------------------------------------------------
    | MENSAGEM
    |--------------------------------------------------------------------------
    -->

    <?php if ($mensagem !== ""): ?>

        <div class="mensagem">

            <?= htmlspecialchars($mensagem) ?>

        </div>

    <?php endif; ?>



    <?php if (count($carrinho) > 0): ?>


        <!--
        |--------------------------------------------------------------------------
        | CONTEÚDO DO CARRINHO
        |--------------------------------------------------------------------------
        -->

        <div class="layout">


            <!--
            |--------------------------------------------------------------------------
            | LISTA
            |--------------------------------------------------------------------------
            -->

            <div class="lista">


                <!-- CONFEITEIRA -->

                <div class="confeiteira">

                    <small>
                        Seu pedido será enviado para:
                    </small>

                    <strong>

                        🧁

                        <?= htmlspecialchars(
                            $nomeConfeitaria
                        ) ?>

                    </strong>

                </div>



                <!--
                |--------------------------------------------------------------------------
                | FORMULÁRIO DE ATUALIZAÇÃO
                |--------------------------------------------------------------------------
                -->

                <form
                    method="POST"
                    action=""
                >

                    <input
                        type="hidden"
                        name="acao"
                        value="atualizar"
                    >


                    <?php foreach (
                        $carrinho
                        as $indice => $item
                    ): ?>


                        <?php

                        $quantidade =
                            (int)$item["quantidade"];


                        $preco =
                            (float)$item["preco"];


                        $subtotal =
                            $quantidade * $preco;

                        ?>


                        <article class="item">


                            <!-- ÍCONE -->

                            <div class="imagem">

                                🎂

                            </div>



                            <!-- INFORMAÇÕES -->

                            <div class="informacoes">


                                <h3>

                                    <?= htmlspecialchars(
                                        $item["nome"]
                                    ) ?>

                                </h3>



                                <div class="preco-unitario">

                                    R$

                                    <?= number_format(
                                        $preco,
                                        2,
                                        ",",
                                        "."
                                    ) ?>

                                    por unidade

                                </div>



                                <?php if (
                                    !empty(
                                        $item["observacao"]
                                    )
                                ): ?>

                                    <div class="observacao">

                                        <strong>
                                            Observação:
                                        </strong>

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $item["observacao"]
                                            )
                                        ) ?>

                                    </div>

                                <?php endif; ?>



                                <div class="quantidade">

                                    <label
                                        for="quantidade_<?= $indice ?>"
                                    >

                                        Quantidade:

                                    </label>


                                    <input
                                        type="number"
                                        id="quantidade_<?= $indice ?>"
                                        name="quantidade[<?= $indice ?>]"
                                        value="<?= $quantidade ?>"
                                        min="1"
                                        max="100"
                                    >

                                </div>


                            </div>



                            <!-- DIREITA -->

                            <div class="item-direita">


                                <div>

                                    <div class="subtotal">

                                        R$

                                        <?= number_format(
                                            $subtotal,
                                            2,
                                            ",",
                                            "."
                                        ) ?>

                                    </div>


                                    <button
                                        type="submit"
                                        name="acao"
                                        value="remover"
                                        formaction=""
                                        class="btn-remover"
                                        onclick="return removerItem(<?= $indice ?>);"
                                    >

                                        Remover

                                    </button>

                                </div>


                            </div>


                        </article>


                    <?php endforeach; ?>


                    <div style="
                        margin-top: 15px;
                        text-align: right;
                    ">

                        <button
                            type="submit"
                            class="btn btn-atualizar"
                        >

                            🔄 Atualizar carrinho

                        </button>

                    </div>


                </form>


            </div>



            <!--
            |--------------------------------------------------------------------------
            | RESUMO
            |--------------------------------------------------------------------------
            -->

            <aside class="resumo">


                <h2>

                    Resumo do pedido

                </h2>



                <div class="linha">

                    <span>

                        Itens

                    </span>


                    <strong>

                        <?= $totalItens ?>

                    </strong>

                </div>



                <div class="linha">

                    <span>

                        Produtos

                    </span>


                    <strong>

                        <?= count($carrinho) ?>

                    </strong>

                </div>



                <div class="linha total">

                    <span>

                        Total

                    </span>


                    <strong>

                        R$

                        <?= number_format(
                            $total,
                            2,
                            ",",
                            "."
                        ) ?>

                    </strong>

                </div>



                <div class="acoes">


                    <a
                        href="finalizar_pedido.php"
                        class="btn btn-finalizar"
                    >

                        Continuar para finalizar

                    </a>



                    <a
                        href="inicio.php"
                        class="btn btn-continuar"
                    >

                        Continuar comprando

                    </a>


                </div>


            </aside>


        </div>


    <?php else: ?>


        <!--
        |--------------------------------------------------------------------------
        | VAZIO
        |--------------------------------------------------------------------------
        -->

        <div class="vazio">


            <div class="icone">

                🛒

            </div>


            <h2>

                Seu carrinho está vazio

            </h2>


            <p>

                Escolha uma confeiteira e
                adicione produtos ao seu pedido.

            </p>


            <a
                href="inicio.php"
                class="btn btn-finalizar"
                style="
                    max-width: 300px;
                    margin: auto;
                "
            >

                Encontrar uma confeiteira

            </a>


        </div>


    <?php endif; ?>


</main>



<script>

function removerItem(indice) {

    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR REMOÇÃO
    |--------------------------------------------------------------------------
    */

    const confirmar =
        confirm(
            "Deseja remover este produto do carrinho?"
        );


    if (!confirmar) {

        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | CRIAR FORMULÁRIO TEMPORÁRIO
    |--------------------------------------------------------------------------
    */

    const form =
        document.createElement("form");

    form.method = "POST";

    form.action = "";


    const acao =
        document.createElement("input");

    acao.type = "hidden";

    acao.name = "acao";

    acao.value = "remover";


    const campoIndice =
        document.createElement("input");

    campoIndice.type = "hidden";

    campoIndice.name = "indice";

    campoIndice.value = indice;


    form.appendChild(acao);

    form.appendChild(campoIndice);


    document.body.appendChild(form);


    form.submit();


    return false;
}

</script>


</body>

</html>