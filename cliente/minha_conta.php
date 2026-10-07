<?php

session_start();

require_once "../config/banco.php";


/*
|--------------------------------------------------------------------------
| VERIFICA LOGIN
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

$clienteId = (int) $_SESSION["cliente_id"];

$mensagem = "";
$erro = "";


/*
|--------------------------------------------------------------------------
| BUSCA DADOS DO CLIENTE
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        nome,
        telefone,
        email,
        endereco,
        numero,
        bairro,
        cidade,
        observacao,
        ativo
    FROM clientes
    WHERE id = ?
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$clienteId]);

$cliente = $stmt->fetch();


if (!$cliente) {

    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;

}


/*
|--------------------------------------------------------------------------
| ATUALIZA DADOS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"] ?? "");
    $telefone = trim($_POST["telefone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $endereco = trim($_POST["endereco"] ?? "");
    $numero = trim($_POST["numero"] ?? "");
    $bairro = trim($_POST["bairro"] ?? "");
    $cidade = trim($_POST["cidade"] ?? "");
    $observacao = trim($_POST["observacao"] ?? "");


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erro = "Informe seu nome.";

    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $erro = "Informe um e-mail válido.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | VERIFICA SE O E-MAIL JÁ ESTÁ SENDO USADO
        |--------------------------------------------------------------------------
        */

        if ($email !== "") {

            $sqlEmail = "
                SELECT id
                FROM usuarios
                WHERE email = ?
                  AND id <> ?
                LIMIT 1
            ";

            $stmtEmail = $pdo->prepare($sqlEmail);

            $stmtEmail->execute([
                $email,
                $_SESSION["usuario_id"]
            ]);

            $usuarioExistente = $stmtEmail->fetch();

            if ($usuarioExistente) {

                $erro = "Este e-mail já está sendo utilizado por outra conta.";

            }

        }


        /*
        |--------------------------------------------------------------------------
        | SALVA
        |--------------------------------------------------------------------------
        */

        if ($erro === "") {

            try {

                $pdo->beginTransaction();


                /*
                --------------------------------------------------------------
                ATUALIZA CLIENTE
                --------------------------------------------------------------
                */

                $sqlUpdateCliente = "
                    UPDATE clientes
                    SET
                        nome = ?,
                        telefone = ?,
                        email = ?,
                        endereco = ?,
                        numero = ?,
                        bairro = ?,
                        cidade = ?,
                        observacao = ?
                    WHERE id = ?
                ";

                $stmtUpdateCliente = $pdo->prepare(
                    $sqlUpdateCliente
                );

                $stmtUpdateCliente->execute([

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

                    $clienteId

                ]);


                /*
                --------------------------------------------------------------
                ATUALIZA USUÁRIO
                --------------------------------------------------------------
                |
                | Como usuarios.email possui UNIQUE, mantemos os dois
                | cadastros sincronizados.
                |
                --------------------------------------------------------------
                */

                if ($email !== "") {

                    $sqlUpdateUsuario = "
                        UPDATE usuarios
                        SET
                            nome = ?,
                            email = ?
                        WHERE id = ?
                          AND tipo = 'cliente'
                    ";

                    $stmtUpdateUsuario = $pdo->prepare(
                        $sqlUpdateUsuario
                    );

                    $stmtUpdateUsuario->execute([

                        $nome,

                        $email,

                        $_SESSION["usuario_id"]

                    ]);

                } else {

                    /*
                    |
                    | A conta precisa continuar tendo um e-mail porque
                    | usuarios.email é NOT NULL.
                    |
                    | Nesse caso mantemos o e-mail atual da conta.
                    |
                    */

                    $sqlUpdateUsuario = "
                        UPDATE usuarios
                        SET
                            nome = ?
                        WHERE id = ?
                          AND tipo = 'cliente'
                    ";

                    $stmtUpdateUsuario = $pdo->prepare(
                        $sqlUpdateUsuario
                    );

                    $stmtUpdateUsuario->execute([

                        $nome,

                        $_SESSION["usuario_id"]

                    ]);

                }


                $pdo->commit();


                /*
                |--------------------------------------------------------------------------
                | ATUALIZA SESSÃO
                |--------------------------------------------------------------------------
                */

                $_SESSION["usuario_nome"] = $nome;

                $_SESSION["cliente_nome"] = $nome;

                $_SESSION["cliente_telefone"] =
                    $telefone;


                if ($email !== "") {

                    $_SESSION["usuario_email"] =
                        $email;

                }


                $mensagem =
                    "Seus dados foram atualizados com sucesso.";


                /*
                |--------------------------------------------------------------------------
                | RECARREGA CLIENTE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    $clienteId
                ]);

                $cliente = $stmt->fetch();


            } catch (Exception $e) {

                if ($pdo->inTransaction()) {

                    $pdo->rollBack();

                }

                $erro =
                    "Não foi possível atualizar seus dados.";

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
        Minha conta
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            background: #f5f6fa;

            color: #222;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

        }


        .container {

            width: 100%;

            max-width: 900px;

            margin: 0 auto;

            padding: 30px 20px 50px;

        }


        /*
        ==========================================================
        TOPO
        ==========================================================
        */

        .topo {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;

        }


        .topo h1 {

            margin: 0;

            font-size: 30px;

        }


        .topo p {

            margin: 6px 0 0;

            color: #666;

        }


        .botoes {

            display: flex;

            gap: 10px;

            flex-wrap: wrap;

        }


        .botao {

            display: inline-block;

            padding: 11px 18px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

        }


        .botao-voltar {

            background: #e9ecef;

            color: #222;

        }


        .botao-inicio {

            background: #222;

            color: white;

        }


        /*
        ==========================================================
        CARD
        ==========================================================
        */

        .card {

            background: #fff;

            border-radius: 16px;

            padding: 28px;

            box-shadow:
                0 4px 20px rgba(0, 0, 0, 0.06);

        }


        .card h2 {

            margin: 0 0 22px;

            padding-bottom: 12px;

            border-bottom: 1px solid #eee;

            font-size: 21px;

        }


        /*
        ==========================================================
        MENSAGENS
        ==========================================================
        */

        .mensagem {

            padding: 14px 16px;

            margin-bottom: 20px;

            border-radius: 8px;

            background: #d4edda;

            color: #155724;

            font-weight: bold;

        }


        .erro {

            padding: 14px 16px;

            margin-bottom: 20px;

            border-radius: 8px;

            background: #f8d7da;

            color: #721c24;

            font-weight: bold;

        }


        /*
        ==========================================================
        FORMULÁRIO
        ==========================================================
        */

        .linha {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 18px;

            margin-bottom: 18px;

        }


        .campo {

            display: flex;

            flex-direction: column;

        }


        .campo label {

            margin-bottom: 7px;

            font-size: 14px;

            font-weight: bold;

        }


        .campo input,

        .campo textarea {

            width: 100%;

            padding: 12px 13px;

            border: 1px solid #ccc;

            border-radius: 8px;

            font-family: inherit;

            font-size: 15px;

            outline: none;

        }


        .campo input:focus,

        .campo textarea:focus {

            border-color: #222;

        }


        .campo textarea {

            min-height: 110px;

            resize: vertical;

        }


        .campo-full {

            margin-bottom: 18px;

        }


        .ajuda {

            margin-top: 6px;

            color: #777;

            font-size: 12px;

        }


        /*
        ==========================================================
        BOTÃO SALVAR
        ==========================================================
        */

        .acoes {

            display: flex;

            justify-content: flex-end;

            margin-top: 25px;

            padding-top: 20px;

            border-top: 1px solid #eee;

        }


        .botao-salvar {

            border: none;

            background: #198754;

            color: white;

            padding: 13px 25px;

            border-radius: 8px;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

        }


        .botao-salvar:hover {

            background: #157347;

        }


        /*
        ==========================================================
        DADOS DA CONTA
        ==========================================================
        */

        .dados-conta {

            margin-top: 25px;

            padding-top: 22px;

            border-top: 1px solid #eee;

        }


        .dados-conta h3 {

            margin: 0 0 15px;

            font-size: 17px;

        }


        .dados-grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 12px;

        }


        .dado {

            padding: 13px;

            background: #f8f9fa;

            border-radius: 8px;

        }


        .dado strong {

            display: block;

            font-size: 12px;

            text-transform: uppercase;

            color: #777;

            margin-bottom: 5px;

        }


        .dado span {

            font-size: 14px;

        }


        /*
        ==========================================================
        RESPONSIVO
        ==========================================================
        */

        @media (max-width: 650px) {

            .container {

                padding: 20px 12px 40px;

            }


            .topo {

                flex-direction: column;

                align-items: flex-start;

            }


            .topo h1 {

                font-size: 25px;

            }


            .linha {

                grid-template-columns: 1fr;

                gap: 18px;

            }


            .dados-grid {

                grid-template-columns: 1fr;

            }


            .acoes {

                justify-content: stretch;

            }


            .botao-salvar {

                width: 100%;

            }

        }

    </style>

</head>


<body>


<div class="container">


    <!--
    ==========================================================
    TOPO
    ==========================================================
    -->

    <div class="topo">

        <div>

            <h1>
                Minha conta
            </h1>

            <p>
                Gerencie seus dados pessoais e endereço.
            </p>

        </div>


        <div class="botoes">

            <a
                href="inicio.php"
                class="botao botao-inicio"
            >
                ← Início
            </a>

        </div>

    </div>


    <div class="card">


        <?php if ($mensagem !== ""): ?>

            <div class="mensagem">

                <?= htmlspecialchars($mensagem) ?>

            </div>

        <?php endif; ?>


        <?php if ($erro !== ""): ?>

            <div class="erro">

                <?= htmlspecialchars($erro) ?>

            </div>

        <?php endif; ?>


        <h2>
            Meus dados
        </h2>


        <form
            method="POST"
            action=""
        >


            <!--
            ======================================================
            NOME / TELEFONE
            ======================================================
            -->

            <div class="linha">


                <div class="campo">

                    <label for="nome">
                        Nome completo *
                    </label>

                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        maxlength="150"
                        required
                        value="<?= htmlspecialchars(
                            $cliente["nome"] ?? ""
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="telefone">
                        Telefone
                    </label>

                    <input
                        type="text"
                        id="telefone"
                        name="telefone"
                        maxlength="30"
                        value="<?= htmlspecialchars(
                            $cliente["telefone"] ?? ""
                        ) ?>"
                    >

                </div>


            </div>


            <!--
            ======================================================
            EMAIL
            ======================================================
            -->

            <div class="campo-full">

                <div class="campo">

                    <label for="email">
                        E-mail
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $cliente["email"] ?? ""
                        ) ?>"
                    >

                    <div class="ajuda">
                        O e-mail também é utilizado para entrar na sua conta.
                    </div>

                </div>

            </div>


            <!--
            ======================================================
            ENDEREÇO
            ======================================================
            -->

            <div class="linha">


                <div class="campo">

                    <label for="endereco">
                        Endereço
                    </label>

                    <input
                        type="text"
                        id="endereco"
                        name="endereco"
                        maxlength="255"
                        value="<?= htmlspecialchars(
                            $cliente["endereco"] ?? ""
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="numero">
                        Número
                    </label>

                    <input
                        type="text"
                        id="numero"
                        name="numero"
                        maxlength="20"
                        value="<?= htmlspecialchars(
                            $cliente["numero"] ?? ""
                        ) ?>"
                    >

                </div>


            </div>


            <!--
            ======================================================
            BAIRRO / CIDADE
            ======================================================
            -->

            <div class="linha">


                <div class="campo">

                    <label for="bairro">
                        Bairro
                    </label>

                    <input
                        type="text"
                        id="bairro"
                        name="bairro"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $cliente["bairro"] ?? ""
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="cidade">
                        Cidade
                    </label>

                    <input
                        type="text"
                        id="cidade"
                        name="cidade"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $cliente["cidade"] ?? ""
                        ) ?>"
                    >

                </div>


            </div>


            <!--
            ======================================================
            OBSERVAÇÃO
            ======================================================
            -->

            <div class="campo-full">

                <div class="campo">

                    <label for="observacao">
                        Observação
                    </label>

                    <textarea
                        id="observacao"
                        name="observacao"
                        maxlength="500"
                    ><?= htmlspecialchars(
                        $cliente["observacao"] ?? ""
                    ) ?></textarea>

                    <div class="ajuda">
                        Você pode deixar uma observação padrão para seus pedidos.
                    </div>

                </div>

            </div>


            <!--
            ======================================================
            SALVAR
            ======================================================
            -->

            <div class="acoes">

                <button
                    type="submit"
                    class="botao-salvar"
                >
                    Salvar alterações
                </button>

            </div>


        </form>


        <!--
        ==========================================================
        INFORMAÇÕES DA CONTA
        ==========================================================
        -->

        <div class="dados-conta">

            <h3>
                Informações da conta
            </h3>


            <div class="dados-grid">


                <div class="dado">

                    <strong>
                        ID do cliente
                    </strong>

                    <span>
                        #<?= (int) $cliente["id"] ?>
                    </span>

                </div>


                <div class="dado">

                    <strong>
                        Situação
                    </strong>

                    <span>

                        <?= (int) $cliente["ativo"] === 1
                            ? "Conta ativa"
                            : "Conta inativa"
                        ?>

                    </span>

                </div>


            </div>

        </div>


    </div>


</div>


</body>

</html>