<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("admin");

$erro = "";
$sucesso = "";


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"]);
    $nome_confeitaria = trim($_POST["nome_confeitaria"]);
    $email = trim($_POST["email"]);
    $telefone = trim($_POST["telefone"]);
    $cidade = trim($_POST["cidade"]);
    $senha = $_POST["senha"];


    if (
        $nome === "" ||
        $nome_confeitaria === "" ||
        $email === "" ||
        $senha === ""
    ) {

        $erro = "Preencha todos os campos obrigatórios.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $erro = "Digite um e-mail válido.";

    } elseif (strlen($senha) < 6) {

        $erro = "A senha deve ter pelo menos 6 caracteres.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | VERIFICAR E-MAIL
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {

            $erro = "Este e-mail já está cadastrado.";

        } else {

            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | CRIAR USUÁRIO
                |--------------------------------------------------------------------------
                */

                $senhaHash = password_hash(
                    $senha,
                    PASSWORD_DEFAULT
                );


                $stmt = $pdo->prepare("
                    INSERT INTO usuarios
                    (
                        nome,
                        email,
                        senha,
                        tipo,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        'confeiteira',
                        1
                    )
                ");


                $stmt->execute([
                    $nome,
                    $email,
                    $senhaHash
                ]);


                $usuarioId = $pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | CRIAR CONFEITEIRA
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO confeiteiras
                    (
                        usuario_id,
                        nome_confeitaria,
                        telefone,
                        cidade
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


                $stmt->execute([
                    $usuarioId,
                    $nome_confeitaria,
                    $telefone,
                    $cidade
                ]);


                $pdo->commit();


                header(
                    "Location: confeiteiras.php"
                );

                exit;


            } catch (Exception $e) {

                $pdo->rollBack();

                $erro =
                    "Erro ao cadastrar: "
                    . $e->getMessage();
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Nova Confeiteira</title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <header class="topo">

        <div>

            <h1>Nova Confeiteira</h1>

            <p>
                Cadastrar uma nova profissional
            </p>

        </div>

        <a href="confeiteiras.php">
            Voltar
        </a>

    </header>


    <main>

        <div class="formulario-container">

            <?php if ($erro): ?>

                <div class="erro">
                    <?= htmlspecialchars($erro) ?>
                </div>

            <?php endif; ?>


            <form method="POST">


                <label>
                    Nome da confeiteira *
                </label>

                <input
                    type="text"
                    name="nome"
                    required
                >


                <label>
                    Nome da confeitaria *
                </label>

                <input
                    type="text"
                    name="nome_confeitaria"
                    required
                >


                <label>
                    E-mail *
                </label>

                <input
                    type="email"
                    name="email"
                    required
                >


                <label>
                    Telefone
                </label>

                <input
                    type="text"
                    name="telefone"
                >


                <label>
                    Cidade
                </label>

                <input
                    type="text"
                    name="cidade"
                >


                <label>
                    Senha inicial *
                </label>

                <input
                    type="password"
                    name="senha"
                    minlength="6"
                    required
                >


                <button
                    type="submit"
                    class="botao"
                >
                    Cadastrar Confeiteira
                </button>


            </form>

        </div>

    </main>

</div>

</body>

</html>