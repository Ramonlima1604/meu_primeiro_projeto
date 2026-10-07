<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

/*
|--------------------------------------------------------------------------
| Identificar a confeiteira logada
|--------------------------------------------------------------------------
*/

$usuario_id = $_SESSION["usuario_id"] ?? null;

if (!$usuario_id) {
    header("Location: ../login.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteira_id = (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| Buscar clientes ativos
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        telefone
    FROM clientes
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([$confeiteira_id]);

$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Buscar bolos disponíveis
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        categoria,
        tamanho,
        preco_venda
    FROM bolos
    WHERE confeiteira_id = ?
      AND disponivel = 1
    ORDER BY nome ASC
");

$stmt->execute([$confeiteira_id]);

$bolos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Salvar pedido
|--------------------------------------------------------------------------
*/

$erro = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    (
        !is_string($_POST["csrf_token"] ?? null) ||
        !hash_equals($csrf_token, $_POST["csrf_token"])
    )
) {
    $erro = "A sessão de segurança expirou. Atualize a página e tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {

    $cliente_id = (int) ($_POST["cliente_id"] ?? 0);

    $data_pedido = $_POST["data_pedido"] ?? date("Y-m-d");

    $data_entrega = !empty($_POST["data_entrega"])
        ? $_POST["data_entrega"]
        : null;

    $forma_pagamento = trim(
        $_POST["forma_pagamento"] ?? ""
    );

    $observacao = trim(
        $_POST["observacao"] ?? ""
    );

    $itens_post = $_POST["itens"] ?? [];


    /*
    |--------------------------------------------------------------------------
    | Validar cliente
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM clientes
        WHERE id = ?
          AND confeiteira_id = ?
          AND ativo = 1
        LIMIT 1
    ");

    $stmt->execute([
        $cliente_id,
        $confeiteira_id
    ]);

    $cliente_valido = $stmt->fetchColumn();

    if (!$cliente_valido) {
        $erro = "Selecione um cliente válido.";
    }


    /*
    |--------------------------------------------------------------------------
    | Preparar itens
    |--------------------------------------------------------------------------
    */

    $itens_validos = [];

    if (!$erro) {

        if (!is_array($itens_post) || count($itens_post) === 0) {

            $erro = "Adicione pelo menos um bolo ao pedido.";

        } else {

            foreach ($itens_post as $item) {

                $bolo_id = (int) (
                    $item["bolo_id"] ?? 0
                );

                $quantidade = (float) str_replace(
                    ",",
                    ".",
                    $item["quantidade"] ?? 0
                );

                if (
                    $bolo_id <= 0 ||
                    $quantidade <= 0
                ) {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | Buscar bolo garantindo que pertence à confeiteira
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        nome,
                        preco_venda
                    FROM bolos
                    WHERE id = ?
                      AND confeiteira_id = ?
                      AND disponivel = 1
                    LIMIT 1
                ");

                $stmt->execute([
                    $bolo_id,
                    $confeiteira_id
                ]);

                $bolo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$bolo) {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | Preço informado no pedido
                |
                | Permite alterar o preço de venda daquele pedido sem
                | alterar o cadastro original do bolo.
                |--------------------------------------------------------------------------
                */

                $preco_unitario =
                    $item["preco_unitario"] ?? "";

                $preco_unitario = str_replace(
                    ".",
                    "",
                    $preco_unitario
                );

                $preco_unitario = str_replace(
                    ",",
                    ".",
                    $preco_unitario
                );

                if ($preco_unitario === "") {
                    $preco_unitario = $bolo["preco_venda"];
                }

                $preco_unitario = (float) $preco_unitario;

                if ($preco_unitario < 0) {
                    $preco_unitario = 0;
                }


                /*
                |--------------------------------------------------------------------------
                | Subtotal
                |--------------------------------------------------------------------------
                */

                $subtotal =
                    $quantidade * $preco_unitario;


                /*
                |--------------------------------------------------------------------------
                | Observação do item
                |--------------------------------------------------------------------------
                */

                $observacao_item = trim(
                    $item["observacao"] ?? ""
                );


                $itens_validos[] = [
                    "bolo_id" => $bolo_id,
                    "quantidade" => $quantidade,
                    "preco_unitario" => $preco_unitario,
                    "subtotal" => $subtotal,
                    "observacao" => $observacao_item
                ];
            }


            if (count($itens_validos) === 0) {
                $erro =
                    "Adicione pelo menos um bolo válido ao pedido.";
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Calcular total
    |--------------------------------------------------------------------------
    */

    $valor_total = 0;

    foreach ($itens_validos as $item) {
        $valor_total += $item["subtotal"];
    }


    /*
    |--------------------------------------------------------------------------
    | Novo pedido começa sem recebimentos
    |--------------------------------------------------------------------------
    |
    | O valor recebido NÃO é mais informado no cadastro.
    |
    | Todo pagamento deverá ser registrado posteriormente através
    | de registrar_pagamento_pedido.php.
    |--------------------------------------------------------------------------
    */

    $valor_recebido = 0;

    $valor_restante = $valor_total;


    /*
    |--------------------------------------------------------------------------
    | Status inicial
    |--------------------------------------------------------------------------
    */

    $status = "orcamento";


    /*
    |--------------------------------------------------------------------------
    | Gravar pedido
    |--------------------------------------------------------------------------
    */

    if (!$erro) {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Número do pedido
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(numero_pedido), 0) + 1
                FROM pedidos
                WHERE confeiteira_id = ?
            ");

            $stmt->execute([
                $confeiteira_id
            ]);

            $numero_pedido = (int) $stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | Inserir pedido
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO pedidos (
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
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $confeiteira_id,
                $cliente_id,
                $numero_pedido,
                $data_pedido,
                $data_entrega,
                $status,
                $valor_total,
                0,
                $valor_restante,
                $forma_pagamento !== ""
                    ? $forma_pagamento
                    : null,
                $observacao !== ""
                    ? $observacao
                    : null
            ]);


            $pedido_id = (int) $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Inserir itens
            |--------------------------------------------------------------------------
            */

            $stmt_item = $pdo->prepare("
                INSERT INTO pedido_itens (
                    pedido_id,
                    bolo_id,
                    quantidade,
                    preco_unitario,
                    subtotal,
                    observacao
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($itens_validos as $item) {

                $stmt_item->execute([
                    $pedido_id,
                    $item["bolo_id"],
                    $item["quantidade"],
                    $item["preco_unitario"],
                    $item["subtotal"],
                    $item["observacao"] !== ""
                        ? $item["observacao"]
                        : null
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Finalizar transação
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Ir para visualização do pedido
            |--------------------------------------------------------------------------
            */

            header(
                "Location: visualizar_pedido.php?id=" .
                $pedido_id
            );

            exit;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erro =
                "Erro ao cadastrar o pedido: " .
                $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Dados usados para repopular o formulário
|--------------------------------------------------------------------------
*/

$cliente_selecionado = (int) (
    $_POST["cliente_id"] ?? 0
);

$data_pedido_form =
    $_POST["data_pedido"] ?? date("Y-m-d");

$data_entrega_form =
    $_POST["data_entrega"] ?? "";

$forma_pagamento_form =
    $_POST["forma_pagamento"] ?? "";

$observacao_form =
    $_POST["observacao"] ?? "";

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Cadastrar Pedido</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            background: #f4f5f7;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

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

        .botao-voltar {
            display: inline-block;
            padding: 11px 18px;
            background: #555;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow:
                0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 21px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .campo {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .campo-full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .erro {
            background: #ffe1e1;
            color: #a40000;
            border: 1px solid #ffb5b5;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .tabela-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 11px;
            border-bottom: 1px solid #e5e5e5;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f5f5f5;
            font-size: 14px;
        }

        .campo-item {
            min-width: 150px;
        }

        .campo-quantidade {
            width: 110px;
        }

        .campo-preco {
            width: 140px;
        }

        .campo-subtotal {
            width: 130px;
            font-weight: bold;
            white-space: nowrap;
        }

        .campo-observacao {
            min-width: 200px;
        }

        .botao {
            border: none;
            cursor: pointer;
            border-radius: 7px;
            padding: 11px 16px;
            font-weight: bold;
            font-size: 14px;
        }

        .botao-adicionar {
            background: #198754;
            color: #fff;
        }

        .botao-remover {
            background: #dc3545;
            color: #fff;
            padding: 9px 12px;
        }

        .botao-salvar {
            background: #0d6efd;
            color: #fff;
            font-size: 16px;
            padding: 14px 24px;
        }

        .acoes {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
        }

        .resumo {
            background: #f7f7f7;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }

        .resumo-linha {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 16px;
        }

        .resumo-total {
            font-size: 23px;
            font-weight: bold;
            border-top: 1px solid #ddd;
            margin-top: 8px;
            padding-top: 15px;
        }

        .restante {
            color: #dc3545;
            font-weight: bold;
        }

        .recebido {
            color: #198754;
            font-weight: bold;
        }

        .info-pagamento {
            margin-top: 15px;
            padding: 12px 15px;
            background: #e9f7ef;
            border: 1px solid #b7e4c7;
            color: #155724;
            border-radius: 8px;
            font-size: 14px;
        }

        .sem-itens {
            text-align: center;
            padding: 30px;
            color: #777;
        }

        @media (max-width: 800px) {

            body {
                padding: 15px;
            }

            .topo {
                flex-direction: column;
                align-items: stretch;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .campo-full {
                grid-column: auto;
            }

            .acoes {
                flex-direction: column;
                align-items: stretch;
            }

            .botao-salvar {
                width: 100%;
            }
        }

    </style>

    <link rel="stylesheet" href="../assets/pedidos.css">

</head>

<body>

<div class="container">

    <!-- =========================================================
         CABEÇALHO
    ========================================================== -->

    <div class="topo">

        <div>

            <h1>
                🧾 Novo Pedido
            </h1>

            <div>
                <?= htmlspecialchars(
                    $confeiteira["nome_confeitaria"]
                ) ?>
            </div>

        </div>

        <a
            href="pedidos.php"
            class="botao-voltar"
        >
            ← Voltar para pedidos
        </a>

    </div>


    <!-- =========================================================
         ERRO
    ========================================================== -->

    <?php if ($erro): ?>

        <div class="erro">
            <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>


    <form
        method="POST"
        id="formPedido"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
        >

        <!-- =====================================================
             DADOS DO PEDIDO
        ====================================================== -->

        <div class="card">

            <h2>
                👤 Dados do pedido
            </h2>

            <div class="grid">

                <div class="campo">

                    <label for="cliente_id">
                        Cliente *
                    </label>

                    <select
                        name="cliente_id"
                        id="cliente_id"
                        required
                    >

                        <option value="">
                            Selecione o cliente
                        </option>

                        <?php foreach ($clientes as $cliente): ?>

                            <option
                                value="<?= (int) $cliente["id"] ?>"
                                <?= $cliente_selecionado ===
                                    (int) $cliente["id"]
                                    ? "selected"
                                    : "" ?>
                            >

                                <?= htmlspecialchars(
                                    $cliente["nome"]
                                ) ?>

                                <?php if (!empty($cliente["telefone"])): ?>

                                    -
                                    <?= htmlspecialchars(
                                        $cliente["telefone"]
                                    ) ?>

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <?php if (count($clientes) === 0): ?>

                        <small>

                            Nenhum cliente cadastrado.

                            <a href="cadastrar_cliente.php">
                                Cadastrar cliente
                            </a>

                        </small>

                    <?php endif; ?>

                </div>


                <div class="campo">

                    <label for="data_pedido">
                        Data do pedido *
                    </label>

                    <input
                        type="date"
                        name="data_pedido"
                        id="data_pedido"
                        value="<?= htmlspecialchars(
                            $data_pedido_form
                        ) ?>"
                        required
                    >

                </div>


                <div class="campo">

                    <label for="data_entrega">
                        Data de entrega
                    </label>

                    <input
                        type="date"
                        name="data_entrega"
                        id="data_entrega"
                        value="<?= htmlspecialchars(
                            $data_entrega_form
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="forma_pagamento">
                        Forma de pagamento
                    </label>

                    <select
                        name="forma_pagamento"
                        id="forma_pagamento"
                    >

                        <option value="">
                            Não informado
                        </option>

                        <option
                            value="Dinheiro"
                            <?= $forma_pagamento_form ===
                                "Dinheiro"
                                ? "selected"
                                : "" ?>
                        >
                            Dinheiro
                        </option>

                        <option
                            value="Pix"
                            <?= $forma_pagamento_form ===
                                "Pix"
                                ? "selected"
                                : "" ?>
                        >
                            Pix
                        </option>

                        <option
                            value="Cartão de débito"
                            <?= $forma_pagamento_form ===
                                "Cartão de débito"
                                ? "selected"
                                : "" ?>
                        >
                            Cartão de débito
                        </option>

                        <option
                            value="Cartão de crédito"
                            <?= $forma_pagamento_form ===
                                "Cartão de crédito"
                                ? "selected"
                                : "" ?>
                        >
                            Cartão de crédito
                        </option>

                        <option
                            value="Transferência"
                            <?= $forma_pagamento_form ===
                                "Transferência"
                                ? "selected"
                                : "" ?>
                        >
                            Transferência
                        </option>

                        <option
                            value="A definir"
                            <?= $forma_pagamento_form ===
                                "A definir"
                                ? "selected"
                                : "" ?>
                        >
                            A definir
                        </option>

                    </select>

                </div>

            </div>

        </div>


        <!-- =====================================================
             ITENS DO PEDIDO
        ====================================================== -->

        <div class="card">

            <h2>
                🎂 Bolos do pedido
            </h2>

            <div class="acoes">

                <button
                    type="button"
                    class="botao botao-adicionar"
                    id="adicionarItem"
                >
                    + Adicionar bolo
                </button>

            </div>


            <div class="tabela-container">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Bolo
                            </th>

                            <th>
                                Quantidade
                            </th>

                            <th>
                                Preço unitário
                            </th>

                            <th>
                                Subtotal
                            </th>

                            <th>
                                Observação
                            </th>

                            <th>
                                Ação
                            </th>

                        </tr>

                    </thead>

                    <tbody id="itensPedido">
                    </tbody>

                </table>

            </div>


            <div
                class="sem-itens"
                id="mensagemSemItens"
            >
                Nenhum bolo adicionado.
            </div>


            <!-- =================================================
                 RESUMO
            ================================================== -->

            <div class="resumo">

                <div class="resumo-linha">

                    <span>
                        Total do pedido
                    </span>

                    <strong id="valorTotal">
                        R$ 0,00
                    </strong>

                </div>


                <div class="resumo-linha">

                    <span>
                        Valor recebido
                    </span>

                    <strong
                        id="valorRecebido"
                        class="recebido"
                    >
                        R$ 0,00
                    </strong>

                </div>


                <div class="resumo-linha resumo-total">

                    <span>
                        Valor restante
                    </span>

                    <strong
                        id="valorRestante"
                        class="restante"
                    >
                        R$ 0,00
                    </strong>

                </div>


                <div class="info-pagamento">

                    💳 Nenhum pagamento foi registrado neste momento.
                    Depois de salvar o pedido, você poderá registrar
                    pagamentos através da tela de visualização do pedido.

                </div>

            </div>

        </div>


        <!-- =====================================================
             OBSERVAÇÕES
        ====================================================== -->

        <div class="card">

            <h2>
                📝 Observações do pedido
            </h2>

            <div class="campo">

                <label for="observacao">
                    Observações
                </label>

                <textarea
                    name="observacao"
                    id="observacao"
                    placeholder="Ex.: bolo para aniversário, tema, horário combinado, detalhes especiais..."
                ><?= htmlspecialchars(
                    $observacao_form
                ) ?></textarea>

            </div>

        </div>


        <!-- =====================================================
             BOTÕES
        ====================================================== -->

        <div class="acoes">

            <a
                href="pedidos.php"
                class="botao botao-voltar"
            >
                Cancelar
            </a>

            <button
                type="submit"
                class="botao botao-salvar"
            >
                💾 Salvar pedido
            </button>

        </div>

    </form>

</div>


<!-- =============================================================
     JAVASCRIPT
============================================================== -->

<script>

const bolos = <?= json_encode(
    $bolos,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const itensPedido =
    document.getElementById("itensPedido");

const mensagemSemItens =
    document.getElementById("mensagemSemItens");

const adicionarItem =
    document.getElementById("adicionarItem");

const valorTotal =
    document.getElementById("valorTotal");

const valorRecebido =
    document.getElementById("valorRecebido");

const valorRestante =
    document.getElementById("valorRestante");


/*
|--------------------------------------------------------------------------
| Formatar moeda
|--------------------------------------------------------------------------
*/

function formatarMoeda(valor) {

    return Number(valor || 0).toLocaleString(
        "pt-BR",
        {
            style: "currency",
            currency: "BRL"
        }
    );
}


/*
|--------------------------------------------------------------------------
| Converter valor brasileiro para número
|--------------------------------------------------------------------------
*/

function converterNumero(valor) {

    if (!valor) {
        return 0;
    }

    valor = String(valor)
        .replace(/\s/g, "")
        .replace(/R\$/g, "")
        .replace(/\./g, "")
        .replace(",", ".");

    const numero = parseFloat(valor);

    return isNaN(numero)
        ? 0
        : numero;
}


/*
|--------------------------------------------------------------------------
| Criar opções dos bolos
|--------------------------------------------------------------------------
*/

function gerarOpcoesBolos() {

    let html = `
        <option value="">
            Selecione o bolo
        </option>
    `;

    bolos.forEach(function(bolo) {

        let detalhes = [];

        if (bolo.categoria) {
            detalhes.push(
                bolo.categoria
            );
        }

        if (bolo.tamanho) {
            detalhes.push(
                bolo.tamanho
            );
        }

        let descricao =
            detalhes.length
                ? " - " +
                  detalhes.join(" / ")
                : "";

        html += `
            <option
                value="${bolo.id}"
                data-preco="${bolo.preco_venda || 0}"
            >
                ${escapeHtml(bolo.nome)}
                ${escapeHtml(descricao)}
            </option>
        `;
    });

    return html;
}


/*
|--------------------------------------------------------------------------
| Escapar HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(texto) {

    const div =
        document.createElement("div");

    div.textContent =
        texto ?? "";

    return div.innerHTML;
}


/*
|--------------------------------------------------------------------------
| Adicionar item
|--------------------------------------------------------------------------
*/

function adicionarNovoItem(
    boloSelecionado = "",
    quantidade = 1,
    preco = "",
    observacao = ""
) {

    const indice =
        itensPedido.children.length;

    const linha =
        document.createElement("tr");

    linha.className =
        "item-pedido";

    linha.innerHTML = `

        <td class="campo-item">

            <select
                name="itens[${indice}][bolo_id]"
                class="bolo-select"
                required
            >
                ${gerarOpcoesBolos()}
            </select>

        </td>


        <td class="campo-quantidade">

            <input
                type="number"
                name="itens[${indice}][quantidade]"
                class="quantidade"
                value="${quantidade}"
                min="0.001"
                step="0.001"
                required
            >

        </td>


        <td class="campo-preco">

            <input
                type="text"
                name="itens[${indice}][preco_unitario]"
                class="preco"
                value="${escapeHtml(preco)}"
                placeholder="0,00"
                required
            >

        </td>


        <td class="campo-subtotal">

            <span class="subtotal">
                R$ 0,00
            </span>

        </td>


        <td class="campo-observacao">

            <input
                type="text"
                name="itens[${indice}][observacao]"
                value="${escapeHtml(observacao)}"
                placeholder="Observação"
            >

        </td>


        <td>

            <button
                type="button"
                class="botao botao-remover"
            >
                🗑️
            </button>

        </td>

    `;


    itensPedido.appendChild(linha);


    const select =
        linha.querySelector(
            ".bolo-select"
        );

    const campoPreco =
        linha.querySelector(
            ".preco"
        );


    if (boloSelecionado) {

        select.value =
            boloSelecionado;
    }


    /*
    |--------------------------------------------------------------------------
    | Preencher preço automaticamente
    |--------------------------------------------------------------------------
    */

    if (
        !preco &&
        select.value
    ) {

        const option =
            select.options[
                select.selectedIndex
            ];

        const precoBolo =
            option.dataset.preco || 0;

        campoPreco.value =
            Number(precoBolo).toLocaleString(
                "pt-BR",
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );
    }


    atualizarLinha(linha);
    atualizarResumo();
    atualizarEstado();
}


/*
|--------------------------------------------------------------------------
| Atualizar linha
|--------------------------------------------------------------------------
*/

function atualizarLinha(linha) {

    const quantidade =
        converterNumero(
            linha.querySelector(
                ".quantidade"
            ).value
        );

    const preco =
        converterNumero(
            linha.querySelector(
                ".preco"
            ).value
        );

    const subtotal =
        quantidade * preco;

    linha.querySelector(
        ".subtotal"
    ).textContent =
        formatarMoeda(subtotal);
}


/*
|--------------------------------------------------------------------------
| Atualizar resumo
|--------------------------------------------------------------------------
*/

function atualizarResumo() {

    let total = 0;

    document.querySelectorAll(
        ".item-pedido"
    ).forEach(function(linha) {

        const quantidade =
            converterNumero(
                linha.querySelector(
                    ".quantidade"
                ).value
            );

        const preco =
            converterNumero(
                linha.querySelector(
                    ".preco"
                ).value
            );

        total +=
            quantidade * preco;
    });


    /*
    |--------------------------------------------------------------------------
    | No cadastro o recebido é sempre zero.
    |--------------------------------------------------------------------------
    */

    const recebido = 0;

    let restante =
        total - recebido;

    if (restante < 0) {
        restante = 0;
    }


    valorTotal.textContent =
        formatarMoeda(total);

    valorRecebido.textContent =
        formatarMoeda(recebido);

    valorRestante.textContent =
        formatarMoeda(restante);
}


/*
|--------------------------------------------------------------------------
| Atualizar estado da tabela
|--------------------------------------------------------------------------
*/

function atualizarEstado() {

    const quantidade =
        itensPedido.children.length;

    if (quantidade === 0) {

        mensagemSemItens.style.display =
            "block";

    } else {

        mensagemSemItens.style.display =
            "none";
    }
}


/*
|--------------------------------------------------------------------------
| Quando alterar o bolo
|--------------------------------------------------------------------------
*/

itensPedido.addEventListener(
    "change",
    function(event) {

        if (
            event.target.classList.contains(
                "bolo-select"
            )
        ) {

            const linha =
                event.target.closest(
                    ".item-pedido"
                );

            const option =
                event.target.options[
                    event.target.selectedIndex
                ];

            const preco =
                option.dataset.preco || 0;

            const campoPreco =
                linha.querySelector(
                    ".preco"
                );


            campoPreco.value =
                Number(preco).toLocaleString(
                    "pt-BR",
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );


            atualizarLinha(linha);
            atualizarResumo();
        }
    }
);


/*
|--------------------------------------------------------------------------
| Alterações nos campos
|--------------------------------------------------------------------------
*/

itensPedido.addEventListener(
    "input",
    function(event) {

        const linha =
            event.target.closest(
                ".item-pedido"
            );

        if (!linha) {
            return;
        }

        atualizarLinha(linha);
        atualizarResumo();
    }
);


/*
|--------------------------------------------------------------------------
| Remover item
|--------------------------------------------------------------------------
*/

itensPedido.addEventListener(
    "click",
    function(event) {

        if (
            event.target.classList.contains(
                "botao-remover"
            )
        ) {

            const linha =
                event.target.closest(
                    ".item-pedido"
                );

            linha.remove();

            atualizarResumo();
            atualizarEstado();


            /*
            |--------------------------------------------------------------------------
            | Reindexar campos
            |--------------------------------------------------------------------------
            */

            document.querySelectorAll(
                ".item-pedido"
            ).forEach(function(linha, indice) {

                linha.querySelector(
                    ".bolo-select"
                ).name =
                    `itens[${indice}][bolo_id]`;

                linha.querySelector(
                    ".quantidade"
                ).name =
                    `itens[${indice}][quantidade]`;

                linha.querySelector(
                    ".preco"
                ).name =
                    `itens[${indice}][preco_unitario]`;

                linha.querySelector(
                    ".campo-observacao input"
                ).name =
                    `itens[${indice}][observacao]`;
            });
        }
    }
);


/*
|--------------------------------------------------------------------------
| Adicionar novo bolo
|--------------------------------------------------------------------------
*/

adicionarItem.addEventListener(
    "click",
    function() {

        adicionarNovoItem();

    }
);


/*
|--------------------------------------------------------------------------
| Calcular total
|--------------------------------------------------------------------------
*/

function calcularTotal() {

    let total = 0;

    document.querySelectorAll(
        ".item-pedido"
    ).forEach(function(linha) {

        const quantidade =
            converterNumero(
                linha.querySelector(
                    ".quantidade"
                ).value
            );

        const preco =
            converterNumero(
                linha.querySelector(
                    ".preco"
                ).value
            );

        total +=
            quantidade * preco;
    });

    return total;
}


/*
|--------------------------------------------------------------------------
| Inicialização
|--------------------------------------------------------------------------
*/

<?php if (
    !empty($_POST["itens"]) &&
    is_array($_POST["itens"])
): ?>

    <?php foreach ($_POST["itens"] as $item): ?>

        adicionarNovoItem(
            "<?= (int) ($item["bolo_id"] ?? 0) ?>",

            "<?= htmlspecialchars(
                $item["quantidade"] ?? 1,
                ENT_QUOTES
            ) ?>",

            "<?= htmlspecialchars(
                $item["preco_unitario"] ?? "",
                ENT_QUOTES
            ) ?>",

            "<?= htmlspecialchars(
                $item["observacao"] ?? "",
                ENT_QUOTES
            ) ?>"
        );

    <?php endforeach; ?>

<?php else: ?>

    adicionarNovoItem();

<?php endif; ?>


atualizarResumo();
atualizarEstado();

</script>

</body>

</html>
