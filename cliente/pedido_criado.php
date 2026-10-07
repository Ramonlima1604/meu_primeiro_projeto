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


/*
|--------------------------------------------------------------------------
| PEGA ID DO PEDIDO
|--------------------------------------------------------------------------
*/

$pedidoId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$pedidoId) {
    header("Location: inicio.php");
    exit;
}

$clienteId = (int) $_SESSION["cliente_id"];


/*
|--------------------------------------------------------------------------
| BUSCA PEDIDO
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.*,
        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        cf.nome_confeitaria,
        cf.telefone AS confeiteira_telefone,
        cf.cidade AS confeiteira_cidade
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

if (!$pedido) {
    header("Location: meus_pedidos.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCA ITENS DO PEDIDO
|--------------------------------------------------------------------------
*/

$sqlItens = "
    SELECT
        pi.*,
        b.nome AS bolo_nome
    FROM pedido_itens pi

    INNER JOIN bolos b
        ON b.id = pi.bolo_id

    WHERE pi.pedido_id = ?

    ORDER BY pi.id ASC
";

$stmtItens = $pdo->prepare($sqlItens);
$stmtItens->execute([$pedidoId]);

$itens = $stmtItens->fetchAll();


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusNomes = [
    "orcamento"  => "Aguardando confirmação",
    "confirmado" => "Pedido confirmado",
    "producao"   => "Em produção",
    "pronto"     => "Pronto para entrega",
    "entregue"   => "Entregue",
    "cancelado"  => "Cancelado"
];

$status = $pedido["status"];

$statusTexto = $statusNomes[$status] ?? ucfirst($status);


/*
|--------------------------------------------------------------------------
| FORMATAÇÕES
|--------------------------------------------------------------------------
*/

$numeroPedido = !empty($pedido["numero_pedido"])
    ? $pedido["numero_pedido"]
    : $pedido["id"];

$dataPedido = !empty($pedido["data_pedido"])
    ? date("d/m/Y", strtotime($pedido["data_pedido"]))
    : "-";

$dataEntrega = !empty($pedido["data_entrega"])
    ? date("d/m/Y", strtotime($pedido["data_entrega"]))
    : "A combinar";

$valorTotal = number_format(
    (float) $pedido["valor_total"],
    2,
    ",",
    "."
);

$formaPagamento = !empty($pedido["forma_pagamento"])
    ? $pedido["forma_pagamento"]
    : "Não informada";

$observacao = !empty($pedido["observacao"])
    ? $pedido["observacao"]
    : "";


/*
|--------------------------------------------------------------------------
| TOTAL DE ITENS
|--------------------------------------------------------------------------
*/

$totalItens = 0;

foreach ($itens as $item) {
    $totalItens += (float) $item["quantidade"];
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

    <title>Pedido realizado</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;

            font-family: Arial, Helvetica, sans-serif;

            background: #f5f6fa;

            color: #222;
        }

        .container {
            width: 100%;
            max-width: 900px;

            margin: 0 auto;

            padding: 30px 20px 50px;
        }

        .sucesso {
            background: #ffffff;

            border-radius: 16px;

            padding: 35px 25px;

            text-align: center;

            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);

            margin-bottom: 25px;
        }

        .icone {
            width: 75px;
            height: 75px;

            margin: 0 auto 20px;

            border-radius: 50%;

            background: #28a745;

            color: white;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 42px;
            font-weight: bold;
        }

        .sucesso h1 {
            margin: 0 0 10px;

            font-size: 30px;

            color: #222;
        }

        .sucesso p {
            margin: 5px 0;

            color: #666;

            font-size: 16px;
        }

        .numero {
            margin-top: 20px;

            font-size: 22px;

            font-weight: bold;

            color: #111;
        }

        .numero span {
            color: #28a745;
        }

        .card {
            background: #ffffff;

            border-radius: 16px;

            padding: 25px;

            margin-bottom: 20px;

            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            margin: 0 0 20px;

            font-size: 20px;

            border-bottom: 1px solid #eee;

            padding-bottom: 12px;
        }

        .informacoes {
            display: grid;

            grid-template-columns: repeat(2, 1fr);

            gap: 18px;
        }

        .info {
            background: #f8f9fa;

            border-radius: 10px;

            padding: 15px;
        }

        .info strong {
            display: block;

            font-size: 13px;

            color: #777;

            margin-bottom: 5px;

            text-transform: uppercase;
        }

        .info span {
            font-size: 16px;

            font-weight: 600;
        }

        .status {
            display: inline-block;

            padding: 8px 13px;

            border-radius: 20px;

            background: #fff3cd;

            color: #856404;

            font-size: 14px;

            font-weight: bold;
        }

        .item {
            display: flex;

            justify-content: space-between;

            gap: 20px;

            padding: 15px 0;

            border-bottom: 1px solid #eee;
        }

        .item:last-child {
            border-bottom: none;
        }

        .item-nome {
            font-weight: bold;

            margin-bottom: 5px;
        }

        .item-detalhes {
            color: #777;

            font-size: 14px;
        }

        .item-valor {
            text-align: right;

            white-space: nowrap;

            font-weight: bold;
        }

        .total {
            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-top: 20px;

            padding-top: 20px;

            border-top: 2px solid #eee;

            font-size: 22px;

            font-weight: bold;
        }

        .total span:last-child {
            color: #28a745;
        }

        .observacao {
            background: #fff8e1;

            border-left: 4px solid #ffc107;

            padding: 15px;

            border-radius: 6px;

            color: #555;

            line-height: 1.5;
        }

        .botoes {
            display: flex;

            gap: 12px;

            justify-content: center;

            flex-wrap: wrap;

            margin-top: 25px;
        }

        .botao {
            display: inline-block;

            padding: 13px 22px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

            transition: 0.2s;
        }

        .botao-principal {
            background: #222;

            color: white;
        }

        .botao-principal:hover {
            background: #000;
        }

        .botao-secundario {
            background: #e9ecef;

            color: #222;
        }

        .botao-secundario:hover {
            background: #dfe3e6;
        }

        @media (max-width: 650px) {

            .container {
                padding: 20px 12px 40px;
            }

            .sucesso {
                padding: 30px 18px;
            }

            .sucesso h1 {
                font-size: 25px;
            }

            .informacoes {
                grid-template-columns: 1fr;
            }

            .item {
                flex-direction: column;

                gap: 8px;
            }

            .item-valor {
                text-align: left;
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
    ============================================================
    CONFIRMAÇÃO
    ============================================================
    -->

    <div class="sucesso">

        <div class="icone">
            ✓
        </div>

        <h1>
            Pedido realizado com sucesso!
        </h1>

        <p>
            Seu pedido foi enviado para
            <strong>
                <?= htmlspecialchars($pedido["nome_confeitaria"]) ?>
            </strong>.
        </p>

        <p>
            Agora é só aguardar a confirmação da confeiteira.
        </p>

        <div class="numero">
            Pedido nº
            <span>
                <?= htmlspecialchars($numeroPedido) ?>
            </span>
        </div>

    </div>


    <!--
    ============================================================
    INFORMAÇÕES DO PEDIDO
    ============================================================
    -->

    <div class="card">

        <h2>
            Informações do pedido
        </h2>

        <div class="informacoes">

            <div class="info">

                <strong>
                    Confeitaria
                </strong>

                <span>
                    <?= htmlspecialchars($pedido["nome_confeitaria"]) ?>
                </span>

            </div>


            <div class="info">

                <strong>
                    Data do pedido
                </strong>

                <span>
                    <?= htmlspecialchars($dataPedido) ?>
                </span>

            </div>


            <div class="info">

                <strong>
                    Data desejada para entrega
                </strong>

                <span>
                    <?= htmlspecialchars($dataEntrega) ?>
                </span>

            </div>


            <div class="info">

                <strong>
                    Forma de pagamento
                </strong>

                <span>
                    <?= htmlspecialchars($formaPagamento) ?>
                </span>

            </div>


            <div class="info">

                <strong>
                    Status
                </strong>

                <span>

                    <span class="status">
                        <?= htmlspecialchars($statusTexto) ?>
                    </span>

                </span>

            </div>


            <div class="info">

                <strong>
                    Quantidade de itens
                </strong>

                <span>
                    <?= number_format($totalItens, 3, ",", ".") ?>
                </span>

            </div>

        </div>

    </div>


    <!--
    ============================================================
    ITENS DO PEDIDO
    ============================================================
    -->

    <div class="card">

        <h2>
            Itens do pedido
        </h2>


        <?php if (empty($itens)): ?>

            <p>
                Nenhum item encontrado neste pedido.
            </p>

        <?php else: ?>

            <?php foreach ($itens as $item): ?>

                <div class="item">

                    <div>

                        <div class="item-nome">

                            <?= htmlspecialchars($item["bolo_nome"]) ?>

                        </div>

                        <div class="item-detalhes">

                            Quantidade:
                            <?= number_format(
                                (float) $item["quantidade"],
                                3,
                                ",",
                                "."
                            ) ?>

                            <br>

                            Preço unitário:
                            R$
                            <?= number_format(
                                (float) $item["preco_unitario"],
                                2,
                                ",",
                                "."
                            ) ?>

                            <?php if (!empty($item["observacao"])): ?>

                                <br>

                                Observação:
                                <?= htmlspecialchars($item["observacao"]) ?>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="item-valor">

                        R$
                        <?= number_format(
                            (float) $item["subtotal"],
                            2,
                            ",",
                            "."
                        ) ?>

                    </div>

                </div>

            <?php endforeach; ?>


            <div class="total">

                <span>
                    Total do pedido
                </span>

                <span>
                    R$ <?= htmlspecialchars($valorTotal) ?>
                </span>

            </div>

        <?php endif; ?>

    </div>


    <!--
    ============================================================
    OBSERVAÇÃO
    ============================================================
    -->

    <?php if ($observacao !== ""): ?>

        <div class="card">

            <h2>
                Observação
            </h2>

            <div class="observacao">

                <?= nl2br(htmlspecialchars($observacao)) ?>

            </div>

        </div>

    <?php endif; ?>


    <!--
    ============================================================
    BOTÕES
    ============================================================
    -->

    <div class="botoes">

        <a
            href="meus_pedidos.php"
            class="botao botao-principal"
        >
            Ver meus pedidos
        </a>


        <a
            href="inicio.php"
            class="botao botao-secundario"
        >
            Voltar para o início
        </a>

    </div>


</div>

</body>

</html>