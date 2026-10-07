<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/banco.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$usuarioId = $_SESSION['usuario_id'] ?? null;

if (!$usuarioId) {
    header('Location: ../../login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Localiza a confeiteira
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuarioId]);

$confeiteiraId = $stmt->fetchColumn();

if (!$confeiteiraId) {
    die('Confeiteira não encontrada.');
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


$erro = '';


/*
|--------------------------------------------------------------------------
| Cadastro
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $token)) {

        $erro = 'Token de segurança inválido.';
    }


    $descricao = trim(
        $_POST['descricao'] ?? ''
    );

    $valorInformado = $_POST['valor'] ?? '0';


    /*
    |--------------------------------------------------------------------------
    | Trata valor brasileiro
    |--------------------------------------------------------------------------
    |
    | Exemplos:
    | 100,50
    | 100.50
    |
    */
    if (
        strpos($valorInformado, ',') !== false
    ) {

        $valor = str_replace('.', '', $valorInformado);
        $valor = str_replace(',', '.', $valor);

    } else {

        $valor = $valorInformado;
    }


    $data = $_POST['data_recebimento'] ?? '';

    $formaPagamento = trim(
        $_POST['forma_pagamento'] ?? ''
    );

    $observacao = trim(
        $_POST['observacao'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | Validações
    |--------------------------------------------------------------------------
    */
    if (
        $erro === '' &&
        $descricao === ''
    ) {

        $erro = 'Informe a descrição do recebimento.';
    }

    elseif (
        $erro === '' &&
        (
            !is_numeric($valor) ||
            (float)$valor <= 0
        )
    ) {

        $erro = 'Informe um valor válido.';
    }

    elseif (
        $erro === '' &&
        $data === ''
    ) {

        $erro = 'Informe a data do recebimento.';
    }


    /*
    |--------------------------------------------------------------------------
    | Insere recebimento avulso
    |--------------------------------------------------------------------------
    */
    if ($erro === '') {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO recebimentos
                (
                    confeiteira_id,
                    descricao,
                    valor,
                    data_recebimento,
                    forma_pagamento,
                    pedido_id,
                    observacao
                )
                VALUES (?, ?, ?, ?, ?, NULL, ?)
            ");

            $stmt->execute([
                $confeiteiraId,
                $descricao,
                (float)$valor,
                $data,
                $formaPagamento !== ''
                    ? $formaPagamento
                    : null,
                $observacao !== ''
                    ? $observacao
                    : null
            ]);


            header(
                'Location: recebimentos.php?cadastrado=1'
            );

            exit;

        } catch (PDOException $e) {

            $erro = 'Não foi possível cadastrar o recebimento.';
        }
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

    <title>Novo Recebimento</title>

    <link
        rel="stylesheet"
        href="../../assets/style.css"
    >

    <style>

        .pagina {
            max-width: 700px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .box {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.08);
        }

        .informacao {
            background: #fff3cd;
            color: #664d03;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .campo {
            margin-bottom: 18px;
        }

        .campo label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .campo input,
        .campo textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 7px;
        }

        .campo textarea {
            min-height: 100px;
            resize: vertical;
        }

        .botoes {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        button,
        .voltar {
            padding: 11px 18px;
            border-radius: 7px;
            border: 0;
            font-weight: bold;
            text-decoration: none;
        }

        button {
            background: #198754;
            color: #fff;
            cursor: pointer;
        }

        .voltar {
            background: #555;
            color: #fff;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

    </style>

</head>

<body>

<div class="pagina">

    <h1>Novo recebimento</h1>

    <div class="box">


        <div class="informacao">

            <strong>Recebimento avulso</strong>

            <br>

            Este formulário é destinado a valores recebidos
            que não estão vinculados a um pedido.

            <br><br>

            <strong>Importante:</strong>

            pagamentos de pedidos devem ser registrados
            através do próprio pedido.

        </div>


        <?php if ($erro): ?>

            <div class="erro">
                <?= htmlspecialchars($erro) ?>
            </div>

        <?php endif; ?>


        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken) ?>"
            >


            <div class="campo">

                <label>
                    Descrição *
                </label>

                <input
                    type="text"
                    name="descricao"
                    maxlength="255"
                    required
                    value="<?= htmlspecialchars(
                        $_POST['descricao'] ?? ''
                    ) ?>"
                    placeholder="Ex.: Venda de utensílio"
                >

            </div>


            <div class="campo">

                <label>
                    Valor *
                </label>

                <input
                    type="text"
                    name="valor"
                    required
                    value="<?= htmlspecialchars(
                        $_POST['valor'] ?? ''
                    ) ?>"
                    placeholder="Ex.: 150,00"
                >

            </div>


            <div class="campo">

                <label>
                    Data do recebimento *
                </label>

                <input
                    type="date"
                    name="data_recebimento"
                    required
                    value="<?= htmlspecialchars(
                        $_POST['data_recebimento']
                        ?? date('Y-m-d')
                    ) ?>"
                >

            </div>


            <div class="campo">

                <label>
                    Forma de pagamento
                </label>

                <input
                    type="text"
                    name="forma_pagamento"
                    maxlength="100"
                    value="<?= htmlspecialchars(
                        $_POST['forma_pagamento'] ?? ''
                    ) ?>"
                    placeholder="Ex.: Pix, dinheiro, cartão..."
                >

            </div>


            <div class="campo">

                <label>
                    Observação
                </label>

                <textarea
                    name="observacao"
                    placeholder="Informações adicionais..."
                ><?= htmlspecialchars(
                    $_POST['observacao'] ?? ''
                ) ?></textarea>

            </div>


            <div class="botoes">

                <button type="submit">
                    Salvar recebimento
                </button>

                <a
                    href="recebimentos.php"
                    class="voltar"
                >
                    Cancelar
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>