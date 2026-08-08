<?php
/**
 * includes/rentabilidad.php
 *
 * Los números de decisión de un lote: margen, costo por hectárea y rinde de
 * indiferencia.
 *
 * Vive acá porque lo usan el reporte PDF, el reporte Excel y los tests. Son las
 * cuentas de las que depende la promesa del producto — el productor decide una
 * siembra o una venta con esto — y son justamente el tipo de error que no se ve:
 * un 500 salta a la vista, un margen mal calculado devuelve un número con toda
 * confianza y nadie lo nota. Una sola implementación, y con pruebas.
 */

/**
 * Calcula los indicadores de un lote a partir de sus totales.
 *
 * @param array $lote Con las claves: sup (hectáreas), ingreso, costo_dir, alquiler, kgs.
 * @return array{
 *     sup:float, ingreso:float, costo_directo:float, alquiler:float, kgs:float,
 *     costo_total:float, margen_total:float,
 *     ingreso_ha:float, costo_ha:float, alquiler_ha:float, margen_ha:float,
 *     precio_promedio_kg:float, rinde_indiferencia_ha:float
 * }
 */
function rentabilidad_lote(array $lote): array
{
    $sup      = max(0.0, (float)($lote['sup']      ?? 0));
    $ingreso  =          (float)($lote['ingreso']  ?? 0);
    $costoDir =          (float)($lote['costo_dir'] ?? 0);
    $alquiler =          (float)($lote['alquiler'] ?? 0);
    $kgs      = max(0.0, (float)($lote['kgs']      ?? 0));

    $costoTotal  = $costoDir + $alquiler;
    $margenTotal = $ingreso - $costoTotal;

    // Sin superficie cargada no se puede dividir por hectárea. Se devuelve 0 en
    // vez de forzar un 1: un valor inventado se vería como un dato real.
    $porHa = fn(float $v): float => $sup > 0 ? $v / $sup : 0.0;

    // Precio promedio efectivamente obtenido, no el de pizarra: sale de lo que
    // realmente se cobró dividido por lo que realmente se entregó.
    $precioPromedio = $kgs > 0 ? $ingreso / $kgs : 0.0;

    // Rinde de indiferencia: cuántos kg por hectárea hay que sacar para no
    // perder plata, al precio al que se viene vendiendo. Si no hubo ventas no
    // hay precio de referencia y la cuenta no tiene sentido.
    $rindeIndiferencia = ($precioPromedio > 0 && $sup > 0)
        ? ($costoTotal / $precioPromedio) / $sup
        : 0.0;

    return [
        'sup'                   => $sup,
        'ingreso'               => $ingreso,
        'costo_directo'         => $costoDir,
        'alquiler'              => $alquiler,
        'kgs'                   => $kgs,
        'costo_total'           => $costoTotal,
        'margen_total'          => $margenTotal,
        'ingreso_ha'            => $porHa($ingreso),
        'costo_ha'              => $porHa($costoDir),
        'alquiler_ha'           => $porHa($alquiler),
        'margen_ha'             => $porHa($margenTotal),
        'precio_promedio_kg'    => $precioPromedio,
        'rinde_indiferencia_ha' => $rindeIndiferencia,
    ];
}
