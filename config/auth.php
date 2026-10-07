<?php

/*
|--------------------------------------------------------------------------
| SESSÃO
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| EXIGIR LOGIN
|--------------------------------------------------------------------------
|
| Verifica se existe um usuário autenticado.
|
*/

function exigirLogin()
{
    if (
        !isset($_SESSION["usuario_id"]) ||
        (!isset($_SESSION["usuario_tipo"]) && !isset($_SESSION["tipo"]))
    ) {

        header(
            "Location: /sistema_bolos/login.php"
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| EXIGIR TIPO DE USUÁRIO
|--------------------------------------------------------------------------
|
| Exemplos:
|
| exigirTipo("admin");
| exigirTipo("confeiteira");
| exigirTipo("cliente");
|
*/

function exigirTipo($tipo)
{
    exigirLogin();

    // O login principal mantém "tipo" como fonte atual; usuario_tipo pode
    // existir em sessões antigas com um valor desatualizado.
    $tipo_sessao = $_SESSION["tipo"] ?? $_SESSION["usuario_tipo"] ?? null;

    if (
        $tipo_sessao !== $tipo
    ) {

        header(
            "Location: /sistema_bolos/index.php"
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| EXIGIR ADMINISTRADOR
|--------------------------------------------------------------------------
*/

function exigirAdmin()
{
    exigirTipo("admin");
}


/*
|--------------------------------------------------------------------------
| EXIGIR CONFEITEIRA
|--------------------------------------------------------------------------
*/

function exigirConfeiteira()
{
    exigirTipo("confeiteira");
}


/*
|--------------------------------------------------------------------------
| EXIGIR CLIENTE
|--------------------------------------------------------------------------
*/

function exigirCliente()
{
    exigirTipo("cliente");
}
