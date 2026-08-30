<?php
/**
 * El ícono de la pestaña, en un solo lugar.
 *
 * Estaba copiado en cinco archivos, con el verde esmeralda #10b981 de la paleta
 * anterior. Ese color ya no existe en el sistema: el acento es
 * oklch(0.420 0.100 150), que en hexadecimal es #195c2e.
 *
 * Pero cambiar sólo el color lo habría roto. Un favicon se ve sobre la barra del
 * navegador, que puede ser clara u oscura, y el verde del sistema es oscuro:
 * medido contra una barra oscura (#292a2d) da 1,8:1 y desaparece, mientras que
 * el esmeralda daba 5,5:1. Por eso el ícono ahora trae su propio fondo —baldosa
 * verde con la hoja en blanco, 8:1 entre las dos— y así se ve igual sobre
 * cualquier barra, que es lo que hace cualquier aplicación con ícono propio.
 */
?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='-30 -30 572 572'><rect x='-30' y='-30' width='572' height='572' rx='110' fill='%23195c2e'/><path fill='%23ffffff' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></svg>">
