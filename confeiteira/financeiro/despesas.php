<?php

session_start();

require_once __DIR__ . '/../../config/banco.php';

/* =========================================================
   AUTENTICAÇÃO
========================================================= */

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];

/* =========================================================
   FUNÇÕES
========================================================= */

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validar_csrf(): void
{
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        http_response_code(403);
        exit('Requisição inválida.');
    }
}

function dinheiro($valor): string
{
    return 'R$ ' . number_format(
        (float) $valor,
        2,
        ',',
        '.'
    );
}

/* =========================================================
   CONFEITEIRA
========================================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuarioId]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    exit('Confeiteira não encontrada.');
}

$confeiteiraId = (int) $confeiteira['id'];

/* =========================================================
   EXCLUIR DESPESA
========================================================= */

$erro = '';
$sucesso = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['acao'] ?? '') === 'excluir'
) {

    validar_csrf();

    $despesaId = (int) ($_POST['id'] ?? 0);

    if ($despesaId <= 0) {

        $erro = 'Despesa inválida.';

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT id
                FROM despesas
                WHERE id = ?
                  AND confeiteira_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $despesaId,
                $confeiteiraId
            ]);

            if (!$stmt->fetch()) {
                throw new Exception(
                    'Despesa não encontrada.'
                );
            }

            $stmt = $pdo->prepare("
                DELETE FROM despesas
                WHERE id = ?
                  AND confeiteira_id = ?
            ");

            $stmt->execute([
                $despesaId,
                $confeiteiraId
            ]);

            $sucesso = 'Despesa excluída com sucesso.';

        } catch (Throwable $e) {

            error_log(
                'Erro ao excluir despesa: ' .
                $e->getMessage()
            );

            $erro =
                'Não foi possível excluir a despesa.';
        }
    }
}

/* =========================================================
   FILTROS
========================================================= */

$dataInicial = trim($_GET['data_inicial'] ?? '');
$dataFinal   = trim($_GET['data_final'] ?? '');
$categoria   = trim($_GET['categoria'] ?? '');
$busca       = trim($_GET['busca'] ?? '');

/* =========================================================
   CATEGORIAS
========================================================= */

$categorias = [
    'ingredientes' => 'Ingredientes',
    'materiais' => 'Materiais',
    'embalagens' => 'Embalagens',
    'decoracao' => 'Decoração',
    'equipamentos' => 'Equipamentos',
    'transporte' => 'Transporte',
    'marketing' => 'Marketing',
    'energia' => 'Energia',
    'agua' => 'Água',
    'internet' => 'Internet',
    'aluguel' => 'Aluguel',
    'outros' => 'Outros'
];

/* =========================================================
   VALIDAR DATAS
========================================================= */

if (
    $dataInicial !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicial)
) {
    $dataInicial = '';
}

if (
    $dataFinal !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFinal)
) {
    $dataFinal = '';
}

/* =========================================================
   CONSULTA
========================================================= */

$sql = "
    SELECT
        id,
        descricao,
        categoria,
        valor,
        data_despesa,
        observacao
    FROM despesas
    WHERE confeiteira_id = ?
";

$params = [$confeiteiraId];

/* =========================================================
   DATA INICIAL
========================================================= */

if ($dataInicial !== '') {

    $sql .= "
        AND data_despesa >= ?
    ";

    $params[] = $dataInicial . ' 00:00:00';
}

/* =========================================================
   DATA FINAL
========================================================= */

if ($dataFinal !== '') {

    $sql .= "
        AND data_despesa <= ?
    ";

    $params[] = $dataFinal . ' 23:59:59';
}

/* =========================================================
   CATEGORIA
========================================================= */

if (
    $categoria !== '' &&
    array_key_exists($categoria, $categorias)
) {

    $sql .= "
        AND categoria = ?
    ";

    $params[] = $categoria;
}

/* =========================================================
   BUSCA
========================================================= */

if ($busca !== '') {

    $sql .= "
        AND (
            descricao LIKE ?
            OR observacao LIKE ?
        )
    ";

    $termo = '%' . $busca . '%';

    $params[] = $termo;
    $params[] = $termo;
}

/* =========================================================
   ORDENAÇÃO
========================================================= */

$sql .= "
    ORDER BY
        data_despesa DESC,
        id DESC
";

/* =========================================================
   EXECUTAR
========================================================= */

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$despesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   TOTAL
========================================================= */

$totalDespesas = 0;

foreach ($despesas as $despesa) {
    $totalDespesas += (float) $despesa['valor'];
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
        Despesas - <?= h($confeiteira['nome_confeitaria']) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 25px;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .subtitulo {
            color: #666;
            margin-top: 5px;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            text-decoration: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }

        .btn-principal {
            background: #198754;
            color: white;
        }

        .btn-secundario {
            background: #6c757d;
            color: white;
        }

        .btn-editar {
            background: #0d6efd;
            color: white;
        }

        .btn-excluir {
            background: #dc3545;
            color: white;
        }

        .filtros {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .filtros-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        input,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 7px;
            background: white;
        }

        .resumo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .resumo-label {
            color: #666;
            font-size: 14px;
        }

        .resumo-valor {
            color: #dc3545;
            font-size: 25px;
            font-weight: bold;
        }

        .alerta {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .alerta-sucesso {
            background: #d1e7dd;
            color: #0f5132;
        }

        .alerta-erro {
            background: #f8d7da;
            color: #842029;
        }

        .tabela-container {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f8f9fa;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .valor {
            color: #dc3545;
            font-weight: bold;
            white-space: nowrap;
        }

        .categoria {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #f1f3f5;
            font-size: 12px;
            font-weight: bold;
        }

        .observacao {
            color: #777;
            font-size: 12px;
            margin-top: 4px;
        }

        .acoes-linha {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .acoes-linha .btn {
            padding: 7px 10px;
            font-size: 12px;
        }

        .vazio {
            text-align: center;
            padding: 45px 20px;
            color: #777;
        }

        @media (max-width: 600px) {

            .container {
                padding: 15px;
            }

            h1 {
                font-size: 23px;
            }

            .acoes {
                width: 100%;
            }

            .acoes .btn {
                flex: 1;
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <div>

            <h1>Despesas</h1>

            <div class="subtitulo">
                Controle dos gastos da confeitaria.
            </div>

        </div>

        <div class="acoes">

            <a
                href="index.php"
                class="btn btn-secundario"
            >
                ← Financeiro
            </a>

            <a
                href="cadastrar_despesa.php"
                class="btn btn-principal"
            >
                + Nova despesa
            </a>

        </div>

    </div>

    <?php if ($sucesso): ?>

        <div class="alerta alerta-sucesso">
            <?= h($sucesso) ?>
        </div>

    <?php endif; ?>

    <?php if ($erro): ?>

        <div class="alerta alerta-erro">
            <?= h($erro) ?>
        </div>

    <?php endif; ?>

    <!-- FILTROS -->

    <form
        method="GET"
        class="filtros"
    >

        <div class="filtros-grid">

            <div>

                <label for="data_inicial">
                    Data inicial
                </label>

                <input
                    type="date"
                    id="data_inicial"
                    name="data_inicial"
                    value="<?= h($dataInicial) ?>"
                >

            </div>

            <div>

                <label for="data_final">
                    Data final
                </label>

                <input
                    type="date"
                    id="data_final"
                    name="data_final"
                    value="<?= h($dataFinal) ?>"
                >

            </div>

            <div>

                <label for="categoria">
                    Categoria
                </label>

                <select
                    id="categoria"
                    name="categoria"
                >

                    <option value="">
                        Todas
                    </option>

                    <?php foreach (
                        $categorias as $codigo => $nome
                    ): ?>

                        <option
                            value="<?= h($codigo) ?>"
                            <?= $categoria === $codigo
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h($nome) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div>

                <label for="busca">
                    Buscar
                </label>

                <input
                    type="text"
                    id="busca"
                    name="busca"
                    placeholder="Descrição..."
                    value="<?= h($busca) ?>"
                >

            </div>

            <div>

                <button
                    type="submit"
                    class="btn btn-principal"
                >
                    Filtrar
                </button>

            </div>

        </div>

    </form>

    <!-- RESUMO -->

    <div class="resumo">

        <div>

            <div class="resumo-label">
                Total das despesas exibidas
            </div>

            <div class="resumo-valor">
                <?= dinheiro($totalDespesas) ?>
            </div>

        </div>

        <div>

            <div class="resumo-label">
                Registros encontrados
            </div>

            <strong>
                <?= count($despesas) ?>
            </strong>

        </div>

    </div>

    <!-- TABELA -->

    <div class="tabela-container">

        <?php if (!$despesas): ?>

            <div class="vazio">

                <strong>
                    Nenhuma despesa encontrada.
                </strong>

                <br>

                Tente alterar os filtros ou cadastre uma nova despesa.

            </div>

        <?php else: ?>

            <table>

                <thead>

                    <tr>

                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>Ações</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($despesas as $despesa): ?>

                    <tr>

                        <td>
                            <?= date(
                                'd/m/Y',
                                strtotime($despesa['data_despesa'])
                            ) ?>
                        </td>

                        <td>

                            <strong>
                                <?= h($despesa['descricao']) ?>
                            </strong>

                            <?php if (
                                !empty($despesa['observacao'])
                            ): ?>

                                <div class="observacao">
                                    <?= h($despesa['observacao']) ?>
                                </div>

                            <?php endif; ?>

                        </td>

                        <td>

                            <span class="categoria">

                                <?= h(
                                    $categorias[
                                        $despesa['categoria']
                                    ]
                                    ?? ucfirst(
                                        $despesa['categoria']
                                    )
                                ) ?>

                            </span>

                        </td>

                        <td class="valor">

                            <?= dinheiro($despesa['valor']) ?>

                        </td>

                        <td>

                            <div class="acoes-linha">

                                <a
                                    href="editar_despesa.php?id=<?= (int) $despesa['id'] ?>"
                                    class="btn btn-editar"
                                >
                                    Editar
                                </a>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Tem certeza que deseja excluir esta despesa?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="acao"
                                        value="excluir"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $despesa['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-excluir"
                                    >
                                        Excluir
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</div>

</body>

</html>