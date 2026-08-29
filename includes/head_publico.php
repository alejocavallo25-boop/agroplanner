<?php
/**
 * Fuentes y hoja de estilos de las pantallas de fuera de la sesión.
 *
 * Existe porque este bloque estaba copiado a mano en seis archivos —login,
 * register, forgot_password, reset_password, terminos, privacidad— y las seis
 * copias se habían quedado atrás: ninguna cargaba las fuentes del sistema, y
 * ninguna le ponía el ?v= a la hoja de estilos.
 *
 * Lo segundo era lo grave. Sin el parámetro, el navegador seguía sirviendo la
 * copia vieja de style.css que tenía guardada, así que el login se veía con el
 * diseño oscuro anterior mientras el resto de la aplicación ya estaba en el
 * claro. No eran dos diseños: era el mismo archivo, en dos versiones.
 *
 * filemtime cambia sola cada vez que se toca el CSS, así que la versión se
 * renueva con el deploy y no hay que acordarse de nada.
 */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
