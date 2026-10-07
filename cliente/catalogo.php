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
| PEGAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$confeiteira_id = isset($_GET["confeiteira_id"])
    ? (int) $_GET["confeiteira_id"]
    : 0;


if ($confeiteira_id <= 0) {

    header("Location: inicio.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.nome_confeitaria,
        c.telefone,
        c.cidade,
        c.descricao
    FROM confeiteiras c
    INNER JOIN usuarios u
        ON u.id = c.usuario_id
    WHERE c.id = ?
      AND u.status = 1
    LIMIT 1
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $confeiteira_id
]);

$confeiteira = $stmt->fetch();


if (!$confeiteira) {

    header("Location: inicio.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR BOLOS
|--------------------------------------------------------------------------
|
| Aqui utilizamos os bolos cadastrados pela confeiteira.
|
*/

$sql = "
    SELECT
        id,
        nome,
        descricao,
        tamanho,
        rendimento,
        preco_venda,
        ativo
    FROM bolos
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY nome ASC
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $confeiteira_id
]);

$bolos = $stmt->fetchAll();


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
        <?= htmlspecialchars(
            $confeiteira["nome_confeitaria"]
        ) ?>
        - Catálogo
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
        | CABEÇALHO DA CONFEITARIA
        |--------------------------------------------------------------------------
        */

        .cabecalho-confeitaria {

            background:
                linear-gradient(
                    135deg,
                    #7b2cbf,
                    #9d4edd
                );

            color: white;

            border-radius: 18px;

            padding: 35px;

            margin-bottom: 30px;

            box-shadow:
                0 8px 25px
                rgba(
                    123,
                    44,
                    191,
                    0.18
                );
        }


        .cabecalho-confeitaria h1 {

            font-size: 30px;

            margin-bottom: 10px;
        }


        .cidade {

            font-size: 15px;

            opacity: 0.95;

            margin-bottom: 12px;
        }


        .descricao-confeitaria {

            max-width: 750px;

            line-height: 1.6;

            opacity: 0.95;
        }


        /*
        |--------------------------------------------------------------------------
        | TÍTULO
        |--------------------------------------------------------------------------
        */

        .titulo {

            display: flex;

            justify-content:
                space-between;

            align-items:
                center;

            margin-bottom: 20px;

            gap: 15px;
        }


        .titulo h2 {

            font-size: 24px;
        }


        .quantidade {

            color: #777;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | LISTA DE BOLOS
        |--------------------------------------------------------------------------
        */

        .lista-bolos {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fill,
                    minmax(
                        280px,
                        1fr
                    )
                );

            gap: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | CARD
        |--------------------------------------------------------------------------
        */

        .card {

            background: white;

            border-radius: 15px;

            overflow: hidden;

            box-shadow:
                0 4px 15px
                rgba(
                    0,
                    0,
                    0,
                    0.07
                );

            transition: 0.2s;

            display: flex;

            flex-direction: column;
        }


        .card:hover {

            transform:
                translateY(-3px);

            box-shadow:
                0 8px 22px
                rgba(
                    0,
                    0,
                    0,
                    0.11
                );
        }


        /*
        |--------------------------------------------------------------------------
        | ÁREA DA IMAGEM
        |--------------------------------------------------------------------------
        */

        .imagem-produto {

            height: 180px;

            background:
                linear-gradient(
                    135deg,
                    #f3e5f5,
                    #eadcf3
                );

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 60px;
        }


        /*
        |--------------------------------------------------------------------------
        | CONTEÚDO
        |--------------------------------------------------------------------------
        */

        .card-conteudo {

            padding: 22px;

            flex: 1;

            display: flex;

            flex-direction: column;
        }


        .card-conteudo h3 {

            font-size: 20px;

            margin-bottom: 8px;

            color: #333;
        }


        .descricao {

            color: #666;

            font-size: 14px;

            line-height: 1.5;

            margin-bottom: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | INFORMAÇÕES
        |--------------------------------------------------------------------------
        */

        .informacoes {

            display: flex;

            flex-wrap: wrap;

            gap: 8px;

            margin-bottom: 18px;
        }


        .info {

            background: #f3f3f3;

            color: #666;

            border-radius: 7px;

            padding:
                6px 9px;

            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | PREÇO
        |--------------------------------------------------------------------------
        */

        .preco {

            font-size: 24px;

            font-weight: bold;

            color: #7b2cbf;

            margin-top: auto;

            margin-bottom: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÃO
        |--------------------------------------------------------------------------
        */

        .btn-produto {

            display: block;

            width: 100%;

            text-align: center;

            text-decoration: none;

            background: #7b2cbf;

            color: white;

            padding: 13px;

            border-radius: 9px;

            font-weight: bold;

            transition: 0.2s;
        }


        .btn-produto:hover {

            background: #6420a0;
        }


        /*
        |--------------------------------------------------------------------------
        | SEM PRODUTOS
        |--------------------------------------------------------------------------
        */

        .sem-produtos {

            background: white;

            border-radius: 15px;

            padding:
                50px 25px;

            text-align: center;

            color: #777;

            box-shadow:
                0 3px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.05
                );
        }


        .sem-produtos .icone {

            font-size: 50px;

            margin-bottom: 15px;
        }


        .sem-produtos h3 {

            color: #444;

            margin-bottom: 8px;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 700px) {

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

                flex-wrap: wrap;
            }


            .container {

                padding:
                    20px 15px 40px;
            }


            .cabecalho-confeitaria {

                padding:
                    25px 20px;
            }


            .cabecalho-confeitaria h1 {

                font-size: 25px;
            }


            .titulo {

                flex-direction: column;

                align-items:
                    flex-start;
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
    | VOLTAR
    |--------------------------------------------------------------------------
    -->

    <a
        href="inicio.php"
        class="voltar"
    >
        ← Voltar para confeiteiras
    </a>



    <!--
    |--------------------------------------------------------------------------
    | CABEÇALHO DA CONFEITARIA
    |--------------------------------------------------------------------------
    -->

    <section class="cabecalho-confeitaria">

        <h1>

            <?= htmlspecialchars(
                $confeiteira["nome_confeitaria"]
            ) ?>

        </h1>


        <?php if (!empty($confeiteira["cidade"])): ?>

            <div class="cidade">

                📍

                <?= htmlspecialchars(
                    $confeiteira["cidade"]
                ) ?>

            </div>

        <?php endif; ?>


        <div class="descricao-confeitaria">

            <?php

            $descricaoConfeitaria =
                trim(
                    $confeiteira["descricao"] ?? ""
                );


            if ($descricaoConfeitaria === "") {

                echo "Confira nosso catálogo de produtos e faça seu pedido.";

            } else {

                echo nl2br(
                    htmlspecialchars(
                        $descricaoConfeitaria
                    )
                );

            }

            ?>

        </div>

    </section>



    <!--
    |--------------------------------------------------------------------------
    | CATÁLOGO
    |--------------------------------------------------------------------------
    -->

    <div class="titulo">

        <h2>
            🍰 Nosso catálogo
        </h2>


        <span class="quantidade">

            <?= count($bolos) ?>

            <?= count($bolos) === 1
                ? "produto disponível"
                : "produtos disponíveis"
            ?>

        </span>

    </div>



    <?php if (count($bolos) > 0): ?>


        <section class="lista-bolos">


            <?php foreach ($bolos as $bolo): ?>


                <article class="card">


                    <div class="imagem-produto">
                        🎂
                    </div>


                    <div class="card-conteudo">


                        <h3>

                            <?= htmlspecialchars(
                                $bolo["nome"]
                            ) ?>

                        </h3>



                        <?php if (!empty($bolo["descricao"])): ?>

                            <div class="descricao">

                                <?= nl2br(
                                    htmlspecialchars(
                                        $bolo["descricao"]
                                    )
                                ) ?>

                            </div>

                        <?php endif; ?>



                        <div class="informacoes">


                            <?php if (!empty($bolo["tamanho"])): ?>

                                <span class="info">

                                    📏
                                    <?= htmlspecialchars(
                                        $bolo["tamanho"]
                                    ) ?>

                                </span>

                            <?php endif; ?>



                            <?php if (!empty($bolo["rendimento"])): ?>

                                <span class="info">

                                    👥
                                    <?= htmlspecialchars(
                                        $bolo["rendimento"]
                                    ) ?>

                                </span>

                            <?php endif; ?>


                        </div>



                        <div class="preco">

                            R$

                            <?= number_format(
                                (float)$bolo["preco_venda"],
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>



                        <a
                            href="produto.php?confeiteira_id=<?= (int)$confeiteira_id ?>&bolo_id=<?= (int)$bolo["id"] ?>"
                            class="btn-produto"
                        >
                            Ver produto
                        </a>


                    </div>

                </article>


            <?php endforeach; ?>


        </section>


    <?php else: ?>


        <div class="sem-produtos">

            <div class="icone">
                🎂
            </div>


            <h3>
                Nenhum produto disponível
            </h3>


            <p>
                Essa confeiteira ainda não possui
                produtos disponíveis no catálogo.
            </p>

        </div>


    <?php endif; ?>


</main>


</body>

</html>