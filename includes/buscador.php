<?php
// Caja de búsqueda universal. Requiere $q (término actual).
// Opcional: $buscador_placeholder para personalizar el texto.
$ph = isset($buscador_placeholder) ? $buscador_placeholder : 'Buscar por todo...';
$qv = isset($q) ? $q : '';
// Si la página lo pide, la búsqueda se dispara solo al presionar Enter (no mientras se escribe).
$enter_only = !empty($buscador_enter_only);
?>
<div class="ap-search-box<?= $qv !== '' ? ' has-value' : '' ?>">
    <i class="fas fa-search"></i>
    <?php /* El placeholder no cuenta como etiqueta: desaparece al escribir y varios
             lectores de pantalla no lo anuncian. Como acá no hay label visible
             —sólo la lupa— el nombre accesible va en aria-label. */ ?>
    <input type="text" placeholder="<?= htmlspecialchars($ph) ?>"
           aria-label="<?= htmlspecialchars($ph) ?>"
           value="<?= htmlspecialchars($qv) ?>"
           oninput="apBuscar(this)" onkeydown="apBuscarEnter(this, event)" autocomplete="off"<?= $enter_only ? ' data-search-on-enter="1"' : '' ?>>
    <button type="button" class="ap-search-clear" title="Limpiar" onclick="apBuscarLimpiar(this)">
        <i class="fas fa-times"></i>
    </button>
</div>
