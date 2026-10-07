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

if (
    !isset($_SESSION["carrinho"]) ||
    !is_array($_SESSION["carrinho"]) ||
    count($_SESSION["carrinho"]) === 0
) {
    header("Location: carrinho.php");
    exit;
}


$carrinho = $_SESSION["carrinho"];

$cliente_id = (int) $_SESSION["cliente_id"];


/*
|--------------------------------------------------------------------------
| VERIFICAR CARRINHO
|--------------------------------------------------------------------------
|
| Todos os itens precisam pertencer à mesma confeiteira.
|
*/

$confeiteira_id = null;

$total = 0;

$totalItens = 0;


foreach ($carrinho as $item) {

    if (
        !isset($item["bolo_id"]) ||
        !isset($item["confeiteira_id"]) ||
        !isset($item["quantidade"])
    ) {
        header("Location: carrinho.php");
        exit;
    }


    $itemConfeiteira =
        (int) $item["confeiteira_id"];


    if ($confeiteira_id === null) {

        $confeiteira_id =
            $itemConfeiteira;

    } elseif (
        $confeiteira_id !== $itemConfeiteira
    ) {

        /*
        |--------------------------------------------------------------
        | NÃO PERMITIR CONFEITEIRAS DIFERENTES
        |--------------------------------------------------------------
        */

        $_SESSION["erro_carrinho"] =
            "Não é possível finalizar um pedido com produtos de confeiteiras diferentes.";

        header("Location: carrinho.php");
        exit;
    }


    $quantidade =
        (int) $item["quantidade"];


    $preco =
        (float) $item["preco"];


    if ($quantidade < 1) {

        header("Location: carrinho.php");
        exit;
    }


    $total +=
        $quantidade * $preco;


    $totalItens +=
        $quantidade;
}


if ($confeiteira_id === null) {

    header("Location: carrinho.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR CLIENTE
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
        observacao
    FROM clientes
    WHERE id = ?
      AND ativo = 1
    LIMIT 1
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $cliente_id
]);

$cliente = $stmt->fetch();


if (!$cliente) {

    session_destroy();

    header("Location: login.php");
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
| VALIDAR NOVAMENTE OS PRODUTOS NO BANCO
|--------------------------------------------------------------------------
|
| Não confiamos somente nos dados da sessão.
|
| O preço utilizado para criar o pedido será buscado
| novamente no banco.
|
*/

$idsBolos = [];

foreach ($carrinho as $item) {

    $idsBolos[] =
        (int) $item["bolo_id"];
}

$idsBolos = array_unique($idsBolos);

$placeholders =
    implode(
        ",",
        array_fill(
            0,
            count($idsBolos),
            "?"
        )
    );


$sql = "
    SELECT
        id,
        confeiteira_id,
        nome,
        preco_venda,
        ativo
    FROM bolos
    WHERE id IN ($placeholders)
      AND confeiteira_id = ?
      AND ativo = 1
";

$parametros = $idsBolos;

$parametros[] =
    $confeiteira_id;


$stmt = $pdo->prepare($sql);

$stmt->execute($parametros);

$bolosBanco =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| INDEXAR PRODUTOS
|--------------------------------------------------------------------------
*/

$produtosBanco = [];

foreach ($bolosBanco as $bolo) {

    $produtosBanco[
        (int)$bolo["id"]
    ] = $bolo;
}


/*
|--------------------------------------------------------------------------
| GARANTIR QUE TODOS EXISTEM
|--------------------------------------------------------------------------
*/

foreach ($carrinho as $item) {

    $boloId =
        (int)$item["bolo_id"];


    if (
        !isset(
            $produtosBanco[$boloId]
        )
    ) {

        $_SESSION["erro_carrinho"] =
            "Um dos produtos do seu carrinho não está mais disponível.";

        header("Location: carrinho.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| RECALCULAR TOTAL REAL
|--------------------------------------------------------------------------
*/

$total = 0;

$totalItens = 0;


foreach ($carrinho as $item) {

    $boloId =
        (int)$item["bolo_id"];


    $quantidade =
        (int)$item["quantidade"];


    $precoAtual =
        (float)$produtosBanco[
            $boloId
        ]["preco_venda"];


    $total +=
        $quantidade * $precoAtual;


    $totalItens +=
        $quantidade;
}


/*
|--------------------------------------------------------------------------
| DADOS INICIAIS DO FORMULÁRIO
|--------------------------------------------------------------------------
*/

$nome =
    $cliente["nome"] ?? "";

$telefone =
    $cliente["telefone"] ?? "";

$email =
    $cliente["email"] ?? "";

$endereco =
    $cliente["endereco"] ?? "";

$numero =
    $cliente["numero"] ?? "";

$bairro =
    $cliente["bairro"] ?? "";

$cidade =
    $cliente["cidade"] ?? "";


$dataEntrega = "";

$formaPagamento = "";

$observacao = "";

$erro = "";


/*
|--------------------------------------------------------------------------
| PROCESSAR PEDIDO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

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


    $dataEntrega =
        trim(
            $_POST["data_entrega"] ?? ""
        );


    $formaPagamento =
        trim(
            $_POST["forma_pagamento"] ?? ""
        );


    $observacao =
        trim(
            $_POST["observacao"] ?? ""
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erro =
            "Informe seu nome.";

    } elseif ($telefone === "") {

        $erro =
            "Informe seu telefone.";

    } elseif ($endereco === "") {

        $erro =
            "Informe o endereço.";

    } elseif ($numero === "") {

        $erro =
            "Informe o número.";

    } elseif ($bairro === "") {

        $erro =
            "Informe o bairro.";

    } elseif ($cidade === "") {

        $erro =
            "Informe a cidade.";

    } elseif ($dataEntrega === "") {

        $erro =
            "Informe a data de entrega.";

    } elseif (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $dataEntrega
        )
    ) {

        $erro =
            "Informe uma data de entrega válida.";

    } elseif (
        $dataEntrega < date("Y-m-d")
    ) {

        $erro =
            "A data de entrega não pode ser anterior a hoje.";

    } elseif ($formaPagamento === "") {

        $erro =
            "Selecione a forma de pagamento.";

    }


    /*
    |--------------------------------------------------------------------------
    | CRIAR PEDIDO
    |--------------------------------------------------------------------------
    */

    if ($erro === "") {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------
            | ATUALIZAR DADOS DO CLIENTE
            |--------------------------------------------------------------
            */

            $sql = "
                UPDATE clientes
                SET
                    nome = ?,
                    telefone = ?,
                    email = ?,
                    endereco = ?,
                    numero = ?,
                    bairro = ?,
                    cidade = ?
                WHERE id = ?
            ";

            $stmt =
                $pdo->prepare($sql);


            $stmt->execute([
                $nome,
                $telefone,
                $email !== "" ? $email : null,
                $endereco,
                $numero,
                $bairro,
                $cidade,
                $cliente_id
            ]);


            /*
            |--------------------------------------------------------------
            | VINCULAR CLIENTE À CONFEITEIRA
            |--------------------------------------------------------------
            */

            $sql = "
                INSERT IGNORE INTO cliente_confeiteiras
                (
                    cliente_id,
                    confeiteira_id
                )
                VALUES (?, ?)
            ";

            $stmt =
                $pdo->prepare($sql);


            $stmt->execute([
                $cliente_id,
                $confeiteira_id
            ]);


            /*
            |--------------------------------------------------------------
            | VERIFICAR PRÓXIMO NÚMERO DO PEDIDO
            |--------------------------------------------------------------
            */

            $sql = "
                SELECT
                    COALESCE(
                        MAX(numero_pedido),
                        0
                    ) + 1 AS proximo
                FROM pedidos
                WHERE confeiteira_id = ?
            ";

            $stmt =
                $pdo->prepare($sql);


            $stmt->execute([
                $confeiteira_id
            ]);


            $resultadoNumero =
                $stmt->fetch();


            $numeroPedido =
                (int)$resultadoNumero["proximo"];


            /*
            |--------------------------------------------------------------
            | CRIAR PEDIDO
            |--------------------------------------------------------------
            */

            $sql = "
                INSERT INTO pedidos
                (
                    confeiteira_id,
                    cliente_id,
                    numero_pedido,
                    data_pedido,
                    data_entrega,
                    status,
                    valor_total,
                    valor_recebido,
                    valor_restante,
                    forma_pagamento,
                    observacao
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'orcamento',
                    ?,
                    0,
                    ?,
                    ?,
                    ?
                )
            ";


            $valorRestante =
                $total;


            $stmt =
                $pdo->prepare($sql);


            $stmt->execute([
                $confeiteira_id,
                $cliente_id,
                $numeroPedido,
                date("Y-m-d"),
                $dataEntrega,
                $total,
                $valorRestante,
                $formaPagamento,
                $observacao !== ""
                    ? $observacao
                    : null
            ]);


            /*
            |--------------------------------------------------------------
            | ID DO PEDIDO
            |--------------------------------------------------------------
            */

            $pedidoId =
                (int)$pdo->lastInsertId();


            /*
            |--------------------------------------------------------------
            | INSERIR ITENS
            |--------------------------------------------------------------
            */

            $sqlItem = "
                INSERT INTO pedido_itens
                (
                    pedido_id,
                    bolo_id,
                    quantidade,
                    preco_unitario,
                    subtotal,
                    observacao
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ";


            $stmtItem =
                $pdo->prepare($sqlItem);


            foreach ($carrinho as $item) {

                $boloId =
                    (int)$item["bolo_id"];


                $quantidade =
                    (int)$item["quantidade"];


                $precoUnitario =
                    (float)$produtosBanco[
                        $boloId
                    ]["preco_venda"];


                $subtotal =
                    $quantidade *
                    $precoUnitario;


                $itemObservacao =
                    trim(
                        $item["observacao"] ?? ""
                    );


                $stmtItem->execute([
                    $pedidoId,
                    $boloId,
                    $quantidade,
                    $precoUnitario,
                    $subtotal,
                    $itemObservacao !== ""
                        ? $itemObservacao
                        : null
                ]);
            }


            /*
            |--------------------------------------------------------------
            | ATUALIZAR SESSÃO DO CLIENTE
            |--------------------------------------------------------------
            */

            $_SESSION["cliente_nome"] =
                $nome;

            $_SESSION["cliente_telefone"] =
                $telefone;


            /*
            |--------------------------------------------------------------
            | LIMPAR CARRINHO
            |--------------------------------------------------------------
            */

            $_SESSION["carrinho"] = [];


            /*
            |--------------------------------------------------------------
            | GUARDAR ID DO PEDIDO
            |--------------------------------------------------------------
            */

            $_SESSION["ultimo_pedido_id"] =
                $pedidoId;


            /*
            |--------------------------------------------------------------
            | FINALIZAR TRANSAÇÃO
            |--------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------
            | IR PARA PEDIDO CRIADO
            |--------------------------------------------------------------
            */

            header(
                "Location: pedido_criado.php?id="
                . $pedidoId
            );

            exit;


        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------
            | DESFAZER TUDO
            |--------------------------------------------------------------
            */

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            $erro =
                "Não foi possível finalizar o pedido. "
                . "Tente novamente.";
        }
    }
}


/*
|--------------------------------------------------------------------------
| FORMATAÇÃO
|--------------------------------------------------------------------------
*/

$totalFormatado =
    number_format(
        $total,
        2,
        ",",
        "."
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
        Finalizar Pedido - Sistema de Bolos
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

            max-width: 1150px;

            margin:
                0 auto;

            padding:
                30px 20px 50px;
        }


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
        | ERRO
        |--------------------------------------------------------------------------
        */

        .erro {

            background: #f8d7da;

            color: #842029;

            border:
                1px solid #f1aeb5;

            border-radius: 8px;

            padding: 13px 15px;

            margin-bottom: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | LAYOUT
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
        | FORMULÁRIO
        |--------------------------------------------------------------------------
        */

        .formulario {

            background: white;

            border-radius: 15px;

            padding: 28px;

            box-shadow:
                0 4px 15px
                rgba(
                    0,
                    0,
                    0,
                    0.06
                );
        }


        .formulario h2 {

            font-size: 21px;

            margin-bottom: 20px;
        }


        .grupo {

            margin-bottom: 20px;
        }


        .grupo-titulo {

            font-size: 17px;

            font-weight: bold;

            color: #7b2cbf;

            margin-bottom: 15px;

            padding-bottom: 8px;

            border-bottom:
                1px solid #eee;
        }


        .linha {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 15px;

            margin-bottom: 15px;
        }


        .linha.endereco {

            grid-template-columns:
                minmax(0, 1fr)
                120px;
        }


        .campo {

            display: flex;

            flex-direction: column;
        }


        .campo label {

            font-size: 13px;

            font-weight: bold;

            margin-bottom: 7px;

            color: #555;
        }


        .campo input,
        .campo select,
        .campo textarea {

            width: 100%;

            padding: 12px 13px;

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
        .campo select:focus,
        .campo textarea:focus {

            border-color: #7b2cbf;
        }


        .campo textarea {

            min-height: 100px;

            resize: vertical;
        }


        .obrigatorio {

            color: #dc3545;
        }


        /*
        |--------------------------------------------------------------------------
        | RESUMO
        |--------------------------------------------------------------------------
        */

        .resumo {

            background: white;

            border-radius: 15px;

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

            margin-bottom: 18px;
        }


        .confeitaria {

            background: #f5eafa;

            color: #7b2cbf;

            padding: 12px;

            border-radius: 8px;

            margin-bottom: 18px;

            font-size: 14px;

            font-weight: bold;
        }


        .item-resumo {

            display: flex;

            justify-content:
                space-between;

            gap: 10px;

            padding:
                12px 0;

            border-bottom:
                1px solid #eee;

            font-size: 14px;
        }


        .item-resumo:last-of-type {

            border-bottom: none;
        }


        .item-nome {

            color: #555;

            line-height: 1.4;
        }


        .item-valor {

            font-weight: bold;

            white-space: nowrap;
        }


        .total {

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 15px;

            border-top:
                1px solid #ddd;

            padding-top: 18px;

            margin-top: 10px;
        }


        .total span {

            font-size: 17px;

            font-weight: bold;
        }


        .total strong {

            color: #7b2cbf;

            font-size: 24px;
        }


        /*
        |--------------------------------------------------------------------------
        | BOTÃO
        |--------------------------------------------------------------------------
        */

        .btn-confirmar {

            width: 100%;

            border: none;

            background: #7b2cbf;

            color: white;

            padding: 15px;

            border-radius: 9px;

            font-size: 16px;

            font-weight: bold;

            cursor: pointer;

            margin-top: 22px;

            transition: 0.2s;
        }


        .btn-confirmar:hover {

            background: #6420a0;
        }


        .voltar {

            display: inline-block;

            margin-top: 15px;

            width: 100%;

            text-align: center;

            color: #7b2cbf;

            text-decoration: none;

            font-size: 14px;
        }


        .voltar:hover {

            text-decoration: underline;
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

                grid-row: 1;
            }

        }


        @media (max-width: 600px) {

            .container {

                padding:
                    20px 15px 40px;
            }


            .formulario {

                padding: 20px;
            }


            .linha,
            .linha.endereco {

                grid-template-columns: 1fr;
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

        <a href="carrinho.php">
            🛒 Carrinho
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


    <div class="titulo">

        <h1>
            Finalizar pedido
        </h1>

        <p>
            Confira seus dados e informe as informações da entrega.
        </p>

    </div>



    <?php if ($erro !== ""): ?>

        <div class="erro">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>



    <form
        method="POST"
        action=""
    >


        <div class="layout">


            <!--
            |--------------------------------------------------------------------------
            | DADOS
            |--------------------------------------------------------------------------
            -->

            <section class="formulario">


                <div class="grupo">

                    <div class="grupo-titulo">

                        👤 Seus dados

                    </div>


                    <div class="linha">


                        <div class="campo">

                            <label for="nome">

                                Nome
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="nome"
                                name="nome"
                                value="<?= htmlspecialchars($nome) ?>"
                                required
                            >

                        </div>



                        <div class="campo">

                            <label for="telefone">

                                Telefone
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="telefone"
                                name="telefone"
                                value="<?= htmlspecialchars($telefone) ?>"
                                required
                            >

                        </div>


                    </div>



                    <div class="campo">

                        <label for="email">

                            E-mail

                        </label>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($email) ?>"
                        >

                    </div>


                </div>



                <div class="grupo">


                    <div class="grupo-titulo">

                        📍 Endereço de entrega

                    </div>



                    <div class="linha endereco">


                        <div class="campo">

                            <label for="endereco">

                                Endereço
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="endereco"
                                name="endereco"
                                value="<?= htmlspecialchars($endereco) ?>"
                                required
                            >

                        </div>



                        <div class="campo">

                            <label for="numero">

                                Número
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="numero"
                                name="numero"
                                value="<?= htmlspecialchars($numero) ?>"
                                required
                            >

                        </div>


                    </div>



                    <div class="linha">


                        <div class="campo">

                            <label for="bairro">

                                Bairro
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="bairro"
                                name="bairro"
                                value="<?= htmlspecialchars($bairro) ?>"
                                required
                            >

                        </div>



                        <div class="campo">

                            <label for="cidade">

                                Cidade
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="text"
                                id="cidade"
                                name="cidade"
                                value="<?= htmlspecialchars($cidade) ?>"
                                required
                            >

                        </div>


                    </div>


                </div>



                <div class="grupo">


                    <div class="grupo-titulo">

                        📅 Dados do pedido

                    </div>



                    <div class="linha">


                        <div class="campo">

                            <label for="data_entrega">

                                Data desejada para entrega
                                <span class="obrigatorio">*</span>

                            </label>


                            <input
                                type="date"
                                id="data_entrega"
                                name="data_entrega"
                                value="<?= htmlspecialchars($dataEntrega) ?>"
                                min="<?= date("Y-m-d") ?>"
                                required
                            >

                        </div>



                        <div class="campo">

                            <label for="forma_pagamento">

                                Forma de pagamento
                                <span class="obrigatorio">*</span>

                            </label>


                            <select
                                id="forma_pagamento"
                                name="forma_pagamento"
                                required
                            >

                                <option value="">
                                    Selecione
                                </option>


                                <option
                                    value="Pix"
                                    <?= $formaPagamento === "Pix"
                                        ? "selected"
                                        : "" ?>
                                >
                                    Pix
                                </option>


                                <option
                                    value="Dinheiro"
                                    <?= $formaPagamento === "Dinheiro"
                                        ? "selected"
                                        : "" ?>
                                >
                                    Dinheiro
                                </option>


                                <option
                                    value="Cartão de crédito"
                                    <?= $formaPagamento === "Cartão de crédito"
                                        ? "selected"
                                        : "" ?>
                                >
                                    Cartão de crédito
                                </option>


                                <option
                                    value="Cartão de débito"
                                    <?= $formaPagamento === "Cartão de débito"
                                        ? "selected"
                                        : "" ?>
                                >
                                    Cartão de débito
                                </option>


                            </select>

                        </div>


                    </div>



                    <div class="campo">

                        <label for="observacao">

                            Observações do pedido

                        </label>


                        <textarea
                            id="observacao"
                            name="observacao"
                            placeholder="Ex.: horário preferencial, informações sobre a entrega, mensagem para o bolo, etc."
                        ><?= htmlspecialchars($observacao) ?></textarea>

                    </div>


                </div>


            </section>



            <!--
            |--------------------------------------------------------------------------
            | RESUMO
            |--------------------------------------------------------------------------
            -->

            <aside class="resumo">


                <h2>

                    Resumo do pedido

                </h2>



                <div class="confeitaria">

                    🧁

                    <?= htmlspecialchars(
                        $confeiteira["nome_confeitaria"]
                    ) ?>

                </div>



                <?php foreach ($carrinho as $item): ?>


                    <?php

                    $boloId =
                        (int)$item["bolo_id"];


                    $quantidade =
                        (int)$item["quantidade"];


                    $precoAtual =
                        (float)$produtosBanco[
                            $boloId
                        ]["preco_venda"];


                    $subtotal =
                        $quantidade *
                        $precoAtual;

                    ?>


                    <div class="item-resumo">


                        <div class="item-nome">

                            <strong>

                                <?= htmlspecialchars(
                                    $item["nome"]
                                ) ?>

                            </strong>


                            <br>


                            <?= $quantidade ?>

                            x

                            R$

                            <?= number_format(
                                $precoAtual,
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>


                        <div class="item-valor">

                            R$

                            <?= number_format(
                                $subtotal,
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>


                    </div>


                <?php endforeach; ?>



                <div class="total">

                    <span>

                        Total

                    </span>


                    <strong>

                        R$

                        <?= $totalFormatado ?>

                    </strong>

                </div>



                <button
                    type="submit"
                    class="btn-confirmar"
                >

                    ✓ Confirmar pedido

                </button>



                <a
                    href="carrinho.php"
                    class="voltar"
                >

                    ← Voltar para o carrinho

                </a>


            </aside>


        </div>


    </form>


</main>


</body>

</html>