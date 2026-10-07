<?php

session_start();

/*
|--------------------------------------------------------------------------
| MENSAGEM DE CONTA EXCLUÍDA
|--------------------------------------------------------------------------
*/

$conta_excluida = (
    isset($_GET['conta_excluida']) &&
    $_GET['conta_excluida'] === '1'
);

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
        Sistema de Bolos
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #f8f9fa,
                    #eeeeee
                );

            color: #222;

            min-height: 100vh;
        }

        .container {
            width: 100%;

            max-width: 1100px;

            margin: 0 auto;

            padding: 30px 20px 50px;
        }

        /*
        |--------------------------------------------------------------------------
        | TOPO
        |--------------------------------------------------------------------------
        */

        .topo {
            text-align: center;

            padding: 30px 20px 20px;
        }

        .logo {
            font-size: 55px;

            margin-bottom: 10px;
        }

        .topo h1 {
            margin: 0;

            font-size: 38px;
        }

        .topo p {
            color: #666;

            font-size: 18px;

            margin-top: 10px;
        }

        /*
        |--------------------------------------------------------------------------
        | MENSAGEM DE SUCESSO
        |--------------------------------------------------------------------------
        */

        .mensagem-sucesso {
            max-width: 700px;

            margin: 10px auto 30px;

            padding: 16px 20px;

            background: #e8f7ed;

            border: 1px solid #b7dfc2;

            color: #176b2c;

            border-radius: 10px;

            text-align: center;

            line-height: 1.5;

            font-weight: 500;
        }

        /*
        |--------------------------------------------------------------------------
        | ESCOLHA
        |--------------------------------------------------------------------------
        */

        .titulo-secao {
            text-align: center;

            margin-top: 20px;

            margin-bottom: 25px;
        }

        .titulo-secao h2 {
            margin-bottom: 8px;
        }

        .titulo-secao p {
            margin: 0;

            color: #777;
        }

        /*
        |--------------------------------------------------------------------------
        | CARDS
        |--------------------------------------------------------------------------
        */

        .cards {
            display: grid;

            grid-template-columns:
                repeat(
                    2,
                    minmax(280px, 1fr)
                );

            gap: 25px;

            max-width: 850px;

            margin: 0 auto;
        }

        .card {
            background: #fff;

            border-radius: 16px;

            padding: 30px;

            box-shadow:
                0 5px 25px
                rgba(0, 0, 0, 0.08);

            text-align: center;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-3px);

            box-shadow:
                0 8px 30px
                rgba(0, 0, 0, 0.12);
        }

        .card-icone {
            font-size: 55px;

            margin-bottom: 15px;
        }

        .card h3 {
            margin: 10px 0;

            font-size: 24px;
        }

        .card p {
            color: #666;

            line-height: 1.5;

            min-height: 48px;
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

            margin-top: 25px;
        }

        .btn {
            display: block;

            width: 100%;

            padding: 14px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

            text-align: center;

            transition:
                background 0.2s ease;
        }

        .btn-principal {
            background: #222;

            color: #fff;
        }

        .btn-principal:hover {
            background: #000;
        }

        .btn-secundario {
            background: #f0f0f0;

            color: #333;
        }

        .btn-secundario:hover {
            background: #e0e0e0;
        }

        /*
        |--------------------------------------------------------------------------
        | CADASTRO
        |--------------------------------------------------------------------------
        */

        .cadastro {
            max-width: 850px;

            margin: 30px auto 0;

            background: #fff;

            border-radius: 16px;

            padding: 30px;

            text-align: center;

            box-shadow:
                0 5px 25px
                rgba(0, 0, 0, 0.08);
        }

        .cadastro h2 {
            margin-top: 0;
        }

        .cadastro p {
            color: #666;

            line-height: 1.5;
        }

        .cadastro-botoes {
            display: grid;

            grid-template-columns:
                repeat(
                    2,
                    1fr
                );

            gap: 12px;

            margin-top: 20px;
        }

        /*
        |--------------------------------------------------------------------------
        | RODAPÉ
        |--------------------------------------------------------------------------
        */

        .rodape {
            text-align: center;

            margin-top: 40px;

            color: #888;

            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 700px) {

            .cards {
                grid-template-columns: 1fr;
            }

            .cadastro-botoes {
                grid-template-columns: 1fr;
            }

            .topo h1 {
                font-size: 30px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- =====================================================
         TOPO
    ====================================================== -->

    <header class="topo">

        <div class="logo">
            🍰
        </div>

        <h1>
            Sistema de Bolos
        </h1>

        <p>
            Gestão de confeitaria, pedidos e clientes
        </p>

    </header>


    <!-- =====================================================
         MENSAGEM DE EXCLUSÃO
    ====================================================== -->

    <?php if ($conta_excluida): ?>

        <div class="mensagem-sucesso">

            <strong>
                Sua conta foi desativada com sucesso.
            </strong>

            <br>

            Você voltou para a tela inicial do sistema.

        </div>

    <?php endif; ?>


    <!-- =====================================================
         TÍTULO
    ====================================================== -->

    <section class="titulo-secao">

        <h2>
            Como você deseja acessar?
        </h2>

        <p>
            Escolha uma das opções abaixo.
        </p>

    </section>


    <!-- =====================================================
         CARDS
    ====================================================== -->

    <div class="cards">


        <!-- =================================================
             CLIENTE
        ================================================== -->

        <div class="card">

            <div class="card-icone">
                👤
            </div>

            <h3>
                Cliente
            </h3>

            <p>
                Acesse sua conta para consultar
                pedidos, informações e acompanhar
                suas compras.
            </p>

            <div class="acoes">

                <a
                    href="cliente/login.php"
                    class="btn btn-principal"
                >
                    Entrar como cliente
                </a>

            </div>

        </div>


        <!-- =================================================
             CONFEITEIRA
        ================================================== -->

        <div class="card">

            <div class="card-icone">
                👩‍🍳
            </div>

            <h3>
                Confeiteira
            </h3>

            <p>
                Acesse o painel para gerenciar
                produtos, pedidos, estoque,
                clientes e financeiro.
            </p>

            <div class="acoes">

                <a
                    href="login.php"
                    class="btn btn-principal"
                >
                    Entrar como confeiteira
                </a>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CADASTRO
    ====================================================== -->

    <section class="cadastro">

        <h2>
            Ainda não possui uma conta?
        </h2>

        <p>
            Escolha o tipo de cadastro que deseja realizar.
        </p>

        <div class="cadastro-botoes">

            <!--
            ==================================================
            CADASTRO DA CONFEITEIRA
            ==================================================
            -->

            <a
                href="cadastro_confeiteira.php"
                class="btn btn-secundario"
            >
                Criar conta de confeiteira
            </a>


            <!--
            ==================================================
            CADASTRO DO CLIENTE
            ==================================================
            -->

            <a
                href="cliente/cadastro.php"
                class="btn btn-secundario"
            >
                Criar conta de cliente
            </a>

        </div>

    </section>


    <!-- =====================================================
         RODAPÉ
    ====================================================== -->

    <footer class="rodape">

        Sistema de gerenciamento de confeitaria

    </footer>

</div>

</body>

</html>