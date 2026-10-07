<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/banco.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
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

$confeiteira_id = $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| PROCESSAR CADASTRO
|--------------------------------------------------------------------------
*/

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome = trim($_POST['nome'] ?? '');
    $tipo = $_POST['tipo'] ?? 'outros';
    $descricao = trim($_POST['descricao'] ?? '');

    $rendimento = str_replace(',', '.', $_POST['rendimento'] ?? '0');
    $rendimento = floatval($rendimento);

    $unidade_rendimento = strtolower(
        trim($_POST['unidade_rendimento'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDAR
    |--------------------------------------------------------------------------
    */

    $tipos_validos = [
        'massa',
        'recheio',
        'cobertura',
        'decoracao',
        'outros'
    ];

    $unidades_validas = [
        'g',
        'kg',
        'ml',
        'l',
        'un'
    ];

    if ($nome === '') {
        $erro = 'Informe o nome da receita.';
    } elseif (!in_array($tipo, $tipos_validos, true)) {
        $erro = 'Tipo de receita inválido.';
    } elseif ($rendimento <= 0) {
        $erro = 'Informe um rendimento maior que zero.';
    } elseif (!in_array($unidade_rendimento, $unidades_validas, true)) {
        $erro = 'Selecione uma unidade de rendimento válida.';
    }

    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    if ($erro === '') {

        $stmt = $pdo->prepare("
            INSERT INTO receitas
            (
                confeiteira_id,
                nome,
                tipo,
                descricao,
                rendimento,
                unidade_rendimento,
                ativo
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                1
            )
        ");

        $stmt->execute([
            $confeiteira_id,
            $nome,
            $tipo,
            $descricao,
            $rendimento,
            $unidade_rendimento
        ]);

        $receita_id = $pdo->lastInsertId();

        /*
        |--------------------------------------------------------------------------
        | APÓS CADASTRAR
        |--------------------------------------------------------------------------
        |
        | Vamos direto para a composição da receita.
        |
        */

        header(
            'Location: composicao_receita.php?id=' .
            $receita_id
        );

        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Cadastrar Receita</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8;
            color: #222;
        }

        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 8px;
        }

        .subtitulo {
            color: #666;
            margin-bottom: 25px;
        }

        .campo {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 15px;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .linha {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .erro {
            background: #ffe5e5;
            color: #a00000;
            border: 1px solid #ffb3b3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info {
            background: #eef6ff;
            border: 1px solid #c9e2ff;
            color: #174a7e;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .botoes {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        button,
        .voltar {
            padding: 12px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 15px;
            font-weight: bold;
        }

        button {
            background: #198754;
            color: white;
        }

        button:hover {
            background: #157347;
        }

        .voltar {
            background: #6c757d;
            color: white;
        }

        .voltar:hover {
            background: #5c636a;
        }

        @media (max-width: 600px) {

            .container {
                margin: 15px auto;
                padding: 10px;
            }

            .card {
                padding: 20px;
            }

            .linha {
                grid-template-columns: 1fr;
            }

            .botoes {
                flex-direction: column;
            }

            button,
            .voltar {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>Nova Receita</h1>

        <div class="subtitulo">
            Cadastre uma receita que poderá ser utilizada em vários bolos.
        </div>

        <?php if ($erro): ?>

            <div class="erro">
                <?= htmlspecialchars($erro) ?>
            </div>

        <?php endif; ?>

        <div class="info">

            <strong>Como funciona o rendimento?</strong>

            <br><br>

            Informe quanto a receita produz no total.

            <br>

            Exemplo: uma receita de brigadeiro produz
            <strong>1.000 g</strong>.

            <br><br>

            Depois, quando essa receita for adicionada a um bolo,
            poderemos informar que o bolo utiliza, por exemplo,
            <strong>400 g</strong>.

            <br><br>

            Assim o sistema calculará automaticamente apenas
            <strong>40% do custo da receita</strong>.

        </div>

        <form method="POST">

            <div class="campo">

                <label for="nome">
                    Nome da receita
                </label>

                <input
                    type="text"
                    id="nome"
                    name="nome"
                    placeholder="Ex.: Brigadeiro Gourmet"
                    value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"
                    required
                >

            </div>

            <div class="campo">

                <label for="tipo">
                    Tipo da receita
                </label>

                <select id="tipo" name="tipo" required>

                    <option value="massa"
                        <?= (($_POST['tipo'] ?? '') === 'massa') ? 'selected' : '' ?>>
                        Massa
                    </option>

                    <option value="recheio"
                        <?= (($_POST['tipo'] ?? '') === 'recheio') ? 'selected' : '' ?>>
                        Recheio
                    </option>

                    <option value="cobertura"
                        <?= (($_POST['tipo'] ?? '') === 'cobertura') ? 'selected' : '' ?>>
                        Cobertura
                    </option>

                    <option value="decoracao"
                        <?= (($_POST['tipo'] ?? '') === 'decoracao') ? 'selected' : '' ?>>
                        Decoração
                    </option>

                    <option value="outros"
                        <?= (($_POST['tipo'] ?? 'outros') === 'outros') ? 'selected' : '' ?>>
                        Outros
                    </option>

                </select>

            </div>

            <div class="linha">

                <div class="campo">

                    <label for="rendimento">
                        Rendimento total
                    </label>

                    <input
                        type="number"
                        id="rendimento"
                        name="rendimento"
                        step="0.001"
                        min="0.001"
                        placeholder="Ex.: 1000"
                        value="<?= htmlspecialchars($_POST['rendimento'] ?? '') ?>"
                        required
                    >

                </div>

                <div class="campo">

                    <label for="unidade_rendimento">
                        Unidade
                    </label>

                    <select
                        id="unidade_rendimento"
                        name="unidade_rendimento"
                        required
                    >

                        <option value="">
                            Selecione
                        </option>

                        <option value="g"
                            <?= (($_POST['unidade_rendimento'] ?? '') === 'g') ? 'selected' : '' ?>>
                            Gramas (g)
                        </option>

                        <option value="kg"
                            <?= (($_POST['unidade_rendimento'] ?? '') === 'kg') ? 'selected' : '' ?>>
                            Quilogramas (kg)
                        </option>

                        <option value="ml"
                            <?= (($_POST['unidade_rendimento'] ?? '') === 'ml') ? 'selected' : '' ?>>
                            Mililitros (ml)
                        </option>

                        <option value="l"
                            <?= (($_POST['unidade_rendimento'] ?? '') === 'l') ? 'selected' : '' ?>>
                            Litros (l)
                        </option>

                        <option value="un"
                            <?= (($_POST['unidade_rendimento'] ?? '') === 'un') ? 'selected' : '' ?>>
                            Unidades (un)
                        </option>

                    </select>

                </div>

            </div>

            <div class="campo">

                <label for="descricao">
                    Descrição
                </label>

                <textarea
                    id="descricao"
                    name="descricao"
                    placeholder="Observações, modo de preparo, informações da receita..."
                ><?= htmlspecialchars($_POST['descricao'] ?? '') ?></textarea>

            </div>

            <div class="botoes">

                <button type="submit">
                    Cadastrar Receita
                </button>

                <a
                    href="receitas.php"
                    class="voltar"
                >
                    Voltar
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>