<?php

/*
|--------------------------------------------------------------------------
| SESSÃO
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| BANCO DE DADOS
|--------------------------------------------------------------------------
*/

require_once "config/banco.php";


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$erro = "";

$email = "";


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /*
    |--------------------------------------------------------------------------
    | DADOS
    |--------------------------------------------------------------------------
    */

    $email = trim(
        $_POST["email"] ?? ""
    );

    $senha = $_POST["senha"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÃO
    |--------------------------------------------------------------------------
    */

    if (
        $email === "" ||
        $senha === ""
    ) {

        $erro =
            "Preencha o e-mail e a senha.";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $erro =
            "Informe um e-mail válido.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | BUSCAR USUÁRIO ATIVO
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("

            SELECT
                id,
                nome,
                email,
                senha,
                tipo,
                status

            FROM usuarios

            WHERE email = ?

              AND status = 1

            LIMIT 1

        ");

        $stmt->execute([
            $email
        ]);

        $usuario =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        /*
        |--------------------------------------------------------------------------
        | USUÁRIO NÃO ENCONTRADO
        |--------------------------------------------------------------------------
        */

        if (!$usuario) {


            /*
            |--------------------------------------------------------------------------
            | VERIFICAR SE A CONTA EXISTE, MAS ESTÁ DESATIVADA
            |--------------------------------------------------------------------------
            */

            $stmtStatus = $pdo->prepare("

                SELECT
                    id,
                    status

                FROM usuarios

                WHERE email = ?

                LIMIT 1

            ");

            $stmtStatus->execute([
                $email
            ]);

            $usuarioStatus =
                $stmtStatus->fetch(
                    PDO::FETCH_ASSOC
                );


            if (
                $usuarioStatus &&
                (int)$usuarioStatus["status"] === 0
            ) {

                $erro =
                    "Esta conta está desativada e não pode acessar o sistema.";

            } else {

                $erro =
                    "E-mail ou senha inválidos.";
            }


        } else {


            /*
            |--------------------------------------------------------------------------
            | VERIFICAR SENHA
            |--------------------------------------------------------------------------
            */

            if (
                !password_verify(
                    $senha,
                    $usuario["senha"]
                )
            ) {

                $erro =
                    "E-mail ou senha inválidos.";

            } else {


                /*
                |--------------------------------------------------------------------------
                | VALIDAR TIPO
                |--------------------------------------------------------------------------
                |
                | Este login é destinado à área administrativa/confeiteira.
                |
                */

                if (
                    $usuario["tipo"] !== "confeiteira" &&
                    $usuario["tipo"] !== "admin"
                ) {

                    $erro =
                        "Esta conta não possui acesso à área de confeiteira.";

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | REGENERAR SESSÃO
                    |--------------------------------------------------------------------------
                    */

                    session_regenerate_id(true);


                    /*
                    |--------------------------------------------------------------------------
                    | LIMPAR SESSÃO ANTERIOR
                    |--------------------------------------------------------------------------
                    */

                    $_SESSION = [];


                    /*
                    |--------------------------------------------------------------------------
                    | GRAVAR NOVA SESSÃO
                    |--------------------------------------------------------------------------
                    */

                    $_SESSION["usuario_id"] =
                        (int)$usuario["id"];

                    $_SESSION["usuario_nome"] =
                        $usuario["nome"];

                    $_SESSION["nome"] =
                        $usuario["nome"];

                    $_SESSION["tipo"] =
                        $usuario["tipo"];

                    /*
                    | Compatibilidade com páginas antigas
                    */

                    $_SESSION["usuario_tipo"] =
                        $usuario["tipo"];


                    /*
                    |--------------------------------------------------------------------------
                    | REDIRECIONAMENTO
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $usuario["tipo"] === "admin"
                    ) {

                        header(
                            "Location: admin/index.php"
                        );

                        exit;
                    }


                    if (
                        $usuario["tipo"] === "confeiteira"
                    ) {

                        header(
                            "Location: confeiteira/index.php"
                        );

                        exit;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | SEGURANÇA
                    |--------------------------------------------------------------------------
                    */

                    header(
                        "Location: index.php"
                    );

                    exit;
                }
            }
        }
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
        Entrar - Sistema de Bolos
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #fff7ed,
                    #fef3c7
                );

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;

        }


        .container {

            width: 100%;

            max-width: 430px;

        }


        .card {

            background: #ffffff;

            border-radius: 18px;

            padding: 35px;

            box-shadow:
                0 12px 35px
                rgba(
                    0,
                    0,
                    0,
                    0.10
                );

        }


        /*
        |--------------------------------------------------------------------------
        | LOGO
        |--------------------------------------------------------------------------
        */

        .logo {

            text-align: center;

            font-size: 48px;

            margin-bottom: 5px;

        }


        h1 {

            margin: 0;

            text-align: center;

            color: #333333;

            font-size: 28px;

        }


        .subtitulo {

            text-align: center;

            color: #777777;

            margin-top: 8px;

            margin-bottom: 28px;

            font-size: 14px;

            line-height: 1.5;

        }


        /*
        |--------------------------------------------------------------------------
        | MENSAGEM DE ERRO
        |--------------------------------------------------------------------------
        */

        .mensagem {

            padding:
                13px 15px;

            border-radius: 10px;

            margin-bottom: 20px;

            font-size: 14px;

            line-height: 1.4;

        }


        .erro {

            background: #fee2e2;

            color: #991b1b;

            border:
                1px solid
                #fecaca;

        }


        /*
        |--------------------------------------------------------------------------
        | CAMPOS
        |--------------------------------------------------------------------------
        */

        .campo {

            margin-bottom: 18px;

        }


        label {

            display: block;

            margin-bottom: 7px;

            color: #333333;

            font-weight: bold;

            font-size: 14px;

        }


        input {

            width: 100%;

            padding:
                13px 14px;

            border:
                1px solid
                #d1d5db;

            border-radius: 10px;

            font-size: 15px;

            outline: none;

            transition: 0.2s;

        }


        input:focus {

            border-color: #f59e0b;

            box-shadow:
                0 0 0 3px
                rgba(
                    245,
                    158,
                    11,
                    0.12
                );

        }


        /*
        |--------------------------------------------------------------------------
        | BOTÃO ENTRAR
        |--------------------------------------------------------------------------
        */

        .botao {

            width: 100%;

            border: none;

            border-radius: 10px;

            padding: 14px;

            background: #f59e0b;

            color: #ffffff;

            font-size: 16px;

            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;

        }


        .botao:hover {

            background: #d97706;

        }


        /*
        |--------------------------------------------------------------------------
        | SEPARADOR
        |--------------------------------------------------------------------------
        */

        .separador {

            margin:
                25px 0;

            border: 0;

            border-top:
                1px solid
                #eeeeee;

        }


        /*
        |--------------------------------------------------------------------------
        | LINKS
        |--------------------------------------------------------------------------
        */

        .links {

            text-align: center;

            margin-top: 18px;

            font-size: 14px;

            color: #666666;

            line-height: 1.8;

        }


        .links a {

            color: #d97706;

            text-decoration: none;

            font-weight: bold;

        }


        .links a:hover {

            text-decoration: underline;

        }


        /*
        |--------------------------------------------------------------------------
        | BOTÃO VOLTAR
        |--------------------------------------------------------------------------
        */

        .voltar {

            display: block;

            width: 100%;

            margin-top: 12px;

            padding: 13px;

            background: #f5f5f5;

            color: #444444;

            border-radius: 10px;

            text-align: center;

            text-decoration: none;

            font-size: 14px;

            font-weight: bold;

            transition: 0.2s;

        }


        .voltar:hover {

            background: #e5e5e5;

        }


        /*
        |--------------------------------------------------------------------------
        | RODAPÉ
        |--------------------------------------------------------------------------
        */

        .rodape {

            text-align: center;

            margin-top: 22px;

            color: #888888;

            font-size: 12px;

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 500px) {

            body {

                padding: 15px;

            }


            .card {

                padding:
                    28px 20px;

            }


            h1 {

                font-size: 24px;

            }

        }

    </style>

</head>


<body>


<div class="container">


    <div class="card">


        <!-- LOGO -->

        <div class="logo">
            🎂
        </div>


        <!-- TÍTULO -->

        <h1>
            Entrar
        </h1>


        <div class="subtitulo">

            Entre na sua conta para acessar
            a área da confeiteira.

        </div>


        <!-- ERRO -->

        <?php if ($erro !== ""): ?>

            <div class="mensagem erro">

                <?= htmlspecialchars(
                    $erro,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- FORMULÁRIO -->

        <form
            method="POST"
            action=""
            autocomplete="on"
        >


            <!-- E-MAIL -->

            <div class="campo">

                <label for="email">
                    E-mail
                </label>


                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    autocomplete="email"
                    value="<?= htmlspecialchars(
                        $email,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    required
                    autofocus
                >

            </div>


            <!-- SENHA -->

            <div class="campo">

                <label for="senha">
                    Senha
                </label>


                <input
                    type="password"
                    id="senha"
                    name="senha"
                    autocomplete="current-password"
                    required
                >

            </div>


            <!-- ENTRAR -->

            <button
                type="submit"
                class="botao"
            >

                Entrar

            </button>


        </form>


        <!-- VOLTAR -->

        <a
            href="index.php"
            class="voltar"
        >

            ← Voltar para a tela inicial

        </a>


        <!-- RODAPÉ -->

        <div class="rodape">

            Sistema de gerenciamento de confeitaria

        </div>


    </div>


</div>


</body>

</html>