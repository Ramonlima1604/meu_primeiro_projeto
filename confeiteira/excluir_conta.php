<?php

session_start();

require_once "../config/banco.php";

/*
|--------------------------------------------------------------------------
| VERIFICAR LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['csrf_excluir_conta'])) {
    $_SESSION['csrf_excluir_conta'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_excluir_conta'];

/*
|--------------------------------------------------------------------------
| BUSCAR USUÁRIO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome,
        email,
        status
    FROM usuarios
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {

    $_SESSION = [];

    session_destroy();

    header("Location: ../index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        usuario_id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Não foi possível localizar os dados da confeiteira.");
}

/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$erro = "";

$etapa = isset($_POST['etapa'])
    ? (int) $_POST['etapa']
    : 1;

if ($etapa !== 2) {
    $etapa = 1;
}

$motivo = "";

/*
|--------------------------------------------------------------------------
| PROCESSAMENTO
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDAR CSRF
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_POST['csrf']) ||
        !hash_equals(
            $_SESSION['csrf_excluir_conta'],
            $_POST['csrf']
        )
    ) {

        $erro = "Sessão de segurança inválida. Atualize a página e tente novamente.";

    }

    /*
    |--------------------------------------------------------------------------
    | ETAPA 1
    |--------------------------------------------------------------------------
    */

    elseif ($etapa === 1) {

        $etapa = 2;

    }

    /*
    |--------------------------------------------------------------------------
    | ETAPA 2
    |--------------------------------------------------------------------------
    */

    elseif ($etapa === 2) {

        $motivo = trim($_POST['motivo'] ?? '');

        if ($motivo === '') {

            $erro = "Informe o motivo da exclusão da conta.";

        } elseif (mb_strlen($motivo) < 5) {

            $erro = "O motivo deve possuir pelo menos 5 caracteres.";

        } elseif (mb_strlen($motivo) > 2000) {

            $erro = "O motivo pode possuir no máximo 2000 caracteres.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | INICIAR TRANSAÇÃO
                |--------------------------------------------------------------------------
                */

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | REGISTRAR SOLICITAÇÃO
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO solicitacoes_exclusao_conta
                    (
                        usuario_id,
                        confeiteira_id,
                        nome,
                        email,
                        motivo
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

                $stmt->execute([
                    $usuario['id'],
                    $confeiteira['id'],
                    $usuario['nome'],
                    $usuario['email'],
                    $motivo
                ]);

                /*
                |--------------------------------------------------------------------------
                | DESATIVAR CONTA
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE usuarios
                    SET status = 0
                    WHERE id = ?
                ");

                $stmt->execute([
                    $usuario['id']
                ]);

                /*
                |--------------------------------------------------------------------------
                | CONFIRMAR
                |--------------------------------------------------------------------------
                */

                $pdo->commit();

                /*
                |--------------------------------------------------------------------------
                | ENCERRAR SESSÃO
                |--------------------------------------------------------------------------
                */

                $_SESSION = [];

                if (ini_get("session.use_cookies")) {

                    $params = session_get_cookie_params();

                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params["path"],
                        $params["domain"],
                        $params["secure"],
                        $params["httponly"]
                    );
                }

                session_destroy();

                /*
                |--------------------------------------------------------------------------
                | VOLTAR PARA A TELA INICIAL
                |--------------------------------------------------------------------------
                */

                header(
                    "Location: ../index.php?conta_excluida=1"
                );

                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    "Erro ao excluir conta: " .
                    $e->getMessage()
                );

                $erro =
                    "Não foi possível concluir a exclusão da conta. " .
                    "Tente novamente.";
            }
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

    <title>Excluir conta</title>

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
            width: 100%;
            max-width: 700px;
            margin: 40px auto;
            padding: 20px;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 30px;

            box-shadow:
                0 5px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 28px;
        }

        h2 {
            margin-top: 0;
            font-size: 21px;
        }

        .subtitulo {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .alerta {
            background: #fff1f1;
            border: 1px solid #f1b5b5;
            color: #8b0000;

            border-radius: 10px;
            padding: 16px;

            margin-bottom: 22px;

            line-height: 1.5;
        }

        .info {
            background: #f7f7f7;
            border-radius: 10px;

            padding: 16px;

            margin-bottom: 22px;
        }

        .info p {
            margin: 7px 0;
        }

        .info strong {
            display: inline-block;
            min-width: 120px;
        }

        .etapas {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .etapa {
            flex: 1;

            text-align: center;

            padding: 10px;

            border-radius: 8px;

            background: #e9ecef;
            color: #666;

            font-size: 14px;
        }

        .etapa.ativa {
            background: #dc3545;
            color: #fff;
            font-weight: bold;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        textarea {
            width: 100%;

            min-height: 150px;

            resize: vertical;

            padding: 12px;

            border: 1px solid #ccc;
            border-radius: 8px;

            font-family: Arial, Helvetica, sans-serif;
            font-size: 15px;
        }

        textarea:focus {
            outline: none;
            border-color: #dc3545;
        }

        .erro {
            background: #ffe5e5;
            border: 1px solid #ffb3b3;

            color: #a00000;

            padding: 12px;

            border-radius: 8px;

            margin-bottom: 20px;
        }

        .botoes {
            display: flex;
            gap: 12px;

            margin-top: 25px;

            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;

            padding: 13px 20px;

            border-radius: 8px;

            border: none;

            text-decoration: none;

            cursor: pointer;

            font-size: 15px;

            font-weight: bold;

            text-align: center;
        }

        .btn-cancelar {
            background: #e9ecef;
            color: #333;
        }

        .btn-excluir {
            background: #dc3545;
            color: #fff;
        }

        .btn-excluir:hover {
            background: #bb2d3b;
        }

        .btn-cancelar:hover {
            background: #d8dadd;
        }

        .aviso-final {
            margin-top: 18px;

            color: #777;

            font-size: 13px;

            line-height: 1.5;
        }

        @media (max-width: 600px) {

            .container {
                margin: 15px auto;
                padding: 12px;
            }

            .card {
                padding: 20px;
            }

            h1 {
                font-size: 23px;
            }

            .botoes {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="card">

        <h1>
            Excluir minha conta
        </h1>

        <p class="subtitulo">
            Antes de continuar, confira as informações abaixo.
        </p>

        <div class="etapas">

            <div class="etapa <?= $etapa === 1 ? 'ativa' : '' ?>">
                1. Confirmar
            </div>

            <div class="etapa <?= $etapa === 2 ? 'ativa' : '' ?>">
                2. Motivo
            </div>

        </div>

        <?php if ($erro): ?>

            <div class="erro">

                <?= htmlspecialchars(
                    $erro,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>

        <div class="alerta">

            <strong>Atenção!</strong>

            <br><br>

            Ao confirmar, sua conta será desativada e você
            não poderá mais acessar o sistema.

            <br><br>

            Seus dados relacionados aos pedidos, produtos,
            clientes e financeiro não serão apagados automaticamente.

        </div>

        <div class="info">

            <p>

                <strong>Nome:</strong>

                <?= htmlspecialchars(
                    $usuario['nome'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </p>

            <p>

                <strong>E-mail:</strong>

                <?= htmlspecialchars(
                    $usuario['email'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </p>

            <p>

                <strong>Confeitaria:</strong>

                <?= htmlspecialchars(
                    $confeiteira['nome_confeitaria'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </p>

        </div>

        <?php if ($etapa === 1): ?>

            <h2>
                Você realmente deseja excluir sua conta?
            </h2>

            <p class="subtitulo">

                Essa ação irá desativar seu acesso ao sistema.

                Na próxima etapa você deverá informar
                o motivo da solicitação.

            </p>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars(
                        $csrf,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="etapa"
                    value="1"
                >

                <div class="botoes">

                    <a
                        href="minha_conta.php"
                        class="btn btn-cancelar"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="btn btn-excluir"
                    >
                        Sim, quero continuar
                    </button>

                </div>

            </form>

        <?php else: ?>

            <h2>
                Por que você deseja excluir sua conta?
            </h2>

            <p class="subtitulo">

                Sua resposta será registrada junto com
                a solicitação de exclusão da conta.

            </p>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars(
                        $csrf,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="etapa"
                    value="2"
                >

                <label for="motivo">
                    Motivo da exclusão
                </label>

                <textarea
                    name="motivo"
                    id="motivo"
                    maxlength="2000"
                    required
                    placeholder="Informe o motivo da exclusão da conta..."
                ><?= htmlspecialchars(
                    $motivo,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></textarea>

                <div class="aviso-final">

                    Depois de confirmar, sua conta será
                    desativada imediatamente e sua sessão
                    será encerrada.

                </div>

                <div class="botoes">

                    <a
                        href="minha_conta.php"
                        class="btn btn-cancelar"
                    >
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="btn btn-excluir"
                        onclick="return confirm('Tem certeza que deseja excluir sua conta?');"
                    >
                        Confirmar exclusão da conta
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>

</body>

</html>