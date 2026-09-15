<?php
/**
 * includes/tambo_estructura.php
 *
 * Categorías y subcategorías de los egresos del tambo.
 *
 * Vivía adentro de tambo_egresos.php. Sale a un archivo propio porque ahora la
 * lee también el chat, para reconocer "¿cuánto gasté en concentrados?": si el
 * formulario y el chat tuvieran cada uno su lista, una categoría nueva cargable
 * sería una pregunta que el chat no entiende.
 *
 * 'items' = la subcategoría tiene conceptos propios del usuario
 *           (tambo_egresos_conceptos); 'libre' = se escribe a mano.
 */
function tambo_estructura(): array
{
    return [
        'Alimentación' => [
            'Concentrados' => 'items',
            'Forrajes'     => 'items',
            'Minerales'    => 'items',
            'Balanceados'  => 'items',
            'Cereal / Grano' => 'items',
            'Otros'        => 'libre',
        ],
        'Veterinaria' => [
            'Sanidad'          => 'items',
            'Reproducción'     => 'items',
            'Higiene'          => 'items',
            'Rutina de ordeñe' => 'items',
            'Otros'            => 'libre',
        ],
        'Sueldos' => [
            'Ordeñe'                       => 'items',
            'Guachera'                     => 'items',
            'Preparto'                     => 'items',
            'Reproducción'                 => 'items',
            'Alimentación'                 => 'items',
            'Mantenimiento'                => 'items',
            'Administración'               => 'items',
            'Retiros de director'          => 'items',
            'Encargado'                    => 'items',
            'Aportes, seguros y aguinaldo' => 'items',
            'Otros'                        => 'libre',
        ],
        'Mantenimiento' => [
            'Maquinaria'   => 'items',
            'Equipamiento' => 'items',
            'Otros'        => 'libre',
        ],
        'Honorarios' => [
            'Veterinarios'        => 'items',
            'Contables'           => 'items',
            'Jurídicos'           => 'items',
            'Recursos Humanos'    => 'items',
            'Marketing'           => 'items',
            'Seguridad e higiene' => 'items',
            'Agrónomo'            => 'items',
            'Asesoramiento'       => 'items',
            'Otros'               => 'libre',
        ],
        'Lubricantes y combustibles' => [
            'Lubricantes'           => 'items',
            'Combustible agro'      => 'items',
            'Combustible vehículos' => 'items',
            'Otros'                 => 'libre',
        ],
        'Alquileres' => [
            'Vacas'       => 'items',
            'Campo / Lote' => 'items',
            'Desperdicio' => 'items',
            'Otros'       => 'libre',
        ],
        'Luz' => [
            'Unidad de Explotación' => 'items',
            'Otros' => 'libre',
        ],
        'Otros' => [
            'Gastos Varios' => 'items',
            'Otros' => 'libre',
        ],
    ];
}
