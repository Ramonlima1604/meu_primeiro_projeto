<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

$mensagem = '';
$erro = '';

/*
|--------------------------------------------------------------------------
| Localiza a confeiteira vinculada ao usuário logado
|--------------------------------------------------------------------------
*/
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

$confeiteira_id = (int) $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| Valida ID da receita
|--------------------------------------------------------------------------
*/
$receita_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$receita_id || $receita_id <= 0) {
    header("Location: receitas.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Busca a receita garantindo que pertence à confeiteira logada
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
        ativo,
        criado_em
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
| Busca custo atual da receita
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT COALESCE(
        SUM(ri.quantidade * i.custo_unitario),
        0
    ) AS custo_total
    FROM receita_insumos ri
    INNER JOIN insumos i
        ON i.id = ri.insumo_id
    WHERE ri.receita_id = ?
      AND i.confeiteira_id = ?
");

$stmt->execute([
    $receita_id,
    $confeiteira_id
]);

$custo_receita = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Processa atualização
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome = trim($_POST['nome'] ?? '');
    $tipo = trim($_POST['tipo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    $rendimento = str_replace(',', '.', trim($_POST['rendimento'] ?? '0'));
    $unidade_rendimento = trim($_POST['unidade_rendimento'] ?? '');

    $ativo = isset($_POST['ativo']) ? 1 : 0;

    /*
    |--------------------------------------------------------------------------
    | Valida nome
    |--------------------------------------------------------------------------
    */
    if ($nome === '') {
        $erro = "Informe o nome da receita.";
    }

    /*
    |--------------------------------------------------------------------------
    | Valida tipo
    |--------------------------------------------------------------------------
    */
    $tipos_validos = [
        'massa',
        'recheio',
        'cobertura',
        'decoracao',
        'outros'
    ];

    if ($erro === '' && !in_array($tipo, $tipos_validos, true)) {
        $erro = "Selecione um tipo de receita válido.";
    }

    /*
    |--------------------------------------------------------------------------
    | Valida rendimento
    |--------------------------------------------------------------------------
    */
    if ($erro === '') {

        if ($rendimento === '' || !is_numeric($rendimento)) {
            $erro = "Informe um rendimento válido.";
        } else {

            $rendimento = (float) $rendimento;

            if ($rendimento < 0) {
                $erro = "O rendimento não pode ser negativo.";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Valida unidade do rendimento
    |--------------------------------------------------------------------------
    */
    $unidades_validas = [
        'kg',
        'g',
        'mg',
        'L',
        'ml',
        'un'
    ];

    if ($erro === '') {

        if ($rendimento > 0) {

            if (!in_array($unidade_rendimento, $unidades_validas, true)) {
                $erro = "Selecione uma unidade de rendimento válida.";
            }

        } else {

            $unidade_rendimento = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Atualiza receita
    |--------------------------------------------------------------------------
    */
    if ($erro === '') {

        try {

            $stmt = $pdo->prepare("
                UPDATE receitas
                SET
                    nome = ?,
                    tipo = ?,
                    descricao = ?,
                    rendimento = ?,
                    unidade_rendimento = ?,
                    ativo = ?
                WHERE id = ?
                  AND confeiteira_id = ?
            ");

            $stmt->execute([
                $nome,
                $tipo,
                $descricao !== '' ? $descricao : null,
                $rendimento,
                $unidade_rendimento,
                $ativo,
                $receita_id,
                $confeiteira_id
            ]);

            $mensagem = "Receita atualizada com sucesso.";

            /*
            |--------------------------------------------------------------------------
            | Atualiza os dados exibidos na tela
            |--------------------------------------------------------------------------
            */
            $receita['nome'] = $nome;
            $receita['tipo'] = $tipo;
            $receita['descricao'] = $descricao;
            $receita['rendimento'] = $rendimento;
            $receita['unidade_rendimento'] = $unidade_rendimento;
            $receita['ativo'] = $ativo;

        } catch (PDOException $e) {

            $erro = "Não foi possível atualizar a receita. Verifique os dados e tente novamente.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Quantidade de insumos utilizados
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM receita_insumos ri
    INNER JOIN insumos i
        ON i.id = ri.insumo_id
    WHERE ri.receita_id = ?
      AND i.confeiteira_id = ?
");

$stmt->execute([
    $receita_id,
    $confeiteira_id
]);

$quantidade_insumos = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Nome amigável do tipo
|--------------------------------------------------------------------------
*/
$nomes_tipos = [
    'massa' => 'Massa',
    'recheio' => 'Recheio',
    'cobertura' => 'Cobertura',
    'decoracao' => 'Decoração',
    'outros' => 'Outros'
];

$tipo_exibicao = $nomes_tipos[$receita['tipo']] ?? 'Outros';

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Editar Receita - Sistema de Bolos</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f5f6f8;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
        }

        .container {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            padding: 25px 20px 50px;
        }

        .topo {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .topo h1 {
            margin: 0 0 6px;
            font-size: 28px;
        }

        .topo p {
            margin: 0;
            color: #666;
        }

        .acoes-topo {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-voltar {
            background: #e9ecef;
            color: #222;
        }

        .btn-composicao {
            background: #6f42c1;
            color: #fff;
        }

        .btn-salvar {
            background: #198754;
            color: #fff;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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
        }

        .campo.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #6f42c1;
            box-shadow: 0 0 0 2px rgba(111,66,193,0.1);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .rendimento {
            display: grid;
            grid-template-columns: 1fr 180px;
            gap: 10px;
        }

        .ajuda {
            margin-top: 6px;
            color: #777;
            font-size: 12px;
        }

        .mensagem {
            padding: 13px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .mensagem-sucesso {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }

        .mensagem-erro {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e9ecef;
        }

        .info .titulo {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .info .valor {
            font-size: 18px;
            font-weight: bold;
        }

        .observacao {
            margin-top: 15px;
            padding: 14px;
            background: #fff3cd;
            border: 1px solid #ffecb5;
            color: #664d03;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.5;
        }

        .checkbox {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
        }

        .checkbox input {
            width: auto;
        }

        .rodape-form {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        @media (max-width: 700px) {

            .grid {
                grid-template-columns: 1fr;
            }

            .campo.full {
                grid-column: auto;
            }

            .rendimento {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .rodape-form {
                flex-direction: column;
            }

            .rodape-form .btn {
                width: 100%;
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <h1>✏️ Editar Receita</h1>

        <p>
            Editando a receita:
            <strong><?= htmlspecialchars($receita['nome']) ?></strong>
        </p>

        <div class="acoes-topo">

            <a
                href="receitas.php"
                class="btn btn-voltar"
            >
                ← Voltar para receitas
            </a>

            <a
                href="composicao_receita.php?id=<?= $receita_id ?>"
                class="btn btn-composicao"
            >
                🧪 Editar composição
            </a>

        </div>

    </div>


    <?php if ($mensagem !== ''): ?>

        <div class="mensagem mensagem-sucesso">
            ✅ <?= htmlspecialchars($mensagem) ?>
        </div>

    <?php endif; ?>


    <?php if ($erro !== ''): ?>

        <div class="mensagem mensagem-erro">
            ❌ <?= htmlspecialchars($erro) ?>
        </div>

    <?php endif; ?>


    <div class="card">

        <h2>📋 Informações da Receita</h2>

        <form method="POST">

            <div class="grid">

                <div class="campo full">

                    <label for="nome">
                        Nome da receita *
                    </label>

                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        maxlength="150"
                        required
                        value="<?= htmlspecialchars($receita['nome']) ?>"
                        placeholder="Ex.: Massa de chocolate"
                    >

                </div>


                <div class="campo">

                    <label for="tipo">
                        Tipo da receita *
                    </label>

                    <select
                        id="tipo"
                        name="tipo"
                        required
                    >

                        <option
                            value="massa"
                            <?= $receita['tipo'] === 'massa' ? 'selected' : '' ?>
                        >
                            Massa
                        </option>

                        <option
                            value="recheio"
                            <?= $receita['tipo'] === 'recheio' ? 'selected' : '' ?>
                        >
                            Recheio
                        </option>

                        <option
                            value="cobertura"
                            <?= $receita['tipo'] === 'cobertura' ? 'selected' : '' ?>
                        >
                            Cobertura
                        </option>

                        <option
                            value="decoracao"
                            <?= $receita['tipo'] === 'decoracao' ? 'selected' : '' ?>
                        >
                            Decoração
                        </option>

                        <option
                            value="outros"
                            <?= $receita['tipo'] === 'outros' ? 'selected' : '' ?>
                        >
                            Outros
                        </option>

                    </select>

                </div>


                <div class="campo">

                    <label for="rendimento">
                        Rendimento
                    </label>

                    <div class="rendimento">

                        <input
                            type="number"
                            id="rendimento"
                            name="rendimento"
                            step="0.001"
                            min="0"
                            value="<?= htmlspecialchars((string) $receita['rendimento']) ?>"
                            placeholder="Ex.: 1"
                        >

                        <select
                            id="unidade_rendimento"
                            name="unidade_rendimento"
                        >

                            <option value="">
                                Sem unidade
                            </option>

                            <option
                                value="kg"
                                <?= $receita['unidade_rendimento'] === 'kg' ? 'selected' : '' ?>
                            >
                                kg
                            </option>

                            <option
                                value="g"
                                <?= $receita['unidade_rendimento'] === 'g' ? 'selected' : '' ?>
                            >
                                g
                            </option>

                            <option
                                value="mg"
                                <?= $receita['unidade_rendimento'] === 'mg' ? 'selected' : '' ?>
                            >
                                mg
                            </option>

                            <option
                                value="L"
                                <?= $receita['unidade_rendimento'] === 'L' ? 'selected' : '' ?>
                            >
                                L
                            </option>

                            <option
                                value="ml"
                                <?= $receita['unidade_rendimento'] === 'ml' ? 'selected' : '' ?>
                            >
                                ml
                            </option>

                            <option
                                value="un"
                                <?= $receita['unidade_rendimento'] === 'un' ? 'selected' : '' ?>
                            >
                                unidade
                            </option>

                        </select>

                    </div>

                    <div class="ajuda">
                        Ex.: uma receita que rende 1 kg deve ser cadastrada como 1 kg.
                    </div>

                </div>


                <div class="campo full">

                    <label for="descricao">
                        Descrição
                    </label>

                    <textarea
                        id="descricao"
                        name="descricao"
                        placeholder="Descrição, modo de preparo, observações ou informações adicionais..."
                    ><?= htmlspecialchars($receita['descricao'] ?? '') ?></textarea>

                </div>


                <div class="campo full">

                    <label>
                        Status
                    </label>

                    <div class="checkbox">

                        <input
                            type="checkbox"
                            id="ativo"
                            name="ativo"
                            value="1"
                            <?= (int) $receita['ativo'] === 1 ? 'checked' : '' ?>
                        >

                        <label
                            for="ativo"
                            style="margin:0;font-weight:normal;"
                        >
                            Receita ativa
                        </label>

                    </div>

                </div>

            </div>


            <div class="rodape-form">

                <a
                    href="receitas.php"
                    class="btn btn-voltar"
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="btn btn-salvar"
                >
                    💾 Salvar alterações
                </button>

            </div>

        </form>

    </div>


    <div class="card">

        <h2>📊 Resumo da Receita</h2>

        <div class="info-grid">

            <div class="info">

                <div class="titulo">
                    Tipo
                </div>

                <div class="valor">
                    <?= htmlspecialchars($tipo_exibicao) ?>
                </div>

            </div>


            <div class="info">

                <div class="titulo">
                    Insumos cadastrados
                </div>

                <div class="valor">
                    <?= $quantidade_insumos ?>
                </div>

            </div>


            <div class="info">

                <div class="titulo">
                    Custo estimado
                </div>

                <div class="valor">
                    R$ <?= number_format($custo_receita, 2, ',', '.') ?>
                </div>

            </div>

        </div>


        <div class="observacao">

            <strong>ℹ️ Importante:</strong>

            alterar os dados acima não altera os ingredientes/insumos
            utilizados na receita.

            <br><br>

            Para adicionar, remover ou alterar quantidades dos insumos,
            utilize o botão
            <strong>“Editar composição”</strong>.

        </div>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const rendimento = document.getElementById('rendimento');
    const unidade = document.getElementById('unidade_rendimento');

    function atualizarUnidade() {

        if (!rendimento || !unidade) {
            return;
        }

        const valor = parseFloat(rendimento.value || 0);

        if (valor <= 0) {
            unidade.value = '';
            unidade.disabled = true;
        } else {
            unidade.disabled = false;
        }
    }

    atualizarUnidade();

    rendimento.addEventListener('input', atualizarUnidade);

});

</script>

</body>

</html>