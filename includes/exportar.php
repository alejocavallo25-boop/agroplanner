<?php
/**
 * includes/exportar.php
 *
 * Un único botón "Exportar" que despliega los formatos disponibles, en lugar de
 * una fila de botones (Excel, PDF, …) compitiendo por la misma barra.
 *
 * Uso:
 *
 *     require_once 'includes/exportar.php';
 *
 *     boton_exportar([
 *         ['etiqueta' => 'Excel', 'href' => 'api/reporte_excel.php?tipo=x', 'icono' => 'fa-file-excel',
 *          'color' => '#10b981', 'detalle' => 'Planilla editable'],
 *         ['etiqueta' => 'PDF',   'href' => 'api/reporte_pdf.php?tipo=x',   'icono' => 'fa-file-pdf',
 *          'color' => '#ff7b72', 'detalle' => 'Listo para imprimir', 'nueva_pestana' => true],
 *     ]);
 *
 * El comportamiento (abrir, cerrar, ubicar) vive en un único listener delegado
 * en includes/footer.php, así da igual cuántos de estos haya en la página.
 *
 * @param array  $opciones Cada una: etiqueta, href y opcionalmente icono, color,
 *                         detalle, nueva_pestana.
 * @param string $etiqueta Texto del botón que abre el menú.
 * @param string $clases   Clases extra para el disparador. 'exp-btn-sm' lo achica
 *                         para que entre dentro de una fila de tabla.
 */
function boton_exportar(array $opciones, string $etiqueta = 'Exportar', string $clases = ''): void
{
    $opciones = array_values(array_filter($opciones, fn($o) => !empty($o['href']) && !empty($o['etiqueta'])));
    if (!$opciones) {
        return;
    }

    $clases = trim($clases);

    // Con un solo formato el menú sobra: se muestra el enlace directo y listo.
    if (count($opciones) === 1) {
        $o = $opciones[0];
        printf(
            '<a href="%s" class="btn exp-btn %s"%s><i class="fas %s" aria-hidden="true" style="color:%s"></i> %s</a>',
            htmlspecialchars($o['href']),
            htmlspecialchars($clases),
            !empty($o['nueva_pestana']) ? ' target="_blank" rel="noopener"' : '',
            htmlspecialchars($o['icono'] ?? 'fa-download'),
            htmlspecialchars($o['color'] ?? 'currentColor'),
            htmlspecialchars($o['etiqueta'])
        );
        return;
    }
    ?>
    <div class="exp-wrap">
        <button type="button" class="btn exp-btn <?= htmlspecialchars($clases) ?>" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-download" aria-hidden="true"></i>
            <span><?= htmlspecialchars($etiqueta) ?></span>
            <i class="fas fa-chevron-down exp-caret" aria-hidden="true"></i>
        </button>
        <div class="exp-menu" role="menu" hidden>
            <?php foreach ($opciones as $o): ?>
            <a class="exp-item" role="menuitem"
               href="<?= htmlspecialchars($o['href']) ?>"
               <?= !empty($o['nueva_pestana']) ? 'target="_blank" rel="noopener"' : '' ?>>
                <i class="fas <?= htmlspecialchars($o['icono'] ?? 'fa-file') ?>" aria-hidden="true"
                   style="color: <?= htmlspecialchars($o['color'] ?? 'var(--text-muted)') ?>;"></i>
                <span class="exp-item-txt">
                    <strong><?= htmlspecialchars($o['etiqueta']) ?></strong>
                    <?php if (!empty($o['detalle'])): ?>
                    <small><?= htmlspecialchars($o['detalle']) ?></small>
                    <?php endif; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
