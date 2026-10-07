<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

exigirConfeiteira();

$mensagem = '';
$erro = '';

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_composicao_receita'])) {
    $_SESSION['csrf_composicao_receita'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_composicao_receita'];

/*
|--------------------------------------------------------------------------
| Localiza a confeiteira logada
|--------------------------------------------------------------------------
*/

$usuario_id = (int) $_SESSION['usuario_id'];

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteira_id = (int) $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| Valida receita
|--------------------------------------------------------------------------
*/

$receita_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$receita_id || $receita_id <= 0) {
    header("Location: receitas.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Busca receita pertencente à confeiteira
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        descricao,
        rendimento,
        unidade_rendimento,
        ativo
    FROM receitas
    WHERE id = ?
      AND confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $receita_id,
    $confeiteira_id
]);

$receita = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receita) {
    header("Location: receitas.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Tipos de receita
|--------------------------------------------------------------------------
*/

$nomes_tipos = [
    'massa' => 'Massa',
    'recheio' => 'Recheio',
    'cobertura' => 'Cobertura',
    'decoracao' => 'Decoração',
    'outros' => 'Outros'
];

/*
|--------------------------------------------------------------------------
| Validação CSRF
|--------------------------------------------------------------------------
*/

function validarCsrfComposicaoReceita($token)
{
    if (
        empty($_SESSION['csrf_composicao_receita'])
        || empty($token)
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['csrf_composicao_receita'],
        $token
    );
}

/*
|--------------------------------------------------------------------------
| ADICIONAR INSUMO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['adicionar_insumo'])
) {

    if (
        !validarCsrfComposicaoReceita(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $erro = "Sessão de segurança inválida. Atualize a página e tente novamente.";

    } else {

        $insumo_id = filter_var(
            $_POST['insumo_id'] ?? 0,
            FILTER_VALIDATE_INT
        );

        $quantidade = str_replace(
            ',',
            '.',
            trim($_POST['quantidade'] ?? '')
        );

        if (!$insumo_id || $insumo_id <= 0) {

            $erro = "Selecione um insumo.";

        } elseif (
            $quantidade === ''
            || !is_numeric($quantidade)
        ) {

            $erro = "Informe uma quantidade válida.";

        } elseif ((float)$quantidade <= 0) {

            $erro = "A quantidade deve ser maior que zero.";

        } else {

            $quantidade = (float)$quantidade;

            try {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------
                | Confirma que o insumo pertence à confeiteira
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        nome,
                        tipo,
                        unidade,
                        custo_unitario,
                        ativo
                    FROM insumos
                    WHERE id = ?
                      AND confeiteira_id = ?
                      AND ativo = 1
                    LIMIT 1
                ");

                $stmt->execute([
                    $insumo_id,
                    $confeiteira_id
                ]);

                $insumo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$insumo) {

                    throw new Exception(
                        "Insumo não encontrado ou não está disponível."
                    );
                }

                /*
                |--------------------------------------------------------------
                | Confirma novamente que a receita pertence à confeiteira
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM receitas
                    WHERE id = ?
                      AND confeiteira_id = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $receita_id,
                    $confeiteira_id
                ]);

                if (!$stmt->fetch()) {

                    throw new Exception(
                        "Receita não encontrada."
                    );
                }

                /*
                |--------------------------------------------------------------
                | Procura composição existente
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        quantidade
                    FROM receita_insumos
                    WHERE receita_id = ?
                      AND insumo_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $receita_id,
                    $insumo_id
                ]);

                $existente = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existente) {

                    $nova_quantidade =
                        (float)$existente['quantidade']
                        + $quantidade;

                    $stmt = $pdo->prepare("
                        UPDATE receita_insumos
                        SET quantidade = ?
                        WHERE id = ?
                          AND receita_id = ?
                    ");

                    $stmt->execute([
                        $nova_quantidade,
                        $existente['id'],
                        $receita_id
                    ]);

                    $mensagem =
                        "Quantidade do insumo atualizada com sucesso.";

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO receita_insumos
                        (
                            receita_id,
                            insumo_id,
                            quantidade
                        )
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        $receita_id,
                        $insumo_id,
                        $quantidade
                    ]);

                    $mensagem =
                        "Insumo adicionado à receita com sucesso.";
                }

                $pdo->commit();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $erro = $e->getMessage();

                if ($erro === '') {
                    $erro = "Não foi possível adicionar o insumo.";
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| REMOVER INSUMO
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['remover_insumo'])
) {

    if (
        !validarCsrfComposicaoReceita(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $erro =
            "Sessão de segurança inválida. Atualize a página e tente novamente.";

    } else {

        $receita_insumo_id = filter_var(
            $_POST['receita_insumo_id'] ?? 0,
            FILTER_VALIDATE_INT
        );

        if (
            !$receita_insumo_id
            || $receita_insumo_id <= 0
        ) {

            $erro = "Item da receita inválido.";

        } else {

            try {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------
                | Confirma que o item pertence à receita
                | e que o insumo pertence à confeiteira
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ri.id
                    FROM receita_insumos ri

                    INNER JOIN receitas r
                        ON r.id = ri.receita_id

                    INNER JOIN insumos i
                        ON i.id = ri.insumo_id

                    WHERE ri.id = ?
                      AND ri.receita_id = ?
                      AND r.confeiteira_id = ?
                      AND i.confeiteira_id = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $receita_insumo_id,
                    $receita_id,
                    $confeiteira_id,
                    $confeiteira_id
                ]);

                if (!$stmt->fetch()) {

                    throw new Exception(
                        "Item não encontrado ou não pertence a esta receita."
                    );
                }

                /*
                |--------------------------------------------------------------
                | Remove
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    DELETE FROM receita_insumos
                    WHERE id = ?
                      AND receita_id = ?
                ");

                $stmt->execute([
                    $receita_insumo_id,
                    $receita_id
                ]);

                if ($stmt->rowCount() <= 0) {

                    throw new Exception(
                        "Não foi possível remover o insumo."
                    );
                }

                $pdo->commit();

                $mensagem =
                    "Insumo removido da receita.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $erro = $e->getMessage();

                if ($erro === '') {
                    $erro =
                        "Não foi possível remover o insumo.";
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ATUALIZAR QUANTIDADE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['atualizar_quantidade'])
) {

    if (
        !validarCsrfComposicaoReceita(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $erro =
            "Sessão de segurança inválida. Atualize a página e tente novamente.";

    } else {

        $receita_insumo_id = filter_var(
            $_POST['receita_insumo_id'] ?? 0,
            FILTER_VALIDATE_INT
        );

        $quantidade = str_replace(
            ',',
            '.',
            trim($_POST['quantidade_atualizada'] ?? '')
        );

        if (
            !$receita_insumo_id
            || $receita_insumo_id <= 0
        ) {

            $erro = "Item da receita inválido.";

        } elseif (
            $quantidade === ''
            || !is_numeric($quantidade)
            || (float)$quantidade <= 0
        ) {

            $erro = "Informe uma quantidade válida.";

        } else {

            $quantidade = (float)$quantidade;

            try {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------
                | Confirma propriedade completa do item
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ri.id
                    FROM receita_insumos ri

                    INNER JOIN receitas r
                        ON r.id = ri.receita_id

                    INNER JOIN insumos i
                        ON i.id = ri.insumo_id

                    WHERE ri.id = ?
                      AND ri.receita_id = ?
                      AND r.confeiteira_id = ?
                      AND i.confeiteira_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $receita_insumo_id,
                    $receita_id,
                    $confeiteira_id,
                    $confeiteira_id
                ]);

                if (!$stmt->fetch()) {

                    throw new Exception(
                        "Item não encontrado."
                    );
                }

                /*
                |--------------------------------------------------------------
                | Atualiza quantidade
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE receita_insumos
                    SET quantidade = ?
                    WHERE id = ?
                      AND receita_id = ?
                ");

                $stmt->execute([
                    $quantidade,
                    $receita_insumo_id,
                    $receita_id
                ]);

                $pdo->commit();

                $mensagem =
                    "Quantidade atualizada com sucesso.";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $erro = $e->getMessage();

                if ($erro === '') {
                    $erro =
                        "Não foi possível atualizar a quantidade.";
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Busca insumos disponíveis
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        tipo,
        unidade,
        quantidade_embalagem,
        preco_embalagem,
        custo_unitario,
        estoque,
        estoque_minimo
    FROM insumos
    WHERE confeiteira_id = ?
      AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([
    $confeiteira_id
]);

$insumos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Busca composição da receita
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ri.id AS receita_insumo_id,
        ri.quantidade,

        i.id AS insumo_id,
        i.nome,
        i.tipo,
        i.unidade,
        i.quantidade_embalagem,
        i.preco_embalagem,
        i.custo_unitario,
        i.estoque,
        i.estoque_minimo,

        (
            ri.quantidade * i.custo_unitario
        ) AS custo_item

    FROM receita_insumos ri

    INNER JOIN receitas r
        ON r.id = ri.receita_id

    INNER JOIN insumos i
        ON i.id = ri.insumo_id

    WHERE ri.receita_id = ?
      AND r.confeiteira_id = ?
      AND i.confeiteira_id = ?

    ORDER BY
        i.tipo ASC,
        i.nome ASC
");

$stmt->execute([
    $receita_id,
    $confeiteira_id,
    $confeiteira_id
]);

$composicao = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Calcula custo total
|--------------------------------------------------------------------------
*/

$custo_total = 0;

foreach ($composicao as $item) {

    $custo_total += (float)$item['custo_item'];
}

/*
|--------------------------------------------------------------------------
| Agrupamento por tipo
|--------------------------------------------------------------------------
*/

$grupos = [
    'ingrediente' => [],
    'material' => [],
    'embalagem' => [],
    'decoracao' => [],
    'outros' => []
];

foreach ($composicao as $item) {

    $tipo = $item['tipo'];

    if (!isset($grupos[$tipo])) {

        $grupos['outros'][] = $item;

    } else {

        $grupos[$tipo][] = $item;
    }
}

$nomes_tipos_insumos = [
    'ingrediente' => '🥣 Ingredientes',
    'material' => '🧰 Materiais',
    'embalagem' => '📦 Embalagens',
    'decoracao' => '🎀 Decoração',
    'outros' => '📌 Outros'
];

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
        Composição da Receita -
        <?= htmlspecialchars($receita['nome']) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f6f8;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 25px 20px 50px;
        }

        .topo {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .topo h1 {
            margin: 0 0 7px;
            font-size: 28px;
        }

        .topo p {
            margin: 0;
            color: #666;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-block;
            border: 0;
            border-radius: 8px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-voltar {
            background: #e9ecef;
            color: #222;
        }

        .btn-editar {
            background: #6f42c1;
            color: #fff;
        }

        .btn-adicionar {
            background: #198754;
            color: #fff;
        }

        .btn-remover {
            background: #dc3545;
            color: #fff;
        }

        .btn-atualizar {
            background: #0d6efd;
            color: #fff;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .card h2 {
            margin: 0 0 18px;
            font-size: 21px;
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 15px;
        }

        .campo {
            display: flex;
            flex-direction: column;
        }

        .campo label {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 7px;
        }

        input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #6f42c1;
            box-shadow: 0 0 0 2px rgba(111,66,193,0.1);
        }

        .info-receita {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .info {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 9px;
            padding: 13px;
        }

        .info-titulo {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .info-valor {
            font-size: 17px;
            font-weight: bold;
        }

        .mensagem {
            border-radius: 8px;
            padding: 13px 15px;
            margin-bottom: 20px;
        }

        .sucesso {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
        }

        .erro {
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
        }

        .tabela-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th {
            background: #f8f9fa;
            text-align: left;
            padding: 12px 10px;
            font-size: 13px;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 11px 10px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        tr:hover td {
            background: #fafafa;
        }

        .grupo-titulo {
            margin: 25px 0 10px;
            font-size: 18px;
        }

        .grupo-titulo:first-child {
            margin-top: 0;
        }

        .quantidade-form {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .quantidade-form input {
            width: 110px;
        }

        .unidade {
            color: #666;
            font-size: 13px;
            white-space: nowrap;
        }

        .custo {
            font-weight: bold;
            white-space: nowrap;
        }

        .estoque-ok {
            color: #198754;
            font-weight: bold;
        }

        .estoque-baixo {
            color: #dc3545;
            font-weight: bold;
        }

        .resumo {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .resumo-item {
            border-radius: 10px;
            padding: 18px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }

        .resumo-item.destaque {
            background: #212529;
            color: #fff;
        }

        .resumo-titulo {
            font-size: 13px;
            opacity: .75;
            margin-bottom: 7px;
        }

        .resumo-valor {
            font-size: 25px;
            font-weight: bold;
        }

        .vazio {
            text-align: center;
            padding: 35px 15px;
            color: #777;
        }

        .observacao {
            margin-top: 18px;
            padding: 14px;
            border-radius: 8px;
            background: #fff3cd;
            border: 1px solid #ffecb5;
            color: #664d03;
            line-height: 1.5;
            font-size: 14px;
        }

        .acoes-tabela {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .acoes-tabela form {
            margin: 0;
        }

        .btn-pequeno {
            padding: 8px 11px;
            font-size: 13px;
        }

        @media (max-width: 800px) {

            .grid {
                grid-template-columns: 1fr;
            }

            .info-receita {
                grid-template-columns: repeat(2, 1fr);
            }

            .resumo {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 500px) {

            .info-receita {
                grid-template-columns: 1fr;
            }

            .acoes {
                flex-direction: column;
            }

            .acoes .btn {
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <h1>
            🧪 Composição da Receita
        </h1>

        <p>
            Receita:
            <strong>
                <?= htmlspecialchars($receita['nome']) ?>
            </strong>
        </p>

        <div class="acoes">

            <a
                href="receitas.php"
                class="btn btn-voltar"
            >
                ← Voltar para receitas
            </a>

            <a
                href="editar_receita.php?id=<?= $receita_id ?>"
                class="btn btn-editar"
            >
                ✏️ Editar receita
            </a>

        </div>

        <div class="info-receita">

            <div class="info">

                <div class="info-titulo">
                    Tipo
                </div>

                <div class="info-valor">

                    <?= htmlspecialchars(
                        $nomes_tipos[$receita['tipo']]
                        ?? 'Outros'
                    ) ?>

                </div>

            </div>


            <div class="info">

                <div class="info-titulo">
                    Rendimento
                </div>

                <div class="info-valor">

                    <?php if (
                        (float)$receita['rendimento'] > 0
                        && !empty($receita['unidade_rendimento'])
                    ): ?>

                        <?= number_format(
                            (float)$receita['rendimento'],
                            3,
                            ',',
                            '.'
                        ) ?>

                        <?= htmlspecialchars(
                            $receita['unidade_rendimento']
                        ) ?>

                    <?php else: ?>

                        Não informado

                    <?php endif; ?>

                </div>

            </div>


            <div class="info">

                <div class="info-titulo">
                    Insumos
                </div>

                <div class="info-valor">
                    <?= count($composicao) ?>
                </div>

            </div>


            <div class="info">

                <div class="info-titulo">
                    Custo da receita
                </div>

                <div class="info-valor">
                    R$ <?= number_format(
                        $custo_total,
                        2,
                        ',',
                        '.'
                    ) ?>
                </div>

            </div>

        </div>

    </div>


    <?php if ($mensagem !== ''): ?>

        <div class="mensagem sucesso">
            ✅ <?= htmlspecialchars($mensagem) ?>
        </div>

    <?php endif; ?>


    <?php if ($erro !== ''): ?>

        <div class="mensagem erro">
            ❌ <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>


    <!-- =========================================================
         ADICIONAR INSUMO
         ========================================================= -->

    <div class="card">

        <h2>
            ➕ Adicionar Insumo
        </h2>

        <?php if (count($insumos_disponiveis) === 0): ?>

            <div class="vazio">

                Nenhum insumo ativo foi cadastrado.

                <br><br>

                <a
                    href="cadastrar_insumo.php"
                    class="btn btn-adicionar"
                >
                    ➕ Cadastrar insumo
                </a>

            </div>

        <?php else: ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf_token) ?>"
                >

                <div class="grid">

                    <div class="campo">

                        <label for="insumo_id">
                            Insumo
                        </label>

                        <select
                            name="insumo_id"
                            id="insumo_id"
                            required
                        >

                            <option value="">
                                Selecione um insumo
                            </option>

                            <?php foreach (
                                $insumos_disponiveis
                                as $insumo
                            ): ?>

                                <option
                                    value="<?= (int)$insumo['id'] ?>"
                                    data-unidade="<?= htmlspecialchars(
                                        $insumo['unidade'] ?? ''
                                    ) ?>"
                                    data-custo="<?= htmlspecialchars(
                                        $insumo['custo_unitario'] ?? 0
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $insumo['nome']
                                    ) ?>

                                    -
                                    <?= htmlspecialchars(
                                        $insumo['unidade'] ?? ''
                                    ) ?>

                                    -
                                    R$
                                    <?= number_format(
                                        (float)$insumo['custo_unitario'],
                                        4,
                                        ',',
                                        '.'
                                    ) ?>

                                    / unidade

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="campo">

                        <label for="quantidade">
                            Quantidade utilizada
                        </label>

                        <input
                            type="number"
                            name="quantidade"
                            id="quantidade"
                            min="0.001"
                            step="0.001"
                            placeholder="Ex.: 500"
                            required
                        >

                        <div
                            id="unidadeSelecionada"
                            style="
                                margin-top:6px;
                                color:#666;
                                font-size:13px;
                            "
                        >
                            Unidade: -
                        </div>

                    </div>

                </div>


                <div
                    style="
                        margin-top:18px;
                        display:flex;
                        justify-content:flex-end;
                    "
                >

                    <button
                        type="submit"
                        name="adicionar_insumo"
                        value="1"
                        class="btn btn-adicionar"
                    >
                        ➕ Adicionar à receita
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         COMPOSIÇÃO
         ========================================================= -->

    <div class="card">

        <h2>
            🧾 Insumos utilizados na receita
        </h2>

        <?php if (count($composicao) === 0): ?>

            <div class="vazio">

                Esta receita ainda não possui insumos.

                <br><br>

                Adicione os ingredientes, materiais,
                embalagens ou itens de decoração utilizados.

            </div>

        <?php else: ?>


            <?php foreach ($grupos as $tipo => $itens): ?>

                <?php if (count($itens) === 0): ?>
                    <?php continue; ?>
                <?php endif; ?>


                <h3 class="grupo-titulo">

                    <?= htmlspecialchars(
                        $nomes_tipos_insumos[$tipo]
                        ?? '📌 Outros'
                    ) ?>

                </h3>


                <div class="tabela-wrapper">

                    <table>

                        <thead>

                        <tr>

                            <th>
                                Insumo
                            </th>

                            <th>
                                Unidade
                            </th>

                            <th>
                                Quantidade
                            </th>

                            <th>
                                Custo unitário
                            </th>

                            <th>
                                Custo na receita
                            </th>

                            <th>
                                Estoque
                            </th>

                            <th>
                                Ações
                            </th>

                        </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($itens as $item): ?>

                            <?php

                            $estoque =
                                (float)$item['estoque'];

                            $estoque_minimo =
                                (float)$item['estoque_minimo'];

                            $estoque_baixo =
                                $estoque <= $estoque_minimo;

                            ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $item['nome']
                                        ) ?>
                                    </strong>

                                </td>


                                <td>

                                    <span class="unidade">

                                        <?= htmlspecialchars(
                                            $item['unidade']
                                            ?: '-'
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <form
                                        method="POST"
                                        class="quantidade-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($csrf_token) ?>"
                                        >

                                        <input
                                            type="number"
                                            name="quantidade_atualizada"
                                            value="<?= htmlspecialchars(
                                                number_format(
                                                    (float)$item['quantidade'],
                                                    3,
                                                    '.',
                                                    ''
                                                )
                                            ) ?>"
                                            min="0.001"
                                            step="0.001"
                                            required
                                        >

                                        <input
                                            type="hidden"
                                            name="receita_insumo_id"
                                            value="<?= (int)$item['receita_insumo_id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="atualizar_quantidade"
                                            value="1"
                                            class="btn btn-atualizar"
                                            title="Atualizar quantidade"
                                        >
                                            💾
                                        </button>

                                    </form>

                                </td>


                                <td class="custo">

                                    R$
                                    <?= number_format(
                                        (float)$item['custo_unitario'],
                                        4,
                                        ',',
                                        '.'
                                    ) ?>

                                </td>


                                <td class="custo">

                                    R$
                                    <?= number_format(
                                        (float)$item['custo_item'],
                                        2,
                                        ',',
                                        '.'
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="<?= $estoque_baixo
                                            ? 'estoque-baixo'
                                            : 'estoque-ok' ?>"
                                    >

                                        <?= number_format(
                                            $estoque,
                                            3,
                                            ',',
                                            '.'
                                        ) ?>

                                        <?= htmlspecialchars(
                                            $item['unidade'] ?? ''
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="acoes-tabela">

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Deseja realmente remover este insumo da receita?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars($csrf_token) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="receita_insumo_id"
                                                value="<?= (int)$item['receita_insumo_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="remover_insumo"
                                                value="1"
                                                class="btn btn-remover btn-pequeno"
                                                title="Remover insumo"
                                            >
                                                🗑️
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endforeach; ?>


            <div class="observacao">

                <strong>ℹ️ Como o custo é calculado:</strong>

                <br>

                O sistema considera a quantidade utilizada na receita
                multiplicada pelo custo unitário cadastrado para o
                respectivo insumo.

                <br><br>

                Exemplo:

                <strong>500 g</strong> de um ingrediente com custo
                unitário de <strong>R$ 0,0200/g</strong> resultam em:

                <strong>R$ 10,00</strong> de custo.

                <br><br>

                O custo desta tela representa o
                <strong>custo estimado dos insumos da receita</strong>.
                Mão de obra, gás, energia, perdas, taxas de cartão,
                entrega e despesas administrativas ainda são tratados
                separadamente no sistema.

            </div>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         RESUMO
         ========================================================= -->

    <div class="card">

        <h2>
            💰 Resumo da Receita
        </h2>

        <div class="resumo">

            <div class="resumo-item">

                <div class="resumo-titulo">
                    Quantidade de itens
                </div>

                <div class="resumo-valor">
                    <?= count($composicao) ?>
                </div>

            </div>


            <div class="resumo-item">

                <div class="resumo-titulo">
                    Rendimento
                </div>

                <div class="resumo-valor">

                    <?php if (
                        (float)$receita['rendimento'] > 0
                        && !empty($receita['unidade_rendimento'])
                    ): ?>

                        <?= number_format(
                            (float)$receita['rendimento'],
                            3,
                            ',',
                            '.'
                        ) ?>

                        <?= htmlspecialchars(
                            $receita['unidade_rendimento']
                        ) ?>

                    <?php else: ?>

                        -

                    <?php endif; ?>

                </div>

            </div>


            <div class="resumo-item destaque">

                <div class="resumo-titulo">
                    Custo total estimado
                </div>

                <div class="resumo-valor">

                    R$
                    <?= number_format(
                        $custo_total,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const selectInsumo =
        document.getElementById('insumo_id');

    const unidade =
        document.getElementById('unidadeSelecionada');

    if (!selectInsumo || !unidade) {
        return;
    }

    function atualizarUnidade() {

        const option =
            selectInsumo.options[
                selectInsumo.selectedIndex
            ];

        if (
            !option
            || !option.value
        ) {

            unidade.textContent =
                'Unidade: -';

            return;
        }

        const unidadeValor =
            option.dataset.unidade || '-';

        unidade.textContent =
            'Unidade: ' + unidadeValor;
    }

    selectInsumo.addEventListener(
        'change',
        atualizarUnidade
    );

    atualizarUnidade();

});

</script>

</body>

</html>