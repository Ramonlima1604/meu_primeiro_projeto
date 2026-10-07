<?php

session_start();

require_once '../../config/banco.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| ID da despesa
|--------------------------------------------------------------------------
*/
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: despesas.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Identifica a confeiteira do usuário logado
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
    die('Confeiteira não encontrada.');
}

$confeiteira_id = (int) $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| Categorias
|--------------------------------------------------------------------------
*/
$categorias = [
    'ingredientes'  => 'Ingredientes',
    'materiais'     => 'Materiais',
    'embalagens'    => 'Embalagens',
    'decoracao'     => 'Decoração',
    'equipamentos'  => 'Equipamentos',
    'transporte'    => 'Transporte',
    'marketing'     => 'Marketing',
    'energia'       => 'Energia',
    'agua'          => 'Água',
    'internet'      => 'Internet',
    'aluguel'       => 'Aluguel',
    'outros'        => 'Outros'
];

/*
|--------------------------------------------------------------------------
| Busca a despesa
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM despesas
    WHERE id = ?
      AND confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $id,
    $confeiteira_id
]);

$despesa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$despesa) {
    die('Despesa não encontrada ou sem permissão para acessá-la.');
}

/*
|--------------------------------------------------------------------------
| Função para converter valor brasileiro
|--------------------------------------------------------------------------
*/
function valor_decimal($valor)
{
    $valor = trim((string) $valor);

    if ($valor === '') {
        return 0;
    }

    $valor = str_replace('R$', '', $valor);
    $valor = trim($valor);

    /*
     * Exemplo:
     * 1.250,50 -> 1250.50
     */
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    return (float) $valor;
}

/*
|--------------------------------------------------------------------------
| Mensagens
|--------------------------------------------------------------------------
*/
$erros = [];

/*
|--------------------------------------------------------------------------
| Processamento
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * CSRF
     */
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $erros[] = 'Sessão expirada. Atualize a página e tente novamente.';
    }

    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $valor = valor_decimal($_POST['valor'] ?? '');
    $data_despesa = trim($_POST['data_despesa'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');

    /*
     * Validações
     */
    if ($descricao === '') {
        $erros[] = 'Informe a descrição da despesa.';
    }

    if (!array_key_exists($categoria, $categorias)) {
        $erros[] = 'Selecione uma categoria válida.';
    }

    if ($valor <= 0) {
        $erros[] = 'Informe um valor maior que zero.';
    }

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $data_despesa
        )
    ) {
        $erros[] = 'Informe uma data válida.';
    }

    /*
     * Atualização
     */
    if (empty($erros)) {

        try {

            $stmt = $pdo->prepare("
                UPDATE despesas
                SET
                    descricao = ?,
                    categoria = ?,
                    valor = ?,
                    data_despesa = ?,
                    observacao = ?
                WHERE id = ?
                  AND confeiteira_id = ?
            ");

            $stmt->execute([
                $descricao,
                $categoria,
                $valor,
                $data_despesa,
                $observacao !== '' ? $observacao : null,
                $id,
                $confeiteira_id
            ]);

            header('Location: despesas.php');
            exit;

        } catch (Throwable $e) {

            error_log(
                'Erro ao editar despesa: ' .
                $e->getMessage()
            );

            $erros[] = 'Não foi possível salvar a alteração. Tente novamente.';
        }
    }

} else {

    /*
     * Preenche os campos com os dados atuais
     */
    $descricao = $despesa['descricao'];
    $categoria = $despesa['categoria'];
    $valor = $despesa['valor'];
    $data_despesa = $despesa['data_despesa'];
    $observacao = $despesa['observacao'] ?? '';
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

    <title>Editar despesa</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            width: 100%;
            max-width: 850px;
            margin: 40px auto;
            padding: 20px;
        }

        .topo {
            margin-bottom: 25px;
        }

        .topo h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .topo p {
            margin: 0;
            color: #666;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .campo {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5d8dc;
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #222;
        }

        .linha {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .erros {
            background: #ffe9e9;
            color: #a40000;
            border: 1px solid #ffbcbc;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .erros ul {
            margin: 0;
            padding-left: 20px;
        }

        .acoes {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 15px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-salvar {
            background: #198754;
            color: #fff;
        }

        .btn-salvar:hover {
            background: #157347;
        }

        .btn-voltar {
            background: #e9ecef;
            color: #222;
        }

        .btn-voltar:hover {
            background: #dfe2e5;
        }

        .ajuda {
            margin-top: 6px;
            font-size: 13px;
            color: #777;
        }

        @media (max-width: 650px) {

            .container {
                margin: 15px auto;
                padding: 12px;
            }

            .card {
                padding: 18px;
            }

            .linha {
                grid-template-columns: 1fr;
            }

            .acoes {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="topo">

        <h1>Editar despesa</h1>

        <p>
            Altere os dados da despesa e salve as modificações.
        </p>

    </div>

    <?php if (!empty($erros)): ?>

        <div class="erros">

            <ul>

                <?php foreach ($erros as $erro): ?>

                    <li>
                        <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <div class="card">

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"
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
                    required
                    value="<?= htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Ex.: Compra de chocolate"
                >

            </div>

            <div class="linha">

                <div class="campo">

                    <label for="categoria">
                        Categoria
                    </label>

                    <select
                        id="categoria"
                        name="categoria"
                        required
                    >

                        <option value="">
                            Selecione
                        </option>

                        <?php foreach ($categorias as $valor_categoria => $nome_categoria): ?>

                            <option
                                value="<?= htmlspecialchars($valor_categoria, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $categoria === $valor_categoria ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($nome_categoria, ENT_QUOTES, 'UTF-8') ?>
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
                        required
                        value="<?= htmlspecialchars(number_format((float)$valor, 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="0,00"
                    >

                    <div class="ajuda">
                        Exemplo: 1250,50
                    </div>

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
                    required
                    value="<?= htmlspecialchars($data_despesa, ENT_QUOTES, 'UTF-8') ?>"
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
                    placeholder="Informações adicionais sobre essa despesa..."
                ><?= htmlspecialchars($observacao, ENT_QUOTES, 'UTF-8') ?></textarea>

            </div>

            <div class="acoes">

                <a
                    href="despesas.php"
                    class="btn btn-voltar"
                >
                    Voltar
                </a>

                <button
                    type="submit"
                    class="btn btn-salvar"
                >
                    Salvar alterações
                </button>

            </div>

        </form>

    </div>

</div>

</body>
</html>