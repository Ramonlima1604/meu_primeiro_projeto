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

function valor_decimal(string $valor): float
{
    $valor = trim($valor);

    $valor = str_replace('R$', '', $valor);
    $valor = trim($valor);

    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    return (float) $valor;
}

/* =========================================================
   BUSCAR CONFEITEIRA
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
   CAMPOS
========================================================= */

$descricao = '';
$categoria = 'outros';
$valor = '';
$dataDespesa = date('Y-m-d');
$observacao = '';

$erro = '';

/* =========================================================
   PROCESSAMENTO
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validar_csrf();

    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'outros');
    $valorInformado = trim($_POST['valor'] ?? '');
    $dataDespesa = trim($_POST['data_despesa'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');

    /* =====================================================
       VALIDAÇÕES
    ===================================================== */

    if ($descricao === '') {

        $erro = 'Informe a descrição da despesa.';

    } elseif (
        !array_key_exists($categoria, $categorias)
    ) {

        $erro = 'Categoria inválida.';

    }

    $valorNumerico = valor_decimal($valorInformado);

    if (
        $erro === '' &&
        $valorNumerico <= 0
    ) {

        $erro = 'Informe um valor maior que zero.';
    }

    if (
        $erro === '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $dataDespesa
        )
    ) {

        $erro = 'Informe uma data válida.';
    }

    /* =====================================================
       INSERIR
    ===================================================== */

    if ($erro === '') {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO despesas (
                    confeiteira_id,
                    descricao,
                    categoria,
                    valor,
                    data_despesa,
                    observacao
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $confeiteiraId,
                $descricao,
                $categoria,
                $valorNumerico,
                $dataDespesa,
                $observacao !== ''
                    ? $observacao
                    : null
            ]);

            header(
                'Location: despesas.php'
            );

            exit;

        } catch (Throwable $e) {

            error_log(
                'Erro ao cadastrar despesa: ' .
                $e->getMessage()
            );

            $erro =
                'Não foi possível cadastrar a despesa.';
        }
    }

    /*
     * Mantém o valor digitado caso tenha ocorrido erro.
     */
    if ($valorInformado !== '') {
        $valor = $valorInformado;
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
        Nova despesa
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
            max-width: 800px;
            margin: 0 auto;
            padding: 25px;
        }

        .topo {
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .subtitulo {
            color: #666;
            margin-top: 6px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .campo {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font-size: 15px;
            background: white;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .ajuda {
            color: #777;
            font-size: 12px;
            margin-top: 5px;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 11px 17px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-salvar {
            background: #198754;
            color: white;
        }

        .btn-cancelar {
            background: #6c757d;
            color: white;
        }

        .alerta {
            background: #f8d7da;
            color: #842029;
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {

            .container {
                padding: 15px;
            }

            .card {
                padding: 18px;
            }

            h1 {
                font-size: 23px;
            }

            .acoes .btn {
                width: 100%;
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <h1>
            Nova despesa
        </h1>

        <div class="subtitulo">
            Registre um gasto da sua confeitaria.
        </div>

    </div>

    <?php if ($erro): ?>

        <div class="alerta">
            <?= h($erro) ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h(csrf_token()) ?>"
            >

            <div class="campo">

                <label for="descricao">
                    Descrição
                </label>

                <input
                    type="text"
                    id="descricao"
                    name="descricao"
                    maxlength="255"
                    placeholder="Ex.: Compra de farinha"
                    value="<?= h($descricao) ?>"
                    required
                >

            </div>

            <div class="campo">

                <label for="categoria">
                    Categoria
                </label>

                <select
                    id="categoria"
                    name="categoria"
                    required
                >

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

            <div class="campo">

                <label for="valor">
                    Valor
                </label>

                <input
                    type="text"
                    id="valor"
                    name="valor"
                    inputmode="decimal"
                    placeholder="0,00"
                    value="<?= h($valor) ?>"
                    required
                >

                <div class="ajuda">
                    Exemplo: 150,50
                </div>

            </div>

            <div class="campo">

                <label for="data_despesa">
                    Data da despesa
                </label>

                <input
                    type="date"
                    id="data_despesa"
                    name="data_despesa"
                    value="<?= h($dataDespesa) ?>"
                    required
                >

            </div>

            <div class="campo">

                <label for="observacao">
                    Observação
                </label>

                <textarea
                    id="observacao"
                    name="observacao"
                    maxlength="1000"
                    placeholder="Alguma informação adicional..."
                ><?= h($observacao) ?></textarea>

            </div>

            <div class="acoes">

                <button
                    type="submit"
                    class="btn btn-salvar"
                >
                    Salvar despesa
                </button>

                <a
                    href="despesas.php"
                    class="btn btn-cancelar"
                >
                    Cancelar
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>