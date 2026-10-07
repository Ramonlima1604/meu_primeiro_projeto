<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("admin");


$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;


if ($id <= 0) {

    header("Location: confeiteiras.php");
    exit;

}


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        c.*,

        u.nome,
        u.email,
        u.status

    FROM confeiteiras c

    INNER JOIN usuarios u
        ON u.id = c.usuario_id

    WHERE c.id = ?

    LIMIT 1
");

$stmt->execute([$id]);

$confeiteira = $stmt->fetch();


if (!$confeiteira) {

    header("Location: confeiteiras.php");
    exit;

}


$erro = "";


/*
|--------------------------------------------------------------------------
| SALVAR ALTERAÇÕES
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nome = trim($_POST["nome"]);
    $nome_confeitaria =
        trim($_POST["nome_confeitaria"]);

    $email = trim($_POST["email"]);
    $telefone = trim($_POST["telefone"]);
    $cidade = trim($_POST["cidade"]);

    $novaSenha = $_POST["senha"];


    if (
        $nome === "" ||
        $nome_confeitaria === "" ||
        $email === ""
    ) {

        $erro =
            "Preencha os campos obrigatórios.";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $erro =
            "Digite um e-mail válido.";

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

            AND id <> ?

            LIMIT 1
        ");

        $stmt->execute([
            $email,
            $confeiteira["usuario_id"]
        ]);


        if ($stmt->fetch()) {

            $erro =
                "Este e-mail já pertence a outro usuário.";

        } else {

            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | ATUALIZAR USUÁRIO
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE usuarios

                    SET
                        nome = ?,
                        email = ?

                    WHERE id = ?
                ");

                $stmt->execute([
                    $nome,
                    $email,
                    $confeiteira["usuario_id"]
                ]);


                /*
                |--------------------------------------------------------------------------
                | ATUALIZAR CONFEITEIRA
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE confeiteiras

                    SET
                        nome_confeitaria = ?,
                        telefone = ?,
                        cidade = ?

                    WHERE id = ?
                ");

                $stmt->execute([
                    $nome_confeitaria,
                    $telefone,
                    $cidade,
                    $id
                ]);


                /*
                |--------------------------------------------------------------------------
                | ALTERAR SENHA
                |--------------------------------------------------------------------------
                */

                if ($novaSenha !== "") {

                    if (strlen($novaSenha) < 6) {

                        throw new Exception(
                            "A nova senha deve ter pelo menos 6 caracteres."
                        );
                    }


                    $senhaHash = password_hash(
                        $novaSenha,
                        PASSWORD_DEFAULT
                    );


                    $stmt = $pdo->prepare("
                        UPDATE usuarios

                        SET senha = ?

                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $senhaHash,
                        $confeiteira["usuario_id"]
                    ]);
                }


                $pdo->commit();


                header(
                    "Location: confeiteiras.php"
                );

                exit;


            } catch (Exception $e) {

                $pdo->rollBack();

                $erro =
                    $e->getMessage();
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Editar Confeiteira
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

</head>

<body>

<div class="admin-container">


    <header class="topo">

        <div>

            <h1>
                Editar Confeiteira
            </h1>

            <p>
                Alterar informações
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
                    Nome *
                </label>

                <input
                    type="text"
                    name="nome"
                    value="<?= htmlspecialchars(
                        $confeiteira["nome"]
                    ) ?>"
                    required
                >


                <label>
                    Nome da confeitaria *
                </label>

                <input
                    type="text"
                    name="nome_confeitaria"
                    value="<?= htmlspecialchars(
                        $confeiteira["nome_confeitaria"]
                    ) ?>"
                    required
                >


                <label>
                    E-mail *
                </label>

                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars(
                        $confeiteira["email"]
                    ) ?>"
                    required
                >


                <label>
                    Telefone
                </label>

                <input
                    type="text"
                    name="telefone"
                    value="<?= htmlspecialchars(
                        $confeiteira["telefone"]
                    ) ?>"
                >


                <label>
                    Cidade
                </label>

                <input
                    type="text"
                    name="cidade"
                    value="<?= htmlspecialchars(
                        $confeiteira["cidade"]
                    ) ?>"
                >


                <label>
                    Nova senha
                </label>

                <input
                    type="password"
                    name="senha"
                    minlength="6"
                >

                <small>
                    Deixe em branco para manter a senha atual.
                </small>


                <br><br>


                <button
                    type="submit"
                    class="botao"
                >
                    Salvar Alterações
                </button>


            </form>

        </div>

    </main>

</div>

</body>

</html>