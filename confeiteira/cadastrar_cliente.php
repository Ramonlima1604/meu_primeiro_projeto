<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| TOKEN CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrf_token = $_SESSION["csrf_token"];


/*
|--------------------------------------------------------------------------
| USUÁRIO LOGADO
|--------------------------------------------------------------------------
*/

$usuarioId =
    $_SESSION["usuario_id"] ?? null;

if (!$usuarioId) {

    header(
        "Location: ../login.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $usuarioId
]);

$confeiteira =
    $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {

    die(
        "Confeiteira não encontrada."
    );
}

$confeiteiraId =
    (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$nome = "";
$telefone = "";
$email = "";
$endereco = "";
$numero = "";
$bairro = "";
$cidade = "";
$observacao = "";

$ativo = 1;

$erro = "";


/*
|--------------------------------------------------------------------------
| CADASTRAR CLIENTE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
) {


    /*
    |--------------------------------------------------------------------------
    | VALIDAR CSRF
    |--------------------------------------------------------------------------
    */

    if (
        !is_string(
            $_POST["csrf_token"] ?? null
        ) ||
        !hash_equals(
            $csrf_token,
            $_POST["csrf_token"]
        )
    ) {

        $erro =
            "A sessão de segurança expirou. Atualize a página e tente novamente.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | RECEBER DADOS
        |--------------------------------------------------------------------------
        */

        $nome =
            trim(
                $_POST["nome"] ?? ""
            );

        $telefone =
            trim(
                $_POST["telefone"] ?? ""
            );

        $email =
            trim(
                $_POST["email"] ?? ""
            );

        $endereco =
            trim(
                $_POST["endereco"] ?? ""
            );

        $numero =
            trim(
                $_POST["numero"] ?? ""
            );

        $bairro =
            trim(
                $_POST["bairro"] ?? ""
            );

        $cidade =
            trim(
                $_POST["cidade"] ?? ""
            );

        $observacao =
            trim(
                $_POST["observacao"] ?? ""
            );

        $ativo =
            isset(
                $_POST["ativo"]
            )
                ? 1
                : 0;


        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÕES
        |--------------------------------------------------------------------------
        */

        if ($nome === "") {

            $erro =
                "Informe o nome do cliente.";

        } elseif (
            mb_strlen($nome) > 150
        ) {

            $erro =
                "O nome do cliente deve ter no máximo 150 caracteres.";

        } elseif (
            $email !== "" &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $erro =
                "Informe um e-mail válido.";
        }


        /*
        |--------------------------------------------------------------------------
        | SALVAR
        |--------------------------------------------------------------------------
        */

        if ($erro === "") {

            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | CADASTRAR CLIENTE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("

                    INSERT INTO clientes (

                        confeiteira_id,

                        nome,

                        telefone,

                        email,

                        endereco,

                        numero,

                        bairro,

                        cidade,

                        observacao,

                        ativo

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

                ");


                $stmt->execute([

                    $confeiteiraId,

                    $nome,

                    $telefone !== ""
                        ? $telefone
                        : null,

                    $email !== ""
                        ? $email
                        : null,

                    $endereco !== ""
                        ? $endereco
                        : null,

                    $numero !== ""
                        ? $numero
                        : null,

                    $bairro !== ""
                        ? $bairro
                        : null,

                    $cidade !== ""
                        ? $cidade
                        : null,

                    $observacao !== ""
                        ? $observacao
                        : null,

                    $ativo

                ]);


                $clienteId =
                    (int) $pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | VINCULAR CLIENTE À CONFEITEIRA
                |--------------------------------------------------------------------------
                |
                | A tabela cliente_confeiteiras permite que o mesmo
                | cliente possa futuramente ser atendido por mais
                | de uma confeiteira.
                |
                */

                try {

                    $stmtVinculo =
                        $pdo->prepare("

                            INSERT INTO cliente_confeiteiras (

                                cliente_id,

                                confeiteira_id

                            )

                            VALUES (?, ?)

                        ");

                    $stmtVinculo->execute([

                        $clienteId,

                        $confeiteiraId

                    ]);

                } catch (
                    PDOException $e
                ) {

                    /*
                    | Se a tabela ou vínculo já estiver protegido
                    | por UNIQUE, não interrompemos o cadastro
                    | principal quando o vínculo já existir.
                    */

                    if (
                        $e->getCode() !== "23000"
                    ) {

                        throw $e;
                    }
                }


                $pdo->commit();


                /*
                |--------------------------------------------------------------------------
                | VOLTAR PARA CLIENTES
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: clientes.php?sucesso=1"
                );

                exit;


            } catch (
                Throwable $e
            ) {

                if (
                    $pdo->inTransaction()
                ) {

                    $pdo->rollBack();
                }


                $erro =
                    "Não foi possível cadastrar o cliente.";

                /*
                | Para desenvolvimento, caso precise descobrir
                | um erro específico do banco, podemos habilitar:
                |
                | $erro = $e->getMessage();
                |
                */
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
        Novo cliente -
        <?= htmlspecialchars(
            $confeiteira["nome_confeitaria"]
        ) ?>
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

            background: #f7f7f8;

            color: #222;
        }


        /*
        |--------------------------------------------------------------------------
        | TOPO
        |--------------------------------------------------------------------------
        */

        .topo {

            height: 72px;

            background: #ffffff;

            border-bottom:
                1px solid #e5e5e5;

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            padding:
                0 35px;

            position: sticky;

            top: 0;

            z-index: 100;
        }


        .logo {

            display: flex;

            align-items: center;

            gap: 12px;
        }


        .logo-icon {

            width: 42px;

            height: 42px;

            background: #8b3d5c;

            color: #ffffff;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 22px;
        }


        .logo-text strong {

            display: block;

            font-size: 18px;
        }


        .logo-text span {

            display: block;

            font-size: 12px;

            color: #888;
        }


        /*
        |--------------------------------------------------------------------------
        | USUÁRIO
        |--------------------------------------------------------------------------
        */

        .usuario {

            display: flex;

            align-items: center;

            gap: 12px;
        }


        .usuario-info {

            text-align: right;
        }


        .usuario-info strong {

            display: block;

            font-size: 14px;
        }


        .usuario-info span {

            font-size: 12px;

            color: #888;
        }


        .avatar {

            width: 40px;

            height: 40px;

            border-radius: 50%;

            background: #f1e4e9;

            color: #8b3d5c;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | LAYOUT
        |--------------------------------------------------------------------------
        */

        .layout {

            display: flex;

            min-height:
                calc(100vh - 72px);
        }


        /*
        |--------------------------------------------------------------------------
        | MENU LATERAL
        |--------------------------------------------------------------------------
        */

        .menu {

            width: 235px;

            background: #ffffff;

            border-right:
                1px solid #e5e5e5;

            padding:
                25px 15px;
        }


        .menu-titulo {

            font-size: 11px;

            font-weight: bold;

            color: #999;

            text-transform:
                uppercase;

            padding:
                0 12px;

            margin-bottom: 10px;
        }


        .menu a {

            display: flex;

            align-items: center;

            gap: 13px;

            padding: 12px;

            margin-bottom: 5px;

            border-radius: 10px;

            text-decoration: none;

            color: #555;

            font-size: 14px;
        }


        .menu a:hover {

            background: #f8eef2;

            color: #8b3d5c;
        }


        .menu a.ativo {

            background: #8b3d5c;

            color: #ffffff;
        }


        .menu-icone {

            width: 22px;

            text-align: center;

            font-size: 18px;
        }


        .menu-separador {

            height: 1px;

            background: #eeeeee;

            margin:
                20px 10px;
        }


        .menu a.sair {

            color: #c0392b;
        }


        /*
        |--------------------------------------------------------------------------
        | CONTEÚDO
        |--------------------------------------------------------------------------
        */

        .conteudo {

            flex: 1;

            padding: 35px;

            max-width: 1250px;
        }


        /*
        |--------------------------------------------------------------------------
        | CABEÇALHO DA PÁGINA
        |--------------------------------------------------------------------------
        */

        .cabecalho {

            display: flex;

            align-items: center;

            gap: 15px;

            margin-bottom: 25px;
        }


        .voltar {

            width: 40px;

            height: 40px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 10px;

            background: #ffffff;

            border:
                1px solid #ddd;

            text-decoration: none;

            color: #555;

            font-size: 20px;

            flex-shrink: 0;
        }


        .voltar:hover {

            background: #f5f5f5;
        }


        .cabecalho h1 {

            font-size: 27px;

            margin-bottom: 5px;
        }


        .cabecalho p {

            font-size: 13px;

            color: #888;
        }


        /*
        |--------------------------------------------------------------------------
        | ERRO
        |--------------------------------------------------------------------------
        */

        .erro {

            background: #fdeceb;

            color: #a93226;

            border:
                1px solid #f3c8c4;

            border-radius: 10px;

            padding:
                13px 15px;

            margin-bottom: 20px;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | CARD
        |--------------------------------------------------------------------------
        */

        .box {

            background: #ffffff;

            border:
                1px solid #e7e7e7;

            border-radius: 15px;

            padding: 25px;

            margin-bottom: 20px;
        }


        .box-titulo {

            margin-bottom: 20px;

            padding-bottom: 15px;

            border-bottom:
                1px solid #eeeeee;
        }


        .box-titulo h2 {

            font-size: 18px;

            margin-bottom: 5px;
        }


        .box-titulo p {

            color: #888;

            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | GRID DOS CAMPOS
        |--------------------------------------------------------------------------
        */

        .grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 20px;
        }


        .campo {

            display: flex;

            flex-direction: column;

            gap: 7px;
        }


        .campo-completo {

            grid-column:
                1 / -1;
        }


        label {

            font-size: 13px;

            font-weight: bold;

            color: #444;
        }


        input,
        textarea {

            width: 100%;

            border:
                1px solid #dcdcdc;

            border-radius: 9px;

            padding: 12px;

            font-family: inherit;

            font-size: 14px;

            outline: none;

            background: #ffffff;
        }


        input:focus,
        textarea:focus {

            border-color: #8b3d5c;

            box-shadow:
                0 0 0 3px
                rgba(
                    139,
                    61,
                    92,
                    0.08
                );
        }


        textarea {

            min-height: 120px;

            resize: vertical;
        }


        /*
        |--------------------------------------------------------------------------
        | AJUDA
        |--------------------------------------------------------------------------
        */

        .ajuda {

            font-size: 11px;

            color: #999;

            margin-top: 2px;
        }


        /*
        |--------------------------------------------------------------------------
        | CHECKBOX
        |--------------------------------------------------------------------------
        */

        .campo-checkbox {

            display: flex;

            align-items: center;

            gap: 10px;

            padding:
                12px 0;
        }


        .campo-checkbox input {

            width: auto;

            accent-color:
                #8b3d5c;
        }


        .campo-checkbox label {

            cursor: pointer;

            font-weight: normal;

            font-size: 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÕES
        |--------------------------------------------------------------------------
        */

        .acoes {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 10px;

            margin-top: 10px;
        }


        .acoes-direita {

            display: flex;

            gap: 10px;
        }


        .botao {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            padding:
                12px 20px;

            border-radius: 9px;

            text-decoration: none;

            font-size: 14px;

            font-weight: bold;

            cursor: pointer;

            border: none;

            min-height: 44px;
        }


        .botao-voltar {

            background: #ffffff;

            color: #555;

            border:
                1px solid #ddd;
        }


        .botao-voltar:hover {

            background: #f5f5f5;
        }


        .botao-salvar {

            background: #8b3d5c;

            color: #ffffff;
        }


        .botao-salvar:hover {

            background: #74324c;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVO
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .grid {

                grid-template-columns:
                    1fr;
            }

            .campo-completo {

                grid-column: auto;
            }

        }


        @media (max-width: 750px) {

            .topo {

                padding:
                    0 18px;
            }


            .usuario-info {

                display: none;
            }


            .menu {

                width: 70px;

                padding:
                    20px 10px;
            }


            .menu-titulo {

                display: none;
            }


            .menu a {

                justify-content:
                    center;

                padding:
                    13px 5px;
            }


            .menu a span:not(
                .menu-icone
            ) {

                display: none;
            }


            .menu-separador {

                margin:
                    15px 5px;
            }


            .conteudo {

                padding:
                    25px 18px;
            }

        }


        @media (max-width: 600px) {

            .box {

                padding:
                    18px;
            }


            .acoes {

                flex-direction:
                    column-reverse;

                align-items:
                    stretch;
            }


            .acoes-direita {

                display: block;
            }


            .botao {

                width: 100%;
            }

        }

    </style>

</head>


<body>


<!-- =====================================================
     TOPO
====================================================== -->

<header class="topo">


    <div class="logo">

        <div class="logo-icon">
            🍰
        </div>


        <div class="logo-text">

            <strong>

                <?= htmlspecialchars(
                    $confeiteira[
                        "nome_confeitaria"
                    ]
                ) ?>

            </strong>


            <span>
                Painel da confeiteira
            </span>

        </div>

    </div>


    <div class="usuario">


        <div class="usuario-info">

            <strong>

                <?= htmlspecialchars(
                    $_SESSION[
                        "usuario_nome"
                    ] ?? "Confeiteira"
                ) ?>

            </strong>


            <span>
                Área administrativa
            </span>

        </div>


        <div class="avatar">

            <?= strtoupper(
                substr(
                    $_SESSION[
                        "usuario_nome"
                    ] ?? "C",
                    0,
                    1
                )
            ) ?>

        </div>

    </div>


</header>



<div class="layout">


<!-- =====================================================
     MENU
====================================================== -->

<aside class="menu">


    <div class="menu-titulo">
        Menu
    </div>


    <a href="index.php">

        <span class="menu-icone">
            🏠
        </span>

        <span>
            Início
        </span>

    </a>


    <a href="produtos.php">

        <span class="menu-icone">
            🍰
        </span>

        <span>
            Produtos
        </span>

    </a>


    <a href="estoque.php">

        <span class="menu-icone">
            📦
        </span>

        <span>
            Estoque
        </span>

    </a>


    <a
        href="clientes.php"
        class="ativo"
    >

        <span class="menu-icone">
            👥
        </span>

        <span>
            Clientes
        </span>

    </a>


    <a href="pedidos.php">

        <span class="menu-icone">
            🛒
        </span>

        <span>
            Pedidos
        </span>

    </a>


    <a href="financeiro/index.php">

        <span class="menu-icone">
            💰
        </span>

        <span>
            Financeiro
        </span>

    </a>


    <div class="menu-separador"></div>


    <a href="minha_conta.php">

        <span class="menu-icone">
            ⚙️
        </span>

        <span>
            Minha conta
        </span>

    </a>


    <a
        href="../logout.php"
        class="sair"
    >

        <span class="menu-icone">
            🚪
        </span>

        <span>
            Sair
        </span>

    </a>


</aside>



<!-- =====================================================
     CONTEÚDO
====================================================== -->

<main class="conteudo">


    <!-- CABEÇALHO -->

    <div class="cabecalho">


        <a
            href="clientes.php"
            class="voltar"
            title="Voltar para clientes"
        >
            ←
        </a>


        <div>

            <h1>
                Novo cliente
            </h1>

            <p>
                Cadastre as informações do seu cliente.
            </p>

        </div>


    </div>



    <!-- ERRO -->

    <?php if ($erro !== ""): ?>

        <div class="erro">

            <?= htmlspecialchars(
                $erro
            ) ?>

        </div>

    <?php endif; ?>



    <form
        method="POST"
        autocomplete="off"
    >


        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $csrf_token
            ) ?>"
        >



        <!-- =================================================
             DADOS DO CLIENTE
        ================================================== -->

        <div class="box">


            <div class="box-titulo">

                <h2>
                    👤 Dados do cliente
                </h2>

                <p>
                    Informe os principais dados para identificação e contato.
                </p>

            </div>



            <div class="grid">


                <!-- NOME -->

                <div class="campo campo-completo">

                    <label for="nome">

                        Nome do cliente *

                    </label>


                    <input
                        type="text"
                        name="nome"
                        id="nome"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $nome
                        ) ?>"
                        placeholder="Ex.: João da Silva"
                        required
                    >

                </div>



                <!-- TELEFONE -->

                <div class="campo">

                    <label for="telefone">

                        Telefone

                    </label>


                    <input
                        type="text"
                        name="telefone"
                        id="telefone"
                        maxlength="15"
                        value="<?= htmlspecialchars(
                            $telefone
                        ) ?>"
                        placeholder="(00) 00000-0000"
                        inputmode="tel"
                    >


                    <div class="ajuda">

                        Telefone ou WhatsApp para contato.

                    </div>

                </div>



                <!-- E-MAIL -->

                <div class="campo">

                    <label for="email">

                        E-mail

                    </label>


                    <input
                        type="email"
                        name="email"
                        id="email"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $email
                        ) ?>"
                        placeholder="cliente@email.com"
                    >

                </div>



            </div>

        </div>



        <!-- =================================================
             ENDEREÇO
        ================================================== -->

        <div class="box">


            <div class="box-titulo">

                <h2>
                    📍 Endereço
                </h2>

                <p>
                    Essas informações podem ser utilizadas para entrega do pedido.
                </p>

            </div>



            <div class="grid">


                <!-- ENDEREÇO -->

                <div class="campo campo-completo">

                    <label for="endereco">

                        Endereço

                    </label>


                    <input
                        type="text"
                        name="endereco"
                        id="endereco"
                        maxlength="200"
                        value="<?= htmlspecialchars(
                            $endereco
                        ) ?>"
                        placeholder="Ex.: Rua das Flores"
                    >

                </div>



                <!-- NÚMERO -->

                <div class="campo">

                    <label for="numero">

                        Número

                    </label>


                    <input
                        type="text"
                        name="numero"
                        id="numero"
                        maxlength="20"
                        value="<?= htmlspecialchars(
                            $numero
                        ) ?>"
                        placeholder="Ex.: 123"
                    >

                </div>



                <!-- BAIRRO -->

                <div class="campo">

                    <label for="bairro">

                        Bairro

                    </label>


                    <input
                        type="text"
                        name="bairro"
                        id="bairro"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $bairro
                        ) ?>"
                        placeholder="Ex.: Centro"
                    >

                </div>



                <!-- CIDADE -->

                <div class="campo">

                    <label for="cidade">

                        Cidade

                    </label>


                    <input
                        type="text"
                        name="cidade"
                        id="cidade"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $cidade
                        ) ?>"
                        placeholder="Ex.: Araras"
                    >

                </div>


            </div>

        </div>



        <!-- =================================================
             OBSERVAÇÕES
        ================================================== -->

        <div class="box">


            <div class="box-titulo">

                <h2>
                    📝 Observações
                </h2>

                <p>
                    Adicione informações importantes sobre o cliente.
                </p>

            </div>


            <div class="campo">


                <label for="observacao">

                    Observações

                </label>


                <textarea
                    name="observacao"
                    id="observacao"
                    placeholder="Ex.: Cliente prefere contato pelo WhatsApp, possui restrição a determinado ingrediente, endereço de entrega, preferência de horário..."
                ><?= htmlspecialchars(
                    $observacao
                ) ?></textarea>


            </div>


        </div>



        <!-- =================================================
             STATUS
        ================================================== -->

        <div class="box">


            <div class="box-titulo">

                <h2>
                    ⚙️ Status do cliente
                </h2>

                <p>
                    Clientes inativos não aparecem normalmente nas listas de pedidos.
                </p>

            </div>


            <div class="campo-checkbox">


                <input
                    type="checkbox"
                    name="ativo"
                    id="ativo"
                    value="1"
                    <?= $ativo
                        ? "checked"
                        : "" ?>
                >


                <label for="ativo">

                    Cliente ativo

                </label>


            </div>


        </div>



        <!-- =================================================
             BOTÕES
        ================================================== -->

        <div class="acoes">


            <a
                href="clientes.php"
                class="botao botao-voltar"
            >

                ← Cancelar

            </a>


            <div class="acoes-direita">


                <button
                    type="submit"
                    class="botao botao-salvar"
                >

                    💾 Salvar cliente

                </button>


            </div>


        </div>


    </form>


</main>


</div>



<script>

/*
|--------------------------------------------------------------------------
| MÁSCARA DE TELEFONE
|--------------------------------------------------------------------------
*/

const telefone =
    document.getElementById(
        "telefone"
    );


if (telefone) {

    telefone.addEventListener(
        "input",
        function () {

            let valor =
                this.value.replace(
                    /\D/g,
                    ""
                );


            if (
                valor.length > 11
            ) {

                valor =
                    valor.substring(
                        0,
                        11
                    );
            }


            if (
                valor.length <= 10
            ) {

                valor =
                    valor.replace(
                        /^(\d{2})(\d)/,
                        "($1) $2"
                    );

                valor =
                    valor.replace(
                        /(\d{4})(\d)/,
                        "$1-$2"
                    );

            } else {

                valor =
                    valor.replace(
                        /^(\d{2})(\d)/,
                        "($1) $2"
                    );

                valor =
                    valor.replace(
                        /(\d{5})(\d)/,
                        "$1-$2"
                    );
            }


            this.value =
                valor;
        }
    );
}


/*
|--------------------------------------------------------------------------
| FOCO INICIAL
|--------------------------------------------------------------------------
*/

const campoNome =
    document.getElementById(
        "nome"
    );


if (campoNome) {

    campoNome.focus();
}

</script>


</body>

</html>