<?php

require_once "config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$erros = [];

$nome = "";
$email = "";
$nome_confeitaria = "";
$documento_tipo = "";
$documento = "";
$telefone = "";
$cidade = "";
$estado = "";
$descricao = "";

$senha = "";
$confirmar_senha = "";


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_cadastro_confeiteira"])) {

    $_SESSION["csrf_cadastro_confeiteira"] =
        bin2hex(random_bytes(32));
}

$csrf =
    $_SESSION["csrf_cadastro_confeiteira"];


/*
|--------------------------------------------------------------------------
| FUNÇÃO DE LIMPEZA
|--------------------------------------------------------------------------
*/

function limpar($valor)
{
    return trim((string) $valor);
}


/*
|--------------------------------------------------------------------------
| NORMALIZAR NOME
|--------------------------------------------------------------------------
*/

function normalizarNome($valor)
{
    $valor = trim((string) $valor);

    $valor = preg_replace('/\s+/', ' ', $valor);

    return $valor;
}


/*
|--------------------------------------------------------------------------
| NORMALIZAR NÚMEROS
|--------------------------------------------------------------------------
*/

function somenteNumeros($valor)
{
    return preg_replace('/\D/', '', (string) $valor);
}


/*
|--------------------------------------------------------------------------
| VALIDAR CPF
|--------------------------------------------------------------------------
*/

function validarCPF($cpf)
{
    $cpf = somenteNumeros($cpf);

    if (strlen($cpf) !== 11) {
        return false;
    }

    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    $soma = 0;

    for ($i = 0; $i < 9; $i++) {

        $soma +=
            (int) $cpf[$i] *
            (10 - $i);
    }

    $resto = $soma % 11;

    $digito1 =
        ($resto < 2)
            ? 0
            : 11 - $resto;

    if ((int) $cpf[9] !== $digito1) {
        return false;
    }

    $soma = 0;

    for ($i = 0; $i < 10; $i++) {

        $soma +=
            (int) $cpf[$i] *
            (11 - $i);
    }

    $resto = $soma % 11;

    $digito2 =
        ($resto < 2)
            ? 0
            : 11 - $resto;

    if ((int) $cpf[10] !== $digito2) {
        return false;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| VALIDAR CNPJ
|--------------------------------------------------------------------------
*/

function validarCNPJ($cnpj)
{
    $cnpj = somenteNumeros($cnpj);

    if (strlen($cnpj) !== 14) {
        return false;
    }

    if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $pesos1 = [
        5, 4, 3, 2,
        9, 8, 7, 6,
        5, 4, 3, 2
    ];

    $soma = 0;

    for ($i = 0; $i < 12; $i++) {

        $soma +=
            (int) $cnpj[$i] *
            $pesos1[$i];
    }

    $resto = $soma % 11;

    $digito1 =
        ($resto < 2)
            ? 0
            : 11 - $resto;

    if ((int) $cnpj[12] !== $digito1) {
        return false;
    }

    $pesos2 = [
        6, 5, 4, 3, 2,
        9, 8, 7, 6, 5, 4, 3, 2
    ];

    $soma = 0;

    for ($i = 0; $i < 13; $i++) {

        $soma +=
            (int) $cnpj[$i] *
            $pesos2[$i];
    }

    $resto = $soma % 11;

    $digito2 =
        ($resto < 2)
            ? 0
            : 11 - $resto;

    if ((int) $cnpj[13] !== $digito2) {
        return false;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| FORMATAR CPF
|--------------------------------------------------------------------------
*/

function formatarCPF($cpf)
{
    $cpf = somenteNumeros($cpf);

    if (strlen($cpf) !== 11) {
        return $cpf;
    }

    return
        substr($cpf, 0, 3) . "." .
        substr($cpf, 3, 3) . "." .
        substr($cpf, 6, 3) . "-" .
        substr($cpf, 9, 2);
}


/*
|--------------------------------------------------------------------------
| FORMATAR CNPJ
|--------------------------------------------------------------------------
*/

function formatarCNPJ($cnpj)
{
    $cnpj = somenteNumeros($cnpj);

    if (strlen($cnpj) !== 14) {
        return $cnpj;
    }

    return
        substr($cnpj, 0, 2) . "." .
        substr($cnpj, 2, 3) . "." .
        substr($cnpj, 5, 3) . "/" .
        substr($cnpj, 8, 4) . "-" .
        substr($cnpj, 12, 2);
}


/*
|--------------------------------------------------------------------------
| FORMATAR TELEFONE
|--------------------------------------------------------------------------
*/

function formatarTelefone($telefone)
{
    $telefone = somenteNumeros($telefone);

    if (strlen($telefone) === 11) {

        return
            "(" .
            substr($telefone, 0, 2) .
            ") " .
            substr($telefone, 2, 5) .
            "-" .
            substr($telefone, 7, 4);
    }

    if (strlen($telefone) === 10) {

        return
            "(" .
            substr($telefone, 0, 2) .
            ") " .
            substr($telefone, 2, 4) .
            "-" .
            substr($telefone, 6, 4);
    }

    return $telefone;
}


/*
|--------------------------------------------------------------------------
| PROCESSAR CADASTRO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrf_form =
        $_POST["csrf_token"] ?? "";

    if (
        empty($csrf_form) ||
        !hash_equals(
            $_SESSION["csrf_cadastro_confeiteira"],
            $csrf_form
        )
    ) {

        $erros[] =
            "Sessão expirada. Atualize a página e tente novamente.";
    }


    /*
    |--------------------------------------------------------------------------
    | RECEBER DADOS
    |--------------------------------------------------------------------------
    */

    $nome =
        limpar(
            $_POST["nome"] ?? ""
        );

    $email =
        strtolower(
            limpar(
                $_POST["email"] ?? ""
            )
        );

    $nome_confeitaria =
        normalizarNome(
            $_POST["nome_confeitaria"] ?? ""
        );

    $documento_tipo =
        strtolower(
            limpar(
                $_POST["documento_tipo"] ?? ""
            )
        );

    $documento =
        somenteNumeros(
            $_POST["documento"] ?? ""
        );

    $telefone =
        somenteNumeros(
            $_POST["telefone"] ?? ""
        );

    $cidade =
        limpar(
            $_POST["cidade"] ?? ""
        );

    /*
    |--------------------------------------------------------------------------
    | ESTADO / UF
    |--------------------------------------------------------------------------
    */

    $estado =
        strtoupper(
            limpar(
                $_POST["estado"] ?? ""
            )
        );

    $descricao =
        limpar(
            $_POST["descricao"] ?? ""
        );

    $senha =
        $_POST["senha"] ?? "";

    $confirmar_senha =
        $_POST["confirmar_senha"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | VALIDAR NOME
    |--------------------------------------------------------------------------
    */

    if ($nome === "") {

        $erros[] =
            "Informe seu nome.";

    } elseif (mb_strlen($nome) < 2) {

        $erros[] =
            "Seu nome deve possuir pelo menos 2 caracteres.";

    } elseif (mb_strlen($nome) > 150) {

        $erros[] =
            "Seu nome não pode ultrapassar 150 caracteres.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR E-MAIL
    |--------------------------------------------------------------------------
    */

    if ($email === "") {

        $erros[] =
            "Informe seu e-mail.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $erros[] =
            "Informe um e-mail válido.";

    } elseif (mb_strlen($email) > 150) {

        $erros[] =
            "O e-mail não pode ultrapassar 150 caracteres.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR NOME DA CONFEITARIA
    |--------------------------------------------------------------------------
    */

    if ($nome_confeitaria === "") {

        $erros[] =
            "Informe o nome da confeitaria.";

    } elseif (mb_strlen($nome_confeitaria) > 150) {

        $erros[] =
            "O nome da confeitaria não pode ultrapassar 150 caracteres.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR TIPO DE DOCUMENTO
    |--------------------------------------------------------------------------
    */

    if (
        $documento_tipo !== "cpf" &&
        $documento_tipo !== "cnpj"
    ) {

        $erros[] =
            "Selecione se o documento é CPF ou CNPJ.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR DOCUMENTO
    |--------------------------------------------------------------------------
    */

    if ($documento === "") {

        $erros[] =
            "Informe seu CPF ou CNPJ.";

    } elseif ($documento_tipo === "cpf") {

        if (strlen($documento) !== 11) {

            $erros[] =
                "O CPF deve possuir 11 números.";

        } elseif (!validarCPF($documento)) {

            $erros[] =
                "O CPF informado é inválido.";
        }

    } elseif ($documento_tipo === "cnpj") {

        if (strlen($documento) !== 14) {

            $erros[] =
                "O CNPJ deve possuir 14 números.";

        } elseif (!validarCNPJ($documento)) {

            $erros[] =
                "O CNPJ informado é inválido.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR TELEFONE
    |--------------------------------------------------------------------------
    */

    if ($telefone === "") {

        $erros[] =
            "Informe seu telefone.";

    } elseif (
        strlen($telefone) !== 10 &&
        strlen($telefone) !== 11
    ) {

        $erros[] =
            "Informe um telefone válido.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR CIDADE
    |--------------------------------------------------------------------------
    */

    if ($cidade === "") {

        $erros[] =
            "Informe sua cidade.";

    } elseif (mb_strlen($cidade) > 100) {

        $erros[] =
            "O nome da cidade não pode ultrapassar 100 caracteres.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR ESTADO
    |--------------------------------------------------------------------------
    */

    $estados_validos = [
        "AC", "AL", "AP", "AM",
        "BA", "CE", "DF", "ES",
        "GO", "MA", "MT", "MS",
        "MG", "PA", "PB", "PR",
        "PE", "PI", "RJ", "RN",
        "RS", "RO", "RR", "SC",
        "SP", "SE", "TO"
    ];

    if ($estado === "") {

        $erros[] =
            "Selecione seu estado.";

    } elseif (!in_array($estado, $estados_validos, true)) {

        $erros[] =
            "Selecione um estado válido.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR DESCRIÇÃO
    |--------------------------------------------------------------------------
    */

    if (mb_strlen($descricao) > 1000) {

        $erros[] =
            "A descrição não pode ultrapassar 1000 caracteres.";
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR SENHA
    |--------------------------------------------------------------------------
    */

    if ($senha === "") {

        $erros[] =
            "Informe uma senha.";

    } else {

        if (strlen($senha) < 8) {

            $erros[] =
                "A senha deve possuir pelo menos 8 caracteres.";
        }

        if (!preg_match('/[A-Z]/', $senha)) {

            $erros[] =
                "A senha deve possuir pelo menos uma letra maiúscula.";
        }

        if (!preg_match('/[a-z]/', $senha)) {

            $erros[] =
                "A senha deve possuir pelo menos uma letra minúscula.";
        }

        if (!preg_match('/[0-9]/', $senha)) {

            $erros[] =
                "A senha deve possuir pelo menos um número.";
        }

        if (!preg_match('/[^A-Za-z0-9]/', $senha)) {

            $erros[] =
                "A senha deve possuir pelo menos um símbolo.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR SENHA
    |--------------------------------------------------------------------------
    */

    if ($confirmar_senha === "") {

        $erros[] =
            "Confirme sua senha.";

    } elseif ($senha !== $confirmar_senha) {

        $erros[] =
            "As senhas não coincidem.";
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR DUPLICIDADES
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        try {

            /*
            |--------------------------------------------------------------------------
            | E-MAIL
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    "SELECT id
                     FROM usuarios
                     WHERE email = ?
                     LIMIT 1"
                );

            $stmt->execute([
                $email
            ]);

            if ($stmt->fetch()) {

                $erros[] =
                    "Este e-mail já está cadastrado.";
            }


            /*
            |--------------------------------------------------------------------------
            | NOME DA CONFEITARIA
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    "SELECT id
                     FROM confeiteiras
                     WHERE nome_confeitaria = ?
                     LIMIT 1"
                );

            $stmt->execute([
                $nome_confeitaria
            ]);

            if ($stmt->fetch()) {

                $erros[] =
                    "Já existe uma confeitaria cadastrada com este nome.";
            }


            /*
            |--------------------------------------------------------------------------
            | TELEFONE
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    "SELECT id
                     FROM confeiteiras
                     WHERE telefone = ?
                     LIMIT 1"
                );

            $stmt->execute([
                $telefone
            ]);

            if ($stmt->fetch()) {

                $erros[] =
                    "Este telefone já está cadastrado.";
            }


            /*
            |--------------------------------------------------------------------------
            | DOCUMENTO
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    "SELECT id
                     FROM confeiteiras
                     WHERE documento = ?
                     LIMIT 1"
                );

            $stmt->execute([
                $documento
            ]);

            if ($stmt->fetch()) {

                $erros[] =
                    "Este CPF/CNPJ já está cadastrado.";
            }

        } catch (PDOException $e) {

            $erros[] =
                "Não foi possível verificar os dados. Tente novamente.";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CADASTRAR
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | CRIAR USUÁRIO
            |--------------------------------------------------------------------------
            */

            $senha_hash =
                password_hash(
                    $senha,
                    PASSWORD_DEFAULT
                );

            $stmt =
                $pdo->prepare(
                    "INSERT INTO usuarios
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
                    )"
                );

            $stmt->execute([
                $nome,
                $email,
                $senha_hash
            ]);

            $usuario_id =
                $pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | CRIAR CONFEITEIRA
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare(
                    "INSERT INTO confeiteiras
                    (
                        usuario_id,
                        nome_confeitaria,
                        telefone,
                        cidade,
                        estado,
                        descricao,
                        documento
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )"
                );

            $stmt->execute([
                $usuario_id,
                $nome_confeitaria,
                $telefone,
                $cidade,
                $estado,
                $descricao,
                $documento
            ]);


            /*
            |--------------------------------------------------------------------------
            | FINALIZAR TRANSAÇÃO
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | LOGIN AUTOMÁTICO
            |--------------------------------------------------------------------------
            */

            session_regenerate_id(true);

            $_SESSION["usuario_id"] =
                $usuario_id;

            $_SESSION["usuario_nome"] =
                $nome;

            $_SESSION["usuario_email"] =
                $email;

            $_SESSION["usuario_tipo"] =
                "confeiteira";


            header(
                "Location: /sistema_bolos/confeiteira/index.php"
            );

            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erros[] =
                "Não foi possível concluir o cadastro. Tente novamente.";
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

    <title>
        Cadastro de Confeiteira - Sistema de Bolos
    </title>

    <link
        rel="stylesheet"
        href="assets/style.css"
    >

    <style>

        .cadastro-box {
            width: 100%;
            max-width: 620px;
        }

        .cadastro-titulo {
            text-align: center;
            margin-bottom: 25px;
        }

        .cadastro-titulo h1 {
            margin-bottom: 8px;
        }

        .cadastro-titulo p {
            margin: 0;
            color: #666;
        }

        .secao-cadastro {
            margin-top: 25px;
            margin-bottom: 20px;
        }

        .secao-cadastro h2 {
            font-size: 18px;
            margin: 0 0 5px;
            color: #333;
        }

        .secao-cadastro p {
            margin: 0 0 15px;
            font-size: 13px;
            color: #777;
        }

        .campo {
            margin-bottom: 15px;
        }

        .campo label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .campo input,
        .campo textarea,
        .campo select {

            width: 100%;
            padding: 11px 12px;

            border: 1px solid #d1d5db;
            border-radius: 8px;

            font-size: 15px;
            font-family: Arial, Helvetica, sans-serif;

            box-sizing: border-box;
            background: #fff;
        }

        .campo input:focus,
        .campo textarea:focus,
        .campo select:focus {

            outline: none;

            border-color: #d97700;

            box-shadow:
                0 0 0 2px
                rgba(217, 119, 0, 0.10);
        }

        .campo textarea {
            min-height: 100px;
            resize: vertical;
        }

        .campo-ajuda {
            margin-top: 5px;
            font-size: 12px;
            color: #777;
        }


        /*
        |--------------------------------------------------------------------------
        | DOCUMENTO
        |--------------------------------------------------------------------------
        */

        .documento-opcoes {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 10px;

            margin-bottom: 10px;
        }

        .documento-opcao {

            position: relative;
        }

        .documento-opcao input {

            position: absolute;

            opacity: 0;

            pointer-events: none;
        }

        .documento-opcao label {

            display: flex;

            align-items: center;
            justify-content: center;

            min-height: 44px;

            padding: 10px;

            border: 1px solid #d1d5db;

            border-radius: 8px;

            cursor: pointer;

            background: #fff;

            transition: 0.2s;

            text-align: center;
        }

        .documento-opcao input:checked + label {

            border-color: #d97700;

            background: #fff7ed;

            color: #b45309;

            font-weight: bold;
        }

        .documento-opcao label:hover {

            background: #f9fafb;
        }


        /*
        |--------------------------------------------------------------------------
        | SENHA
        |--------------------------------------------------------------------------
        */

        .senha-wrapper {
            position: relative;
            width: 100%;
        }

        .senha-wrapper input {
            padding-right: 55px !important;
        }

        .senha-wrapper .mostrar-senha {

            position: absolute !important;

            top: 0 !important;

            right: 5px !important;

            width: 42px !important;

            height: 100% !important;

            min-width: 42px !important;

            margin: 0 !important;

            padding: 0 !important;

            border: none !important;

            border-radius: 7px !important;

            background: transparent !important;

            color: #555 !important;

            cursor: pointer;

            display: flex !important;

            align-items: center !important;

            justify-content: center !important;

            line-height: 1 !important;

            box-shadow: none !important;
        }

        .senha-wrapper .mostrar-senha:hover {

            background: #f3f4f6 !important;

            color: #222 !important;
        }

        .senha-wrapper .mostrar-senha:focus {

            outline: none !important;

            background: #f3f4f6 !important;

            color: #222 !important;
        }

        .icone-olho {

            width: 21px;
            height: 21px;

            display: block;

            pointer-events: none;
        }


        /*
        |--------------------------------------------------------------------------
        | REQUISITOS SENHA
        |--------------------------------------------------------------------------
        */

        .senha-requisitos {

            margin-top: 10px;

            padding: 12px;

            background: #f9fafb;

            border: 1px solid #e5e7eb;

            border-radius: 8px;

            font-size: 13px;
        }

        .senha-requisitos-titulo {

            margin-bottom: 8px;

            font-weight: bold;

            color: #444;
        }

        .requisito {

            margin: 5px 0;

            color: #777;

            transition: 0.2s;
        }

        .requisito.ok {

            color: #15803d;

            font-weight: bold;
        }

        .icone-requisito {

            display: inline-block;

            width: 20px;

            font-weight: bold;
        }


        /*
        |--------------------------------------------------------------------------
        | ERROS
        |--------------------------------------------------------------------------
        */

        .erro-cadastro {

            background: #fee2e2;

            border: 1px solid #fecaca;

            color: #991b1b;

            border-radius: 8px;

            padding: 12px 15px;

            margin-bottom: 20px;

            font-size: 14px;
        }

        .erro-cadastro strong {

            display: block;

            margin-bottom: 6px;
        }

        .erro-cadastro ul {

            margin: 0;

            padding-left: 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | SUCESSO VISUAL
        |--------------------------------------------------------------------------
        */

        .campo-documento-ajuda {

            margin-top: 5px;

            font-size: 12px;

            color: #777;
        }


        /*
        |--------------------------------------------------------------------------
        | RODAPÉ
        |--------------------------------------------------------------------------
        */

        .rodape-cadastro {

            margin-top: 22px;

            text-align: center;
        }

        .rodape-cadastro a {

            text-decoration: none;

            font-weight: bold;
        }

        .link-login {

            color: #d97700;
        }

        .link-voltar {

            display: inline-block;

            margin-top: 14px;

            color: #777;

            font-size: 14px;
        }

        .obrigatorio {

            color: #c00;
        }


        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 600px) {

            .cadastro-box {
                max-width: 100%;
            }

            .campo input,
            .campo textarea,
            .campo select {
                font-size: 16px;
            }

            .senha-wrapper .mostrar-senha {

                width: 44px !important;

                min-width: 44px !important;
            }

        }

    </style>

</head>


<body>


<div class="login-container">


    <div class="login-box cadastro-box">


        <!-- =====================================================
             TÍTULO
        ====================================================== -->

        <div class="cadastro-titulo">

            <h1>
                Criar conta
            </h1>

            <p>
                Cadastre sua confeitaria gratuitamente
            </p>

        </div>


        <!-- =====================================================
             ERROS
        ====================================================== -->

        <?php if (!empty($erros)): ?>

            <div class="erro-cadastro">

                <strong>
                    Não foi possível concluir o cadastro:
                </strong>

                <ul>

                    <?php foreach ($erros as $erro): ?>

                        <li>

                            <?= htmlspecialchars(
                                $erro,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             FORMULÁRIO
        ====================================================== -->

        <form
            method="POST"
            action=""
            autocomplete="on"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >


            <!-- =================================================
                 DADOS DA CONTA
            ================================================== -->

            <div class="secao-cadastro">

                <h2>
                    Seus dados
                </h2>

                <p>
                    Essas informações serão utilizadas para acessar sua conta.
                </p>

            </div>


            <!-- NOME -->

            <div class="campo">

                <label for="nome">

                    Nome completo

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <input
                    type="text"
                    id="nome"
                    name="nome"
                    maxlength="150"
                    required
                    autocomplete="name"
                    value="<?= htmlspecialchars(
                        $nome,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <!-- EMAIL -->

            <div class="campo">

                <label for="email">

                    E-mail

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    required
                    autocomplete="email"
                    value="<?= htmlspecialchars(
                        $email,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <!-- =================================================
                 SENHA
            ================================================== -->

            <div class="campo">

                <label for="senha">

                    Senha

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <div class="senha-wrapper">

                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        minlength="8"
                        maxlength="255"
                        required
                        autocomplete="new-password"
                        aria-describedby="senha-requisitos"
                    >

                    <button
                        type="button"
                        class="mostrar-senha"
                        data-target="senha"
                        aria-label="Mostrar senha"
                        title="Mostrar senha"
                    >

                        <svg
                            class="icone-olho"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >

                            <path
                                d="M3 3L21 21"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.58 10.58
                                   A2 2 0 0 0
                                   13.42 13.42"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M9.88 5.09
                                   C11.18 4.7
                                   12.57 4.5
                                   14 4.5
                                   C19 4.5
                                   22 9
                                   22 9
                                   C21.39 9.91
                                   20.39 11.15
                                   19.05 12.29"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M6.61 6.61
                                   C4.65 7.83
                                   3 9
                                   2 12
                                   C2 12
                                   5 19.5
                                   12 19.5
                                   C13.57 19.5
                                   15 19.15
                                   16.27 18.57"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                        </svg>

                    </button>

                </div>


                <div
                    class="senha-requisitos"
                    id="senha-requisitos"
                >

                    <div class="senha-requisitos-titulo">
                        Sua senha deve conter:
                    </div>

                    <div
                        class="requisito"
                        id="req-tamanho"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 8 caracteres
                    </div>

                    <div
                        class="requisito"
                        id="req-maiuscula"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 letra maiúscula
                    </div>

                    <div
                        class="requisito"
                        id="req-minuscula"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 letra minúscula
                    </div>

                    <div
                        class="requisito"
                        id="req-numero"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 número
                    </div>

                    <div
                        class="requisito"
                        id="req-simbolo"
                    >
                        <span class="icone-requisito">○</span>
                        Pelo menos 1 símbolo
                    </div>

                </div>

            </div>


            <!-- =================================================
                 CONFIRMAR SENHA
            ================================================== -->

            <div class="campo">

                <label for="confirmar_senha">

                    Confirmar senha

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <div class="senha-wrapper">

                    <input
                        type="password"
                        id="confirmar_senha"
                        name="confirmar_senha"
                        minlength="8"
                        maxlength="255"
                        required
                        autocomplete="new-password"
                    >

                    <button
                        type="button"
                        class="mostrar-senha"
                        data-target="confirmar_senha"
                        aria-label="Mostrar senha"
                        title="Mostrar senha"
                    >

                        <svg
                            class="icone-olho"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >

                            <path
                                d="M3 3L21 21"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.58 10.58
                                   A2 2 0 0 0
                                   13.42 13.42"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M9.88 5.09
                                   C11.18 4.7
                                   12.57 4.5
                                   14 4.5
                                   C19 4.5
                                   22 9
                                   22 9
                                   C21.39 9.91
                                   20.39 11.15
                                   19.05 12.29"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                            <path
                                d="M6.61 6.61
                                   C4.65 7.83
                                   3 12
                                   2 12
                                   C2 12
                                   5 19.5
                                   12 19.5
                                   C13.57 19.5
                                   15 19.15
                                   16.27 18.57"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />

                        </svg>

                    </button>

                </div>

                <div
                    class="campo-ajuda"
                    id="mensagem-confirmacao"
                >
                    Digite novamente a mesma senha.
                </div>

            </div>


            <!-- =================================================
                 DADOS DA CONFEITARIA
            ================================================== -->

            <div class="secao-cadastro">

                <h2>
                    Dados da confeitaria
                </h2>

                <p>
                    Essas informações serão utilizadas no seu perfil e atendimento.
                </p>

            </div>


            <!-- NOME DA CONFEITARIA -->

            <div class="campo">

                <label for="nome_confeitaria">

                    Nome da confeitaria

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <input
                    type="text"
                    id="nome_confeitaria"
                    name="nome_confeitaria"
                    maxlength="150"
                    required
                    autocomplete="organization"
                    value="<?= htmlspecialchars(
                        $nome_confeitaria,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div class="campo-ajuda">

                    Este nome será exclusivo no sistema.

                </div>

            </div>


            <!-- =================================================
                 CPF / CNPJ
            ================================================== -->

            <div class="campo">

                <label>

                    Documento

                    <span class="obrigatorio">
                        *
                    </span>

                </label>


                <div class="documento-opcoes">

                    <div class="documento-opcao">

                        <input
                            type="radio"
                            id="tipo_cpf"
                            name="documento_tipo"
                            value="cpf"
                            <?= $documento_tipo === "cpf"
                                ? "checked"
                                : "" ?>
                        >

                        <label for="tipo_cpf">
                            CPF
                        </label>

                    </div>


                    <div class="documento-opcao">

                        <input
                            type="radio"
                            id="tipo_cnpj"
                            name="documento_tipo"
                            value="cnpj"
                            <?= $documento_tipo === "cnpj"
                                ? "checked"
                                : "" ?>
                        >

                        <label for="tipo_cnpj">
                            CNPJ
                        </label>

                    </div>

                </div>


                <input
                    type="text"
                    id="documento"
                    name="documento"
                    maxlength="18"
                    required
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="Selecione CPF ou CNPJ"
                    value="<?= htmlspecialchars(
                        $documento_tipo === "cpf"
                            ? formatarCPF($documento)
                            : (
                                $documento_tipo === "cnpj"
                                    ? formatarCNPJ($documento)
                                    : $documento
                            ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div
                    class="campo-documento-ajuda"
                    id="ajuda-documento"
                >
                    Selecione CPF ou CNPJ.
                </div>

            </div>


            <!-- =================================================
                 TELEFONE
            ================================================== -->

            <div class="campo">

                <label for="telefone">

                    Telefone / WhatsApp

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <input
                    type="text"
                    id="telefone"
                    name="telefone"
                    maxlength="15"
                    required
                    inputmode="numeric"
                    autocomplete="tel"
                    placeholder="(00) 00000-0000"
                    value="<?= htmlspecialchars(
                        formatarTelefone($telefone),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div class="campo-ajuda">

                    O telefone deve ser único no sistema.

                </div>

            </div>


            <!-- CIDADE -->

            <div class="campo">

                <label for="cidade">

                    Cidade

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <input
                    type="text"
                    id="cidade"
                    name="cidade"
                    maxlength="100"
                    autocomplete="address-level2"
                    value="<?= htmlspecialchars(
                        $cidade,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

            </div>


            <!-- ESTADO -->

            <div class="campo">

                <label for="estado">

                    Estado

                    <span class="obrigatorio">
                        *
                    </span>

                </label>

                <select
                    id="estado"
                    name="estado"
                    required
                    autocomplete="address-level1"
                >

                    <option value="">
                        Selecione o estado
                    </option>

                    <?php

                    $listaEstados = [

                        "AC" => "Acre",
                        "AL" => "Alagoas",
                        "AP" => "Amapá",
                        "AM" => "Amazonas",
                        "BA" => "Bahia",
                        "CE" => "Ceará",
                        "DF" => "Distrito Federal",
                        "ES" => "Espírito Santo",
                        "GO" => "Goiás",
                        "MA" => "Maranhão",
                        "MT" => "Mato Grosso",
                        "MS" => "Mato Grosso do Sul",
                        "MG" => "Minas Gerais",
                        "PA" => "Pará",
                        "PB" => "Paraíba",
                        "PR" => "Paraná",
                        "PE" => "Pernambuco",
                        "PI" => "Piauí",
                        "RJ" => "Rio de Janeiro",
                        "RN" => "Rio Grande do Norte",
                        "RS" => "Rio Grande do Sul",
                        "RO" => "Rondônia",
                        "RR" => "Roraima",
                        "SC" => "Santa Catarina",
                        "SP" => "São Paulo",
                        "SE" => "Sergipe",
                        "TO" => "Tocantins"

                    ];

                    foreach (
                        $listaEstados
                        as $uf => $nomeEstado
                    ):

                    ?>

                        <option
                            value="<?= $uf ?>"
                            <?= $estado === $uf
                                ? "selected"
                                : "" ?>
                        >
                            <?= $uf ?> - <?= $nomeEstado ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <div class="campo-ajuda">

                    Selecione o estado onde sua confeitaria está localizada.

                </div>

            </div>


            <!-- DESCRIÇÃO -->

            <div class="campo">

                <label for="descricao">

                    Descrição da confeitaria

                </label>

                <textarea
                    id="descricao"
                    name="descricao"
                    maxlength="1000"
                    placeholder="Conte um pouco sobre sua confeitaria..."
                ><?= htmlspecialchars(
                    $descricao,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?></textarea>

                <div class="campo-ajuda">

                    Máximo de 1000 caracteres.

                </div>

            </div>


            <!-- BOTÃO -->

            <button
                type="submit"
            >
                Criar minha conta
            </button>


        </form>


        <!-- =====================================================
             RODAPÉ
        ====================================================== -->

        <div class="rodape-cadastro">

            <div>

                Já possui uma conta?

                <a
                    href="login.php"
                    class="link-login"
                >
                    Entrar
                </a>

            </div>


            <a
                href="index.php"
                class="link-voltar"
            >
                ← Voltar para o início
            </a>

        </div>


    </div>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {


        /*
        |--------------------------------------------------------------------------
        | MOSTRAR / OCULTAR SENHA
        |--------------------------------------------------------------------------
        */

        const botoes =
            document.querySelectorAll(
                ".mostrar-senha"
            );


        botoes.forEach(
            function (botao) {

                botao.addEventListener(
                    "click",
                    function () {

                        const target =
                            botao.getAttribute(
                                "data-target"
                            );

                        const campo =
                            document.getElementById(
                                target
                            );

                        const svg =
                            botao.querySelector(
                                ".icone-olho"
                            );


                        if (!campo) {
                            return;
                        }


                        if (
                            campo.type ===
                            "password"
                        ) {

                            campo.type =
                                "text";


                            svg.innerHTML = `

                                <path
                                    d="M2 12
                                       C2 12 5 5
                                       12 5
                                       C19 5 22 12
                                       22 12
                                       C22 12 19 19
                                       12 19
                                       C5 19 2 12
                                       2 12Z"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linejoin="round"
                                />

                                <circle
                                    cx="12"
                                    cy="12"
                                    r="3"
                                    stroke="currentColor"
                                    stroke-width="2"
                                />

                            `;


                            botao.setAttribute(
                                "aria-label",
                                "Ocultar senha"
                            );

                            botao.setAttribute(
                                "title",
                                "Ocultar senha"
                            );


                        } else {

                            campo.type =
                                "password";


                            svg.innerHTML = `

                                <path
                                    d="M3 3L21 21"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M10.58 10.58
                                       A2 2 0 0 0
                                       13.42 13.42"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M9.88 5.09
                                       C11.18 4.7
                                       12.57 4.5
                                       14 4.5
                                       C19 4.5
                                       22 9
                                       22 9
                                       C21.39 9.91
                                       20.39 11.15
                                       19.05 12.29"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M6.61 6.61
                                       C4.65 7.83
                                       3 12
                                       2 12
                                       C2 12
                                       5 19.5
                                       12 19.5
                                       C13.57 19.5
                                       15 19.15
                                       16.27 18.57"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />

                            `;


                            botao.setAttribute(
                                "aria-label",
                                "Mostrar senha"
                            );

                            botao.setAttribute(
                                "title",
                                "Mostrar senha"
                            );

                        }

                    }
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | DOCUMENTO
        |--------------------------------------------------------------------------
        */

        const documentoTipo =
            document.querySelectorAll(
                'input[name="documento_tipo"]'
            );

        const documento =
            document.getElementById(
                "documento"
            );

        const ajudaDocumento =
            document.getElementById(
                "ajuda-documento"
            );


        function obterTipoDocumento() {

            const selecionado =
                document.querySelector(
                    'input[name="documento_tipo"]:checked'
                );

            return selecionado
                ? selecionado.value
                : "";
        }


        function somenteNumerosJS(valor) {

            return valor.replace(
                /\D/g,
                ""
            );
        }


        function formatarDocumento() {

            const tipo =
                obterTipoDocumento();

            let valor =
                somenteNumerosJS(
                    documento.value
                );


            if (tipo === "cpf") {

                valor =
                    valor.substring(
                        0,
                        11
                    );


                if (valor.length > 9) {

                    valor =
                        valor.replace(
                            /(\d{3})(\d{3})(\d{3})(\d{0,2})/,
                            "$1.$2.$3-$4"
                        );

                } else if (valor.length > 6) {

                    valor =
                        valor.replace(
                            /(\d{3})(\d{3})(\d{0,3})/,
                            "$1.$2.$3"
                        );

                } else if (valor.length > 3) {

                    valor =
                        valor.replace(
                            /(\d{3})(\d{0,3})/,
                            "$1.$2"
                        );
                }


                documento.value =
                    valor;

                documento.maxLength =
                    14;

                documento.placeholder =
                    "000.000.000-00";

                ajudaDocumento.textContent =
                    "Informe um CPF válido. O CPF deve ser único no sistema.";

            } else if (tipo === "cnpj") {

                valor =
                    valor.substring(
                        0,
                        14
                    );


                if (valor.length > 12) {

                    valor =
                        valor.replace(
                            /(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/,
                            "$1.$2.$3/$4-$5"
                        );

                } else if (valor.length > 8) {

                    valor =
                        valor.replace(
                            /(\d{2})(\d{3})(\d{3})(\d{0,4})/,
                            "$1.$2.$3/$4"
                        );

                } else if (valor.length > 5) {

                    valor =
                        valor.replace(
                            /(\d{2})(\d{3})(\d{0,3})/,
                            "$1.$2.$3"
                        );

                } else if (valor.length > 2) {

                    valor =
                        valor.replace(
                            /(\d{2})(\d{0,3})/,
                            "$1.$2"
                        );
                }


                documento.value =
                    valor;

                documento.maxLength =
                    18;

                documento.placeholder =
                    "00.000.000/0000-00";

                ajudaDocumento.textContent =
                    "Informe um CNPJ válido. O CNPJ deve ser único no sistema.";

            } else {

                documento.value =
                    somenteNumerosJS(
                        documento.value
                    );

                documento.maxLength =
                    18;

                documento.placeholder =
                    "Selecione CPF ou CNPJ";

                ajudaDocumento.textContent =
                    "Selecione CPF ou CNPJ.";

            }

        }


        documentoTipo.forEach(
            function (radio) {

                radio.addEventListener(
                    "change",
                    function () {

                        documento.value =
                            "";

                        formatarDocumento();

                        documento.focus();

                    }
                );

            }
        );


        documento.addEventListener(
            "input",
            formatarDocumento
        );


        formatarDocumento();


        /*
        |--------------------------------------------------------------------------
        | TELEFONE
        |--------------------------------------------------------------------------
        */

        const telefone =
            document.getElementById(
                "telefone"
            );


        function formatarTelefoneJS() {

            let valor =
                somenteNumerosJS(
                    telefone.value
                );


            valor =
                valor.substring(
                    0,
                    11
                );


            if (valor.length === 11) {

                telefone.value =
                    valor.replace(
                        /(\d{2})(\d{5})(\d{4})/,
                        "($1) $2-$3"
                    );

            } else if (valor.length === 10) {

                telefone.value =
                    valor.replace(
                        /(\d{2})(\d{4})(\d{4})/,
                        "($1) $2-$3"
                    );

            } else {

                telefone.value =
                    valor;
            }

        }


        telefone.addEventListener(
            "input",
            formatarTelefoneJS
        );


        /*
        |--------------------------------------------------------------------------
        | SENHA
        |--------------------------------------------------------------------------
        */

        const senha =
            document.getElementById(
                "senha"
            );

        const confirmarSenha =
            document.getElementById(
                "confirmar_senha"
            );

        const reqTamanho =
            document.getElementById(
                "req-tamanho"
            );

        const reqMaiuscula =
            document.getElementById(
                "req-maiuscula"
            );

        const reqMinuscula =
            document.getElementById(
                "req-minuscula"
            );

        const reqNumero =
            document.getElementById(
                "req-numero"
            );

        const reqSimbolo =
            document.getElementById(
                "req-simbolo"
            );

        const mensagemConfirmacao =
            document.getElementById(
                "mensagem-confirmacao"
            );


        function atualizarRequisitos() {

            const valor =
                senha.value;


            atualizarRequisito(
                reqTamanho,
                valor.length >= 8
            );

            atualizarRequisito(
                reqMaiuscula,
                /[A-Z]/.test(valor)
            );

            atualizarRequisito(
                reqMinuscula,
                /[a-z]/.test(valor)
            );

            atualizarRequisito(
                reqNumero,
                /[0-9]/.test(valor)
            );

            atualizarRequisito(
                reqSimbolo,
                /[^A-Za-z0-9]/.test(valor)
            );

            atualizarConfirmacao();

        }


        function atualizarRequisito(
            elemento,
            valido
        ) {

            const icone =
                elemento.querySelector(
                    ".icone-requisito"
                );


            if (valido) {

                elemento.classList.add(
                    "ok"
                );

                icone.textContent =
                    "✓";

            } else {

                elemento.classList.remove(
                    "ok"
                );

                icone.textContent =
                    "○";
            }

        }


        function atualizarConfirmacao() {

            if (
                confirmarSenha.value === ""
            ) {

                mensagemConfirmacao.textContent =
                    "Digite novamente a mesma senha.";

                mensagemConfirmacao.style.color =
                    "#777";

                return;
            }


            if (
                senha.value ===
                confirmarSenha.value
            ) {

                mensagemConfirmacao.textContent =
                    "✓ As senhas coincidem.";

                mensagemConfirmacao.style.color =
                    "#15803d";

            } else {

                mensagemConfirmacao.textContent =
                    "As senhas não coincidem.";

                mensagemConfirmacao.style.color =
                    "#b91c1c";
            }

        }


        senha.addEventListener(
            "input",
            atualizarRequisitos
        );

        confirmarSenha.addEventListener(
            "input",
            atualizarConfirmacao
        );

    }
);

</script>


</body>

</html>