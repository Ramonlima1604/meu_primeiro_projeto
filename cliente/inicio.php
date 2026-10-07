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
| DADOS DO CLIENTE
|--------------------------------------------------------------------------
*/

$nomeCliente = $_SESSION["cliente_nome"] ?? $_SESSION["usuario_nome"] ?? "Cliente";


/*
|--------------------------------------------------------------------------
| BUSCA
|--------------------------------------------------------------------------
*/

$busca = trim($_GET["busca"] ?? "");


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRAS
|--------------------------------------------------------------------------
*/

if ($busca !== "") {

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
        WHERE u.status = 1
          AND (
                c.nome_confeitaria LIKE ?
                OR c.cidade LIKE ?
                OR c.descricao LIKE ?
              )
        ORDER BY c.nome_confeitaria ASC
    ";

    $termo = "%" . $busca . "%";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $termo,
        $termo,
        $termo
    ]);

} else {

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
        WHERE u.status = 1
        ORDER BY c.nome_confeitaria ASC
    ";

    $stmt = $pdo->query($sql);
}

$confeiteiras = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Início - Sistema de Bolos</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        /*
        |--------------------------------------------------------------------------
        | TOPO
        |--------------------------------------------------------------------------
        */

        .topo {
            background: #ffffff;
            border-bottom: 1px solid #e5e5e5;
            padding: 18px 30px;
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
            padding: 10px 14px;
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
        | CONTEÚDO
        |--------------------------------------------------------------------------
        */

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 35px 20px 50px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOAS-VINDAS
        |--------------------------------------------------------------------------
        */

        .boas-vindas {
            background: linear-gradient(
                135deg,
                #7b2cbf,
                #9d4edd
            );

            color: white;

            border-radius: 18px;

            padding: 35px;

            margin-bottom: 30px;

            box-shadow: 0 8px 25px rgba(123, 44, 191, 0.18);
        }

        .boas-vindas h1 {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .boas-vindas p {
            font-size: 16px;
            opacity: 0.95;
        }


        /*
        |--------------------------------------------------------------------------
        | BUSCA
        |--------------------------------------------------------------------------
        */

        .area-busca {
            background: white;

            border-radius: 14px;

            padding: 22px;

            margin-bottom: 30px;

            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .area-busca h2 {
            font-size: 20px;
            margin-bottom: 15px;
        }

        .form-busca {
            display: flex;
            gap: 10px;
        }

        .form-busca input {
            flex: 1;

            padding: 14px 16px;

            border: 1px solid #ddd;

            border-radius: 9px;

            font-size: 15px;

            outline: none;
        }

        .form-busca input:focus {
            border-color: #7b2cbf;
        }

        .btn-buscar {
            border: none;

            background: #7b2cbf;

            color: white;

            padding: 0 25px;

            border-radius: 9px;

            font-size: 15px;

            cursor: pointer;

            font-weight: bold;
        }

        .btn-buscar:hover {
            background: #6420a0;
        }


        /*
        |--------------------------------------------------------------------------
        | TÍTULO
        |--------------------------------------------------------------------------
        */

        .titulo-lista {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .titulo-lista h2 {
            font-size: 23px;
        }

        .resultado {
            color: #777;
            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | CARDS
        |--------------------------------------------------------------------------
        */

        .lista-confeiteiras {
            display: grid;

            grid-template-columns: repeat(
                auto-fill,
                minmax(280px, 1fr)
            );

            gap: 20px;
        }

        .card {
            background: white;

            border-radius: 15px;

            overflow: hidden;

            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.07);

            transition: 0.2s;

            display: flex;

            flex-direction: column;
        }

        .card:hover {
            transform: translateY(-3px);

            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.11);
        }

        .card-topo {
            background: linear-gradient(
                135deg,
                #f3e5f5,
                #eadcf3
            );

            height: 100px;

            display: flex;

            align-items: center;

            justify-content: center;
        }

        .icone {
            width: 65px;

            height: 65px;

            border-radius: 50%;

            background: #7b2cbf;

            color: white;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 28px;

            font-weight: bold;
        }

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

        .cidade {
            color: #777;

            font-size: 14px;

            margin-bottom: 14px;
        }

        .descricao {
            color: #666;

            font-size: 14px;

            line-height: 1.5;

            margin-bottom: 20px;

            flex: 1;
        }

        .btn-catalogo {
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

        .btn-catalogo:hover {
            background: #6420a0;
        }


        /*
        |--------------------------------------------------------------------------
        | SEM RESULTADOS
        |--------------------------------------------------------------------------
        */

        .sem-resultados {
            background: white;

            border-radius: 15px;

            padding: 45px 25px;

            text-align: center;

            color: #777;

            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        .sem-resultados .icone-vazio {
            font-size: 45px;

            margin-bottom: 15px;
        }

        .sem-resultados h3 {
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
                padding: 20px 15px 40px;
            }

            .boas-vindas {
                padding: 25px 20px;
            }

            .boas-vindas h1 {
                font-size: 25px;
            }

            .form-busca {
                flex-direction: column;
            }

            .btn-buscar {
                padding: 13px;
            }

            .titulo-lista {
                flex-direction: column;

                align-items: flex-start;

                gap: 5px;
            }

        }

    </style>

</head>

<body>


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

        <a href="logout.php" class="sair">
            Sair
        </a>

    </nav>

</header>


<main class="container">


    <!-- BOAS-VINDAS -->

    <section class="boas-vindas">

        <h1>
            Olá, <?= htmlspecialchars($nomeCliente) ?>! 👋
        </h1>

        <p>
            Encontre uma confeiteira, escolha seus produtos
            e faça seu pedido de forma rápida e fácil.
        </p>

    </section>


    <!-- BUSCA -->

    <section class="area-busca">

        <h2>
            🔎 Encontrar uma confeiteira
        </h2>

        <form
            method="GET"
            action="inicio.php"
            class="form-busca"
        >

            <input
                type="text"
                name="busca"
                value="<?= htmlspecialchars($busca) ?>"
                placeholder="Digite o nome da confeitaria ou cidade..."
            >

            <button
                type="submit"
                class="btn-buscar"
            >
                Buscar
            </button>

        </form>

    </section>


    <!-- LISTA -->

    <div class="titulo-lista">

        <h2>
            <?= $busca !== ""
                ? "Resultados da busca"
                : "Confeitarias disponíveis"
            ?>
        </h2>

        <span class="resultado">
            <?= count($confeiteiras) ?>
            <?= count($confeiteiras) === 1
                ? "confeitaria encontrada"
                : "confeitarias encontradas"
            ?>
        </span>

    </div>


    <?php if (count($confeiteiras) > 0): ?>

        <section class="lista-confeiteiras">

            <?php foreach ($confeiteiras as $confeiteira): ?>

                <?php

                $nomeConfeitaria =
                    $confeiteira["nome_confeitaria"];

                $inicial =
                    strtoupper(
                        mb_substr(
                            $nomeConfeitaria,
                            0,
                            1,
                            "UTF-8"
                        )
                    );

                ?>

                <article class="card">

                    <div class="card-topo">

                        <div class="icone">
                            <?= htmlspecialchars($inicial) ?>
                        </div>

                    </div>


                    <div class="card-conteudo">

                        <h3>
                            <?= htmlspecialchars(
                                $nomeConfeitaria
                            ) ?>
                        </h3>


                        <?php if (!empty($confeiteira["cidade"])): ?>

                            <div class="cidade">
                                📍
                                <?= htmlspecialchars(
                                    $confeiteira["cidade"]
                                ) ?>
                            </div>

                        <?php endif; ?>


                        <div class="descricao">

                            <?php

                            $descricao =
                                trim(
                                    $confeiteira["descricao"] ?? ""
                                );

                            if ($descricao === "") {

                                echo "Confira nosso catálogo de produtos e faça seu pedido.";

                            } else {

                                echo htmlspecialchars(
                                    mb_strimwidth(
                                        $descricao,
                                        0,
                                        150,
                                        "..."
                                    )
                                );

                            }

                            ?>

                        </div>


                        <a
                            href="catalogo.php?confeiteira_id=<?= (int)$confeiteira["id"] ?>"
                            class="btn-catalogo"
                        >
                            Ver catálogo
                        </a>

                    </div>

                </article>

            <?php endforeach; ?>

        </section>

    <?php else: ?>

        <div class="sem-resultados">

            <div class="icone-vazio">
                🔍
            </div>

            <h3>
                Nenhuma confeitaria encontrada
            </h3>

            <p>
                Tente pesquisar por outro nome ou cidade.
            </p>

        </div>

    <?php endif; ?>


</main>

</body>

</html>