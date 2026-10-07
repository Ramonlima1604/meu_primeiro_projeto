<?php

session_start();

require_once "../config/banco.php";


/*
|--------------------------------------------------------------------------
| VERIFICA LOGIN DO CLIENTE
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


/*
|--------------------------------------------------------------------------
| PEGA ID DO PEDIDO
|--------------------------------------------------------------------------
*/

$pedidoId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$pedidoId) {
    header("Location: meus_pedidos.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCA PEDIDO
|--------------------------------------------------------------------------
|
| IMPORTANTE:
| O pedido só será encontrado se pertencer ao cliente
| atualmente logado.
|
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.*,

        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        c.email AS cliente_email,
        c.endereco AS cliente_endereco,
        c.numero AS cliente_numero,
        c.bairro AS cliente_bairro,
        c.cidade AS cliente_cidade,

        cf.nome_confeitaria,
        cf.telefone AS confeiteira_telefone,
        cf.cidade AS confeiteira_cidade,
        cf.descricao AS confeiteira_descricao

    FROM pedidos p

    INNER JOIN clientes c
        ON c.id = p.cliente_id

    INNER JOIN confeiteiras cf
        ON cf.id = p.confeiteira_id

    WHERE p.id = ?
      AND p.cliente_id = ?

    LIMIT 1
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $pedidoId,
    $clienteId
]);

$pedido = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| PEDIDO NÃO ENCONTRADO
|--------------------------------------------------------------------------
*/

if (!$pedido) {

    header("Location: meus_pedidos.php");
    exit;

}


/*
|--------------------------------------------------------------------------
| BUSCA ITENS
|--------------------------------------------------------------------------
*/

$sqlItens = "
    SELECT
        pi.id,
        pi.bolo_id,
        pi.quantidade,
        pi.preco_unitario,
        pi.subtotal,
        pi.observacao,

        b.nome AS bolo_nome,
        b.descricao AS bolo_descricao

    FROM pedido_itens pi

    INNER JOIN bolos b
        ON b.id = pi.bolo_id

    WHERE pi.pedido_id = ?

    ORDER BY pi.id ASC
";

$stmtItens = $pdo->prepare($sqlItens);

$stmtItens->execute([
    $pedidoId
]);

$itens = $stmtItens->fetchAll();


/*
|--------------------------------------------------------------------------
| BUSCA PAGAMENTOS
|--------------------------------------------------------------------------
|
| Os pagamentos reais ficam na tabela recebimentos.
|
|--------------------------------------------------------------------------
*/

$sqlPagamentos = "
    SELECT
        id,
        descricao,
        valor,
        data_recebimento,
        forma_pagamento,
        observacao

    FROM recebimentos

    WHERE pedido_id = ?
      AND confeiteira_id = ?

    ORDER BY data_recebimento DESC, id DESC
";

$stmtPagamentos = $pdo->prepare($sqlPagamentos);

$stmtPagamentos->execute([
    $pedidoId,
    $pedido["confeiteira_id"]
]);

$pagamentos = $stmtPagamentos->fetchAll();


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusNomes = [

    "orcamento" =>
        "Aguardando confirmação",

    "confirmado" =>
        "Pedido confirmado",

    "producao" =>
        "Em produção",

    "pronto" =>
        "Pronto para entrega",

    "entregue" =>
        "Entregue",

    "cancelado" =>
        "Cancelado"

];


$statusClasses = [

    "orcamento" =>
        "status-orcamento",

    "confirmado" =>
        "status-confirmado",

    "producao" =>
        "status-producao",

    "pronto" =>
        "status-pronto",

    "entregue" =>
        "status-entregue",

    "cancelado" =>
        "status-cancelado"

];


$status = $pedido["status"];

$statusTexto =
    $statusNomes[$status]
    ?? ucfirst($status);

$statusClasse =
    $statusClasses[$status]
    ?? "status-orcamento";


/*
|--------------------------------------------------------------------------
| FORMATAÇÕES
|--------------------------------------------------------------------------
*/

$numeroPedido = !empty($pedido["numero_pedido"])
    ? $pedido["numero_pedido"]
    : $pedido["id"];


$dataPedido = !empty($pedido["data_pedido"])
    ? date(
        "d/m/Y",
        strtotime($pedido["data_pedido"])
    )
    : "-";


$dataEntrega = !empty($pedido["data_entrega"])
    ? date(
        "d/m/Y",
        strtotime($pedido["data_entrega"])
    )
    : "A combinar";


$valorTotal = (float) $pedido["valor_total"];


/*
|--------------------------------------------------------------------------
| VALOR REAL RECEBIDO
|--------------------------------------------------------------------------
|
| Usamos a soma da tabela recebimentos como fonte de verdade.
|
|--------------------------------------------------------------------------
*/

$valorRecebido = 0;

foreach ($pagamentos as $pagamento) {

    $valorRecebido += (float) $pagamento["valor"];

}


/*
|--------------------------------------------------------------------------
| VALOR RESTANTE
|--------------------------------------------------------------------------
*/

$valorRestante =
    $valorTotal - $valorRecebido;

if ($valorRestante < 0) {
    $valorRestante = 0;
}


/*
|--------------------------------------------------------------------------
| TOTAL DE ITENS
|--------------------------------------------------------------------------
*/

$totalItens = 0;

foreach ($itens as $item) {

    $totalItens +=
        (float) $item["quantidade"];

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
        Pedido #<?= htmlspecialchars($numeroPedido) ?>
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

            max-width: 1050px;

            margin: 0 auto;

            padding: 25px 20px 50px;

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

            font-size: 28px;

        }


        .topo p {

            margin: 6px 0 0;

            color: #666;

        }


        .botoes-topo {

            display: flex;

            gap: 10px;

            flex-wrap: wrap;

        }


        .botao {

            display: inline-block;

            padding: 11px 17px;

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
        CARDS
        ==========================================================
        */

        .card {

            background: white;

            border-radius: 16px;

            padding: 25px;

            margin-bottom: 20px;

            box-shadow:
                0 3px 15px rgba(0, 0, 0, 0.06);

        }


        .card h2 {

            margin: 0 0 20px;

            font-size: 20px;

            padding-bottom: 12px;

            border-bottom: 1px solid #eee;

        }


        /*
        ==========================================================
        RESUMO
        ==========================================================
        */

        .resumo {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 15px;

        }


        .resumo-box {

            padding: 18px;

            background: #f8f9fa;

            border-radius: 10px;

        }


        .resumo-box strong {

            display: block;

            font-size: 12px;

            color: #777;

            text-transform: uppercase;

            margin-bottom: 7px;

        }


        .resumo-box span {

            font-size: 17px;

            font-weight: bold;

        }


        /*
        ==========================================================
        STATUS
        ==========================================================
        */

        .status {

            display: inline-block;

            padding: 8px 13px;

            border-radius: 20px;

            font-size: 13px;

            font-weight: bold;

        }


        .status-orcamento {

            background: #fff3cd;

            color: #856404;

        }


        .status-confirmado {

            background: #d1ecf1;

            color: #0c5460;

        }


        .status-producao {

            background: #cce5ff;

            color: #004085;

        }


        .status-pronto {

            background: #d4edda;

            color: #155724;

        }


        .status-entregue {

            background: #d4edda;

            color: #155724;

        }


        .status-cancelado {

            background: #f8d7da;

            color: #721c24;

        }


        /*
        ==========================================================
        ITENS
        ==========================================================
        */

        .item {

            padding: 18px 0;

            border-bottom: 1px solid #eee;

            display: flex;

            justify-content: space-between;

            gap: 20px;

        }


        .item:last-child {

            border-bottom: none;

        }


        .item-nome {

            font-size: 17px;

            font-weight: bold;

            margin-bottom: 7px;

        }


        .item-info {

            color: #666;

            font-size: 14px;

            line-height: 1.6;

        }


        .item-observacao {

            margin-top: 7px;

            color: #555;

            font-size: 14px;

        }


        .item-valor {

            text-align: right;

            white-space: nowrap;

        }


        .item-valor strong {

            display: block;

            font-size: 17px;

        }


        .item-valor small {

            color: #777;

        }


        .total {

            display: flex;

            justify-content: space-between;

            align-items: center;

            border-top: 2px solid #eee;

            padding-top: 20px;

            margin-top: 10px;

            font-size: 22px;

            font-weight: bold;

        }


        .total-valor {

            color: #198754;

        }


        /*
        ==========================================================
        PAGAMENTOS
        ==========================================================
        */

        .pagamento {

            display: flex;

            justify-content: space-between;

            gap: 20px;

            padding: 15px 0;

            border-bottom: 1px solid #eee;

        }


        .pagamento:last-child {

            border-bottom: none;

        }


        .pagamento-descricao {

            font-weight: bold;

            margin-bottom: 5px;

        }


        .pagamento-info {

            color: #777;

            font-size: 14px;

        }


        .pagamento-valor {

            font-weight: bold;

            color: #198754;

            white-space: nowrap;

        }


        .sem-pagamentos {

            padding: 15px;

            background: #fff8e1;

            border-radius: 8px;

            color: #856404;

        }


        /*
        ==========================================================
        FINANCEIRO
        ==========================================================
        */

        .financeiro {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

        }


        .financeiro-box {

            padding: 20px;

            border-radius: 10px;

            background: #f8f9fa;

        }


        .financeiro-box strong {

            display: block;

            font-size: 12px;

            text-transform: uppercase;

            color: #777;

            margin-bottom: 7px;

        }


        .financeiro-box span {

            font-size: 22px;

            font-weight: bold;

        }


        .financeiro-total span {

            color: #222;

        }


        .financeiro-pago span {

            color: #198754;

        }


        .financeiro-restante span {

            color: #dc3545;

        }


        /*
        ==========================================================
        CLIENTE / ENTREGA
        ==========================================================
        */

        .dados {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 15px;

        }


        .dado {

            padding: 15px;

            background: #f8f9fa;

            border-radius: 9px;

        }


        .dado strong {

            display: block;

            color: #777;

            font-size: 12px;

            text-transform: uppercase;

            margin-bottom: 5px;

        }


        .dado span {

            font-size: 15px;

        }


        .observacao {

            margin-top: 20px;

            padding: 15px;

            background: #fff8e1;

            border-left: 4px solid #ffc107;

            border-radius: 6px;

            line-height: 1.5;

        }


        /*
        ==========================================================
        RESPONSIVO
        ==========================================================
        */

        @media (max-width: 800px) {

            .resumo {

                grid-template-columns:
                    repeat(2, 1fr);

            }


            .financeiro {

                grid-template-columns:
                    1fr;

            }

        }


        @media (max-width: 600px) {

            .container {

                padding: 20px 12px 40px;

            }


            .topo {

                flex-direction: column;

                align-items: flex-start;

            }


            .topo h1 {

                font-size: 24px;

            }


            .resumo {

                grid-template-columns:
                    1fr;

            }


            .dados {

                grid-template-columns:
                    1fr;

            }


            .item {

                flex-direction: column;

            }


            .item-valor {

                text-align: left;

            }


            .pagamento {

                flex-direction: column;

            }


            .total {

                font-size: 19px;

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

                Pedido nº
                <?= htmlspecialchars($numeroPedido) ?>

            </h1>

            <p>

                <?= htmlspecialchars(
                    $pedido["nome_confeitaria"]
                ) ?>

            </p>

        </div>


        <div class="botoes-topo">

            <a
                href="meus_pedidos.php"
                class="botao botao-voltar"
            >
                ← Meus pedidos
            </a>


            <a
                href="inicio.php"
                class="botao botao-inicio"
            >
                Início
            </a>

        </div>

    </div>


    <!--
    ==========================================================
    RESUMO
    ==========================================================
    -->

    <div class="card">

        <h2>
            Resumo do pedido
        </h2>


        <div class="resumo">


            <div class="resumo-box">

                <strong>
                    Status
                </strong>

                <span>

                    <span
                        class="status <?= htmlspecialchars(
                            $statusClasse
                        ) ?>"
                    >
                        <?= htmlspecialchars(
                            $statusTexto
                        ) ?>
                    </span>

                </span>

            </div>


            <div class="resumo-box">

                <strong>
                    Data do pedido
                </strong>

                <span>
                    <?= htmlspecialchars($dataPedido) ?>
                </span>

            </div>


            <div class="resumo-box">

                <strong>
                    Data de entrega
                </strong>

                <span>
                    <?= htmlspecialchars($dataEntrega) ?>
                </span>

            </div>


            <div class="resumo-box">

                <strong>
                    Pagamento
                </strong>

                <span>

                    <?= !empty(
                        $pedido["forma_pagamento"]
                    )
                        ? htmlspecialchars(
                            $pedido["forma_pagamento"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


        </div>

    </div>


    <!--
    ==========================================================
    ITENS
    ==========================================================
    -->

    <div class="card">

        <h2>
            Produtos do pedido
        </h2>


        <?php if (empty($itens)): ?>

            <p>
                Nenhum produto encontrado neste pedido.
            </p>

        <?php else: ?>


            <?php foreach ($itens as $item): ?>


                <div class="item">


                    <div>

                        <div class="item-nome">

                            <?= htmlspecialchars(
                                $item["bolo_nome"]
                            ) ?>

                        </div>


                        <div class="item-info">

                            Quantidade:
                            <strong>
                                <?= number_format(
                                    (float) $item["quantidade"],
                                    3,
                                    ",",
                                    "."
                                ) ?>
                            </strong>

                            <br>

                            Preço unitário:
                            R$
                            <?= number_format(
                                (float) $item["preco_unitario"],
                                2,
                                ",",
                                "."
                            ) ?>

                        </div>


                        <?php if (
                            !empty(
                                $item["observacao"]
                            )
                        ): ?>

                            <div class="item-observacao">

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


                    </div>


                    <div class="item-valor">

                        <small>
                            Subtotal
                        </small>

                        <strong>

                            R$
                            <?= number_format(
                                (float) $item["subtotal"],
                                2,
                                ",",
                                "."
                            ) ?>

                        </strong>

                    </div>


                </div>


            <?php endforeach; ?>


            <div class="total">

                <span>
                    Total do pedido
                </span>

                <span class="total-valor">

                    R$
                    <?= number_format(
                        $valorTotal,
                        2,
                        ",",
                        "."
                    ) ?>

                </span>

            </div>


        <?php endif; ?>


    </div>


    <!--
    ==========================================================
    FINANCEIRO
    ==========================================================
    -->

    <div class="card">

        <h2>
            Pagamentos
        </h2>


        <div class="financeiro">


            <div class="financeiro-box financeiro-total">

                <strong>
                    Total
                </strong>

                <span>

                    R$
                    <?= number_format(
                        $valorTotal,
                        2,
                        ",",
                        "."
                    ) ?>

                </span>

            </div>


            <div class="financeiro-box financeiro-pago">

                <strong>
                    Já pago
                </strong>

                <span>

                    R$
                    <?= number_format(
                        $valorRecebido,
                        2,
                        ",",
                        "."
                    ) ?>

                </span>

            </div>


            <div class="financeiro-box financeiro-restante">

                <strong>
                    Restante
                </strong>

                <span>

                    R$
                    <?= number_format(
                        $valorRestante,
                        2,
                        ",",
                        "."
                    ) ?>

                </span>

            </div>


        </div>


        <br>


        <?php if (empty($pagamentos)): ?>


            <div class="sem-pagamentos">

                Nenhum pagamento foi registrado neste pedido até o momento.

            </div>


        <?php else: ?>


            <?php foreach ($pagamentos as $pagamento): ?>


                <div class="pagamento">


                    <div>

                        <div class="pagamento-descricao">

                            <?= htmlspecialchars(
                                $pagamento["descricao"]
                            ) ?>

                        </div>


                        <div class="pagamento-info">

                            Data:

                            <?= date(
                                "d/m/Y",
                                strtotime(
                                    $pagamento["data_recebimento"]
                                )
                            ) ?>


                            <?php if (
                                !empty(
                                    $pagamento["forma_pagamento"]
                                )
                            ): ?>

                                <br>

                                Forma:

                                <?= htmlspecialchars(
                                    $pagamento["forma_pagamento"]
                                ) ?>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $pagamento["observacao"]
                                )
                            ): ?>

                                <br>

                                Observação:

                                <?= htmlspecialchars(
                                    $pagamento["observacao"]
                                ) ?>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="pagamento-valor">

                        R$
                        <?= number_format(
                            (float) $pagamento["valor"],
                            2,
                            ",",
                            "."
                        ) ?>

                    </div>


                </div>


            <?php endforeach; ?>


        <?php endif; ?>


    </div>


    <!--
    ==========================================================
    DADOS DO CLIENTE / ENTREGA
    ==========================================================
    -->

    <div class="card">

        <h2>
            Dados do pedido
        </h2>


        <div class="dados">


            <div class="dado">

                <strong>
                    Cliente
                </strong>

                <span>
                    <?= htmlspecialchars(
                        $pedido["cliente_nome"]
                    ) ?>
                </span>

            </div>


            <div class="dado">

                <strong>
                    Telefone
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_telefone"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_telefone"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    E-mail
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_email"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_email"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    Cidade
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_cidade"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_cidade"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    Endereço
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_endereco"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_endereco"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    Número
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_numero"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_numero"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    Bairro
                </strong>

                <span>

                    <?= !empty(
                        $pedido["cliente_bairro"]
                    )
                        ? htmlspecialchars(
                            $pedido["cliente_bairro"]
                        )
                        : "Não informado"
                    ?>

                </span>

            </div>


            <div class="dado">

                <strong>
                    Confeitaria
                </strong>

                <span>

                    <?= htmlspecialchars(
                        $pedido["nome_confeitaria"]
                    ) ?>

                </span>

            </div>


        </div>


        <?php if (!empty($pedido["observacao"])): ?>

            <div class="observacao">

                <strong>
                    Observação do pedido:
                </strong>

                <br><br>

                <?= nl2br(
                    htmlspecialchars(
                        $pedido["observacao"]
                    )
                ) ?>

            </div>

        <?php endif; ?>


    </div>


    <!--
    ==========================================================
    RODAPÉ
    ==========================================================
    -->

    <div class="botoes-topo">

        <a
            href="meus_pedidos.php"
            class="botao botao-voltar"
        >
            ← Voltar para meus pedidos
        </a>


        <a
            href="inicio.php"
            class="botao botao-inicio"
        >
            Voltar para o início
        </a>

    </div>


</div>


</body>

</html>