<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("confeiteira");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];


/*
|--------------------------------------------------------------------------
| IDENTIFICAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$usuario_id = $_SESSION["usuario_id"] ?? null;

if (!$usuario_id) {
    header("Location: ../login.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $usuario_id
]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Perfil da confeiteira não encontrado.");
}

$confeiteiraId = (int) $confeiteira["id"];


/*
|--------------------------------------------------------------------------
| STATUS PERMITIDOS
|--------------------------------------------------------------------------
*/

$statusPermitidos = [
    "orcamento",
    "confirmado",
    "producao",
    "pronto",
    "entregue",
    "cancelado"
];


/*
|--------------------------------------------------------------------------
| EXCLUIR PEDIDO
|--------------------------------------------------------------------------
|
| Regras:
|
| 1. O pedido precisa pertencer à confeiteira.
| 2. O CSRF precisa ser válido.
| 3. O pedido não pode possuir recebimentos.
| 4. Pedidos em produção não podem ser excluídos.
| 5. Pedidos prontos não podem ser excluídos.
| 6. Pedidos entregues não podem ser excluídos.
|
| A verificação e exclusão são feitas dentro de uma transação
| com FOR UPDATE para evitar corrida entre pagamento e exclusão.
|
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["excluir"])
) {

    $tokenRecebido = $_POST["csrf_token"] ?? "";

    if (
        !is_string($tokenRecebido) ||
        !hash_equals($csrf_token, $tokenRecebido)
    ) {
        header("Location: pedidos.php?erro=csrf");
        exit;
    }

    $pedidoId = filter_var(
        $_POST["excluir"],
        FILTER_VALIDATE_INT
    );

    if (!$pedidoId || $pedidoId <= 0) {
        header("Location: pedidos.php?erro=pedido_invalido");
        exit;
    }

    try {

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Travar pedido
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                status,
                valor_total
            FROM pedidos
            WHERE id = ?
              AND confeiteira_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $pedidoId,
            $confeiteiraId
        ]);

        $pedidoExcluir = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedidoExcluir) {

            $pdo->rollBack();

            header("Location: pedidos.php?erro=pedido_nao_encontrado");
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | Não permitir excluir pedidos em produção, prontos ou entregues
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $pedidoExcluir["status"],
                [
                    "producao",
                    "pronto",
                    "entregue"
                ],
                true
            )
        ) {

            $pdo->rollBack();

            header("Location: pedidos.php?erro=pedido_protegido");
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | Verificar recebimentos
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)
            FROM recebimentos
            WHERE pedido_id = ?
              AND confeiteira_id = ?
        ");

        $stmt->execute([
            $pedidoId,
            $confeiteiraId
        ]);

        $totalRecebimentos = (int) $stmt->fetchColumn();


        if ($totalRecebimentos > 0) {

            $pdo->rollBack();

            header(
                "Location: pedidos.php?erro=possui_recebimentos"
            );

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | Excluir itens
        |--------------------------------------------------------------------------
        |
        | Normalmente o FK já possui ON DELETE CASCADE.
        | Mesmo assim, fazemos a exclusão explicitamente caso a
        | estrutura do banco não esteja com CASCADE.
        |
        */

        $stmt = $pdo->prepare("
            DELETE FROM pedido_itens
            WHERE pedido_id = ?
        ");

        $stmt->execute([
            $pedidoId
        ]);


        /*
        |--------------------------------------------------------------------------
        | Excluir pedido
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            DELETE FROM pedidos
            WHERE id = ?
              AND confeiteira_id = ?
        ");

        $stmt->execute([
            $pedidoId,
            $confeiteiraId
        ]);


        /*
        |--------------------------------------------------------------------------
        | Finalizar
        |--------------------------------------------------------------------------
        */

        $pdo->commit();

        header("Location: pedidos.php?sucesso=pedido_excluido");
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            "Erro ao excluir pedido {$pedidoId}: " .
            $e->getMessage()
        );

        header("Location: pedidos.php?erro=exclusao");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$statusFiltro = trim(
    $_GET["status"] ?? ""
);

$busca = trim(
    $_GET["busca"] ?? ""
);

$dataInicio = trim(
    $_GET["data_inicio"] ?? ""
);

$dataFim = trim(
    $_GET["data_fim"] ?? ""
);


/*
|--------------------------------------------------------------------------
| VALIDAR STATUS DO FILTRO
|--------------------------------------------------------------------------
*/

if (
    $statusFiltro !== "" &&
    !in_array(
        $statusFiltro,
        $statusPermitidos,
        true
    )
) {

    $statusFiltro = "";
}


/*
|--------------------------------------------------------------------------
| FUNÇÃO PARA VALIDAR DATA
|--------------------------------------------------------------------------
*/

function dataValidaPedido($data)
{
    if (
        !is_string($data) ||
        $data === ""
    ) {
        return false;
    }

    $objeto = DateTime::createFromFormat(
        "Y-m-d",
        $data
    );

    return $objeto &&
        $objeto->format("Y-m-d") === $data;
}


/*
|--------------------------------------------------------------------------
| VALIDAR DATAS
|--------------------------------------------------------------------------
*/

if (
    $dataInicio !== "" &&
    !dataValidaPedido($dataInicio)
) {
    $dataInicio = "";
}

if (
    $dataFim !== "" &&
    !dataValidaPedido($dataFim)
) {
    $dataFim = "";
}


/*
|--------------------------------------------------------------------------
| SE DATA INICIAL FOR MAIOR QUE FINAL
|--------------------------------------------------------------------------
*/

if (
    $dataInicio !== "" &&
    $dataFim !== "" &&
    $dataInicio > $dataFim
) {

    $temp = $dataInicio;
    $dataInicio = $dataFim;
    $dataFim = $temp;
}


/*
|--------------------------------------------------------------------------
| BUSCAR PEDIDOS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id,
        p.confeiteira_id,
        p.cliente_id,
        p.numero_pedido,
        p.data_pedido,
        p.data_entrega,
        p.status,
        p.valor_total,
        p.valor_recebido,
        p.valor_restante,
        p.forma_pagamento,
        p.observacao,
        p.criado_em,
        p.atualizado_em,

        c.nome AS cliente_nome,
        c.telefone AS cliente_telefone,
        c.email AS cliente_email,

        COALESCE(
            (
                SELECT SUM(r.valor)
                FROM recebimentos r
                WHERE r.pedido_id = p.id
                  AND r.confeiteira_id = p.confeiteira_id
            ),
            0
        ) AS total_recebido_real,

        GREATEST(
            0,
            p.valor_total -
            COALESCE(
                (
                    SELECT SUM(r.valor)
                    FROM recebimentos r
                    WHERE r.pedido_id = p.id
                      AND r.confeiteira_id = p.confeiteira_id
                ),
                0
            )
        ) AS valor_restante_real,

        (
            SELECT COUNT(*)
            FROM pedido_itens pi
            WHERE pi.pedido_id = p.id
        ) AS total_itens

    FROM pedidos p

    INNER JOIN clientes c
        ON c.id = p.cliente_id
       AND c.confeiteira_id = p.confeiteira_id

    WHERE p.confeiteira_id = ?
";


$params = [
    $confeiteiraId
];


/*
|--------------------------------------------------------------------------
| BUSCA
|--------------------------------------------------------------------------
*/

if ($busca !== "") {

    $sql .= "
        AND (
            CAST(p.numero_pedido AS CHAR) LIKE ?
            OR c.nome LIKE ?
            OR c.telefone LIKE ?
            OR c.email LIKE ?
        )
    ";

    $termoBusca = "%" . $busca . "%";

    $params[] = $termoBusca;
    $params[] = $termoBusca;
    $params[] = $termoBusca;
    $params[] = $termoBusca;
}


/*
|--------------------------------------------------------------------------
| FILTRO STATUS
|--------------------------------------------------------------------------
*/

if ($statusFiltro !== "") {

    $sql .= "
        AND p.status = ?
    ";

    $params[] = $statusFiltro;
}


/*
|--------------------------------------------------------------------------
| FILTRO DATA INICIAL
|--------------------------------------------------------------------------
*/

if ($dataInicio !== "") {

    $sql .= "
        AND p.data_entrega >= ?
    ";

    $params[] = $dataInicio;
}


/*
|--------------------------------------------------------------------------
| FILTRO DATA FINAL
|--------------------------------------------------------------------------
*/

if ($dataFim !== "") {

    $sql .= "
        AND p.data_entrega <= ?
    ";

    $params[] = $dataFim;
}


/*
|--------------------------------------------------------------------------
| ORDENAÇÃO
|--------------------------------------------------------------------------
|
| 1. Cancelados ficam no final.
| 2. Pedidos com data de entrega aparecem primeiro.
| 3. Datas mais próximas aparecem primeiro.
| 4. Em caso de empate, pedido mais recente primeiro.
|
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY

        CASE
            WHEN p.status = 'cancelado' THEN 99
            ELSE 1
        END ASC,

        CASE
            WHEN p.data_entrega IS NULL THEN 1
            ELSE 0
        END ASC,

        p.data_entrega ASC,

        p.id DESC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| FUNÇÃO STATUS
|--------------------------------------------------------------------------
*/

function nomeStatusPedido($status)
{
    $nomes = [

        "orcamento" => "Orçamento",

        "confirmado" => "Confirmado",

        "producao" => "Em produção",

        "pronto" => "Pronto",

        "entregue" => "Entregue",

        "cancelado" => "Cancelado"

    ];

    return $nomes[$status] ?? ucfirst($status);
}


/*
|--------------------------------------------------------------------------
| CLASSE STATUS
|--------------------------------------------------------------------------
*/

function classeStatusPedido($status)
{
    switch ($status) {

        case "orcamento":
            return "status-orcamento";

        case "confirmado":
            return "status-confirmado";

        case "producao":
            return "status-producao";

        case "pronto":
            return "status-pronto";

        case "entregue":
            return "status-entregue";

        case "cancelado":
            return "status-cancelado";

        default:
            return "";
    }
}


/*
|--------------------------------------------------------------------------
| MENSAGENS
|--------------------------------------------------------------------------
*/

$mensagemErro = "";
$mensagemSucesso = "";

$erro = $_GET["erro"] ?? "";
$sucesso = $_GET["sucesso"] ?? "";


switch ($erro) {

    case "csrf":
        $mensagemErro =
            "A sessão de segurança expirou. Atualize a página e tente novamente.";
        break;

    case "pedido_invalido":
        $mensagemErro =
            "O pedido informado é inválido.";
        break;

    case "pedido_nao_encontrado":
        $mensagemErro =
            "O pedido não foi encontrado ou não pertence à sua confeitaria.";
        break;

    case "possui_recebimentos":
        $mensagemErro =
            "Não é possível excluir este pedido porque ele possui pagamentos registrados. Isso preserva o histórico financeiro.";
        break;

    case "pedido_protegido":
        $mensagemErro =
            "Este pedido não pode ser excluído porque já está em produção, pronto ou entregue.";
        break;

    case "exclusao":
        $mensagemErro =
            "Não foi possível excluir o pedido. Nenhuma alteração foi feita.";
        break;
}


if ($sucesso === "pedido_excluido") {

    $mensagemSucesso =
        "Pedido excluído com sucesso.";
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

    <title>Pedidos</title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

    <link
        rel="stylesheet"
        href="../assets/pedidos.css"
    >

    <style>

        .pedido-status {

            display: inline-block;

            padding: 6px 10px;

            border-radius: 6px;

            font-size: 13px;

            font-weight: bold;

        }

        .status-orcamento {

            background: #fff3cd;
            color: #856404;

        }

        .status-confirmado {

            background: #cfe2ff;
            color: #084298;

        }

        .status-producao {

            background: #e2d9f3;
            color: #432874;

        }

        .status-pronto {

            background: #cff4fc;
            color: #055160;

        }

        .status-entregue {

            background: #d1e7dd;
            color: #0f5132;

        }

        .status-cancelado {

            background: #f8d7da;
            color: #842029;

        }

        .pedido-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(280px, 1fr)
                );

            gap: 20px;

        }

        .pedido-card {

            border: 1px solid #ddd;

            border-radius: 10px;

            padding: 20px;

            background: #fff;

        }

        .pedido-card h3 {

            margin-top: 0;

            margin-bottom: 10px;

        }

        .pedido-info {

            margin: 8px 0;

        }

        .pedido-valores {

            margin-top: 15px;

            padding-top: 15px;

            border-top: 1px solid #eee;

        }

        .pedido-restante {

            font-weight: bold;

        }

        .pedido-pago {

            color: #198754;

            font-weight: bold;

        }

        .pedido-sem-recebimento {

            color: #dc3545;

            font-weight: bold;

        }

        .filtros {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(180px, 1fr)
                );

            gap: 12px;

            margin-bottom: 25px;

        }

        .filtros label {

            display: block;

            margin-bottom: 5px;

            font-weight: bold;

        }

        .acoes {

            display: flex;

            gap: 8px;

            flex-wrap: wrap;

            margin-top: 15px;

        }

        .mensagem {

            padding: 15px;

            border-radius: 8px;

            margin-bottom: 20px;

        }

        .mensagem-erro {

            background: #f8d7da;

            color: #842029;

            border: 1px solid #f1aeb5;

        }

        .mensagem-sucesso {

            background: #d1e7dd;

            color: #0f5132;

            border: 1px solid #a3cfbb;

        }

        .aviso-exclusao {

            margin-top: 12px;

            padding: 10px 12px;

            background: #f8f9fa;

            border-radius: 7px;

            font-size: 13px;

            color: #666;

        }

    </style>

</head>

<body>

<div class="admin-container">


    <!-- =========================================================
         TOPO
    ========================================================== -->

    <header class="topo">

        <div>

            <h1>
                <?= htmlspecialchars(
                    $confeiteira["nome_confeitaria"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </h1>

            <p>
                Pedidos
            </p>

        </div>

        <a href="index.php">
            Voltar ao painel
        </a>

    </header>


    <!-- =========================================================
         CONTEÚDO
    ========================================================== -->

    <main>


        <!-- =====================================================
             CABEÇALHO
        ====================================================== -->

        <div
            style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:15px;
                flex-wrap:wrap;
                margin-bottom:25px;
            "
        >

            <div>

                <h2>
                    🧾 Meus Pedidos
                </h2>

                <p>
                    Controle os pedidos e acompanhe os pagamentos.
                </p>

            </div>


            <a
                href="cadastrar_pedido.php"
                class="btn"
            >
                ➕ Novo Pedido
            </a>

        </div>


        <!-- =====================================================
             MENSAGEM DE ERRO
        ====================================================== -->

        <?php if ($mensagemErro !== ""): ?>

            <div class="mensagem mensagem-erro">

                <?= htmlspecialchars(
                    $mensagemErro,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             MENSAGEM DE SUCESSO
        ====================================================== -->

        <?php if ($mensagemSucesso !== ""): ?>

            <div class="mensagem mensagem-sucesso">

                <?= htmlspecialchars(
                    $mensagemSucesso,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FILTROS
        ====================================================== -->

        <div class="card">

            <h3>
                🔎 Filtros
            </h3>


            <form
                method="GET"
                action=""
            >

                <div class="filtros">


                    <!-- BUSCA -->

                    <div>

                        <label for="busca">
                            Buscar cliente ou número do pedido
                        </label>

                        <input
                            type="search"
                            id="busca"
                            name="busca"
                            value="<?= htmlspecialchars(
                                $busca,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            placeholder="Nome, telefone, e-mail ou número"
                        >

                    </div>


                    <!-- STATUS -->

                    <div>

                        <label for="status">
                            Status
                        </label>

                        <select
                            name="status"
                            id="status"
                        >

                            <option value="">
                                Todos
                            </option>

                            <option
                                value="orcamento"
                                <?= $statusFiltro === "orcamento"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Orçamento
                            </option>

                            <option
                                value="confirmado"
                                <?= $statusFiltro === "confirmado"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Confirmado
                            </option>

                            <option
                                value="producao"
                                <?= $statusFiltro === "producao"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Em produção
                            </option>

                            <option
                                value="pronto"
                                <?= $statusFiltro === "pronto"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Pronto
                            </option>

                            <option
                                value="entregue"
                                <?= $statusFiltro === "entregue"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Entregue
                            </option>

                            <option
                                value="cancelado"
                                <?= $statusFiltro === "cancelado"
                                    ? "selected"
                                    : ""
                                ?>
                            >
                                Cancelado
                            </option>

                        </select>

                    </div>


                    <!-- DATA INICIAL -->

                    <div>

                        <label for="data_inicio">
                            Entrega a partir de
                        </label>

                        <input
                            type="date"
                            name="data_inicio"
                            id="data_inicio"
                            value="<?= htmlspecialchars(
                                $dataInicio,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                    <!-- DATA FINAL -->

                    <div>

                        <label for="data_fim">
                            Entrega até
                        </label>

                        <input
                            type="date"
                            name="data_fim"
                            id="data_fim"
                            value="<?= htmlspecialchars(
                                $dataFim,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                    </div>


                </div>


                <div
                    style="
                        display:flex;
                        gap:10px;
                        flex-wrap:wrap;
                    "
                >

                    <button
                        type="submit"
                        class="btn"
                    >
                        🔎 Filtrar
                    </button>


                    <a
                        href="pedidos.php"
                        class="btn"
                    >
                        Limpar filtros
                    </a>

                </div>

            </form>

        </div>


        <br>


        <!-- =====================================================
             LISTA
        ====================================================== -->

        <?php if (!$pedidos): ?>

            <div class="card">

                <h3>
                    Nenhum pedido encontrado
                </h3>

                <p>
                    Cadastre seu primeiro pedido para começar
                    a controlar suas vendas.
                </p>

                <br>

                <a
                    href="cadastrar_pedido.php"
                    class="btn"
                >
                    ➕ Cadastrar Pedido
                </a>

            </div>

        <?php else: ?>


            <div class="pedido-grid">


                <?php foreach ($pedidos as $pedido): ?>

                    <?php

                    $totalPedido = (float) (
                        $pedido["valor_total"] ?? 0
                    );

                    $totalRecebido = (float) (
                        $pedido["total_recebido_real"] ?? 0
                    );

                    $totalRestante = (float) (
                        $pedido["valor_restante_real"] ?? 0
                    );

                    $pedidoPago =
                        $totalPedido > 0 &&
                        $totalRestante <= 0;

                    $podeExcluir =
                        !in_array(
                            $pedido["status"],
                            [
                                "producao",
                                "pronto",
                                "entregue"
                            ],
                            true
                        ) &&
                        $totalRecebido <= 0;

                    ?>


                    <div class="pedido-card">


                        <!-- NÚMERO -->

                        <h3>

                            Pedido #

                            <?= (int) $pedido["numero_pedido"] ?>

                        </h3>


                        <!-- STATUS -->

                        <span
                            class="
                                pedido-status
                                <?= htmlspecialchars(
                                    classeStatusPedido(
                                        $pedido["status"]
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            "
                        >

                            <?= htmlspecialchars(
                                nomeStatusPedido(
                                    $pedido["status"]
                                ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>


                        <!-- CLIENTE -->

                        <div class="pedido-info">

                            <strong>
                                Cliente:
                            </strong>

                            <?= htmlspecialchars(
                                $pedido["cliente_nome"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </div>


                        <!-- DATA DO PEDIDO -->

                        <div class="pedido-info">

                            <strong>
                                Pedido:
                            </strong>

                            <?php if (
                                !empty(
                                    $pedido["data_pedido"]
                                )
                            ): ?>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $pedido["data_pedido"]
                                    )
                                ) ?>

                            <?php else: ?>

                                Não definida

                            <?php endif; ?>

                        </div>


                        <!-- DATA DE ENTREGA -->

                        <div class="pedido-info">

                            <strong>
                                Entrega:
                            </strong>

                            <?php if (
                                !empty(
                                    $pedido["data_entrega"]
                                )
                            ): ?>

                                <?= date(
                                    "d/m/Y",
                                    strtotime(
                                        $pedido["data_entrega"]
                                    )
                                ) ?>

                            <?php else: ?>

                                Não definida

                            <?php endif; ?>

                        </div>


                        <!-- ITENS -->

                        <div class="pedido-info">

                            <strong>
                                Itens:
                            </strong>

                            <?= (int) $pedido["total_itens"] ?>

                        </div>


                        <!-- VALORES -->

                        <div class="pedido-valores">


                            <!-- TOTAL -->

                            <div class="pedido-info">

                                <strong>
                                    Total:
                                </strong>

                                R$

                                <?= number_format(
                                    $totalPedido,
                                    2,
                                    ",",
                                    "."
                                ) ?>

                            </div>


                            <!-- RECEBIDO -->

                            <div class="pedido-info">

                                <strong>
                                    Recebido:
                                </strong>

                                R$

                                <?= number_format(
                                    $totalRecebido,
                                    2,
                                    ",",
                                    "."
                                ) ?>

                            </div>


                            <!-- RESTANTE / PAGO -->

                            <?php if ($pedidoPago): ?>

                                <div class="pedido-info pedido-pago">

                                    <strong>
                                        Situação financeira:
                                    </strong>

                                    Pago

                                </div>

                            <?php else: ?>

                                <div class="pedido-info pedido-restante">

                                    <strong>
                                        Restante:
                                    </strong>

                                    R$

                                    <?= number_format(
                                        $totalRestante,
                                        2,
                                        ",",
                                        "."
                                    ) ?>

                                </div>

                            <?php endif; ?>


                        </div>


                        <!-- AÇÕES -->

                        <div class="acoes">


                            <!-- VISUALIZAR -->

                            <a
                                href="visualizar_pedido.php?id=<?= (int) $pedido["id"] ?>"
                                class="btn"
                            >
                                👁️ Ver
                            </a>


                            <!-- EDITAR -->

                            <a
                                href="editar_pedido.php?id=<?= (int) $pedido["id"] ?>"
                                class="btn"
                            >
                                ✏️ Editar
                            </a>


                            <!-- EXCLUIR -->

                            <?php if ($podeExcluir): ?>

                                <form
                                    method="POST"
                                    action="pedidos.php"
                                    style="
                                        display:inline;
                                        margin:0;
                                    "
                                    onsubmit="
                                        return confirm(
                                            'Tem certeza que deseja excluir este pedido? Esta ação não poderá ser desfeita.'
                                        );
                                    "
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $csrf_token,
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="excluir"
                                        value="<?= (int) $pedido["id"] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn botao-remover"
                                    >
                                        🗑️ Excluir
                                    </button>

                                </form>

                            <?php endif; ?>


                        </div>


                        <!-- AVISO -->

                        <?php if (
                            !$podeExcluir &&
                            $totalRecebido > 0
                        ): ?>

                            <div class="aviso-exclusao">

                                🔒 Este pedido possui pagamento
                                registrado e não pode ser excluído.

                            </div>

                        <?php elseif (
                            !$podeExcluir &&
                            in_array(
                                $pedido["status"],
                                [
                                    "producao",
                                    "pronto",
                                    "entregue"
                                ],
                                true
                            )
                        ): ?>

                            <div class="aviso-exclusao">

                                🔒 Este pedido está em uma etapa
                                protegida e não pode ser excluído.

                            </div>

                        <?php endif; ?>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php endif; ?>


    </main>

</div>

</body>

</html>